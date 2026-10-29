<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Carbon\Carbon;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\MessageWasSent;
use Ramon\Chat\Event\ThreadWasCreated;
use Ramon\Chat\Mention\MentionResolver;
use Ramon\Chat\Message;
use Ramon\Chat\Thread;
use Ramon\Chat\ThreadUser;
use Ramon\Chat\Upload;

/**
 * O único caminho por onde mensagens de usuários entram num canal.
 *
 * Validação, rate limit, menções, threads, anexos, contadores e não lidas
 * precisam acontecer juntos ou não acontecer.
 */
class MessageDispatcher
{
    /** Anexos por mensagem: o resto ficaria preso a ela para sempre. */
    public const MAX_UPLOADS_PER_MESSAGE = 10;

    public function __construct(
        protected ExtensionManager $extensions,
        protected Events $events,
        protected SettingsRepositoryInterface $settings,
        protected Translator $translator,
        protected MentionResolver $mentions,
        protected RateLimiter $rateLimiter,
        protected SlowMode $slowMode,
        protected UnreadTracker $unread,
        protected UploadPrivacy $uploadPrivacy
    ) {
    }

    /**
     * @param  int[]  $uploadIds  ids de arquivos já enviados e ainda sem mensagem
     *
     * @throws ValidationException
     */
    public function send(
        Channel $channel,
        User $actor,
        string $content,
        ?Thread $thread = null,
        ?Message $replyTo = null,
        array $uploadIds = [],
        bool $createThread = false
    ): Message {
        $content = trim($content);
        $uploadIds = array_values(array_unique(array_filter(array_map('intval', $uploadIds))));

        if (count($uploadIds) > self::MAX_UPLOADS_PER_MESSAGE) {
            throw new ValidationException([
                'uploadIds' => $this->translator->trans('ramon-chat.api.uploads_too_many', ['max' => self::MAX_UPLOADS_PER_MESSAGE]),
            ]);
        }

        // Só anexos que vão mesmo para a mensagem (do autor, ainda soltos) contam
        // para "não está vazia"; ids inventados não passam.
        $uploadIds = $uploadIds === [] ? [] : Upload::query()
            ->whereIn('id', $uploadIds)
            ->where('user_id', $actor->id)
            ->whereNull('message_id')
            ->when(! UploadPrivacy::requiredFor($channel), fn ($query) => $query->where('is_private', false))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->assertValidContent($channel, $content, $uploadIds);
        $this->assertMaySendStickers($actor, $content);
        $this->rateLimiter->assertWithinLimit($actor);

        // Depois das checagens de conteúdo, antes de gravar: mensagem inválida não
        // custa a vez.
        $this->slowMode->assertMayPost($channel, $actor);

        // A thread precisa partir de mensagem deste canal, senão quebraria a ordem
        // (channel, number).
        if ($replyTo !== null && $replyTo->channel_id !== $channel->id) {
            throw new ValidationException([
                'replyTo' => $this->translator->trans('ramon-chat.api.invalid_reply_target'),
            ]);
        }

        if ($thread !== null && $thread->channel_id !== $channel->id) {
            throw new ValidationException([
                'thread' => $this->translator->trans('ramon-chat.api.invalid_thread_target'),
            ]);
        }

        $threadWasCreated = false;

        // Depois de todas as validações, para uma recusa não custar a vez.
        $this->slowMode->claim($channel, $actor);

        $message = $this->uploadPrivacy->deferringRemoteDeletes(function () use (
            $channel, $actor, $content, &$thread, $replyTo, $uploadIds, $createThread, &$threadWasCreated
        ) { return Message::query()->getConnection()->transaction(function () use (
            $channel, $actor, $content, &$thread, $replyTo, $uploadIds, $createThread, &$threadWasCreated
        ) {
            // Primeira resposta: cria a thread e preenche o thread_id da raiz, para a
            // thread sair numa consulta só.
            if ($createThread && $thread === null && $replyTo !== null) {
                if ($replyTo->thread_id !== null) {
                    $thread = $replyTo->thread;
                } else {
                    $thread = Thread::build($channel, $replyTo, $actor);
                    $thread->save();

                    $replyTo->thread_id = $thread->id;
                    $replyTo->save();

                    $this->trackThread($thread, $replyTo->user_id !== null ? $replyTo->user : $actor);

                    $threadWasCreated = true;
                }
            }

            $message = Message::build($channel, $actor, $content, $thread, $replyTo);
            $message->save();

            $this->attachUploads($channel, $message, $actor, $uploadIds);

            $this->mentions->sync(
                $message,
                $actor->can('mentionChannelWide', $channel)
            );

            // Relações que listeners e serializers usam.
            $message->setRelation('channel', $channel);
            $message->setRelation('user', $actor);

            if ($thread !== null) {
                $message->setRelation('thread', $thread);

                $thread->noteReply($message)->save();
                $this->trackThread($thread, $actor);
            }

            $channel->recordNewMessage($message);

            // Quem envia fica em dia; os demais ganham uma não lida.
            $this->unread->recordNewMessage($message);

            return $message;
        }); });

        // Eventos só depois do commit: o broadcast e o e-mail não têm rollback, e o
        // cliente não pode receber uma mensagem que ainda não existe.
        if ($threadWasCreated && $thread !== null) {
            $this->events->dispatch(new ThreadWasCreated($thread, $actor));
        }

        // Só depois de a mensagem entrar, para um envio que falhou não custar a vez.
        $this->slowMode->noteSent($channel, $actor);

        $this->events->dispatch(new MessageWasSent($message, $actor));

        return $message;
    }

    /**
     * Publica uma mensagem de sistema (entradas, mudanças de status). Pula rate
     * limit e menções, mas avança os contadores para ordenar no stream.
     */
    public function sendSystem(Channel $channel, string $key, array $data = []): Message
    {
        $message = Message::query()->getConnection()->transaction(function () use ($channel, $key, $data) {
            $message = Message::buildSystem($channel, $key, $data);
            $message->save();

            $message->setRelation('channel', $channel);

            $channel->recordNewMessage($message);

            return $message;
        });

        // Após o commit, como acima.
        $this->events->dispatch(new MessageWasSent($message, null));

        return $message;
    }

    /**
     * Vincula uploads pendentes à mensagem. Só os do próprio autor e ainda soltos,
     * para ninguém pegar o arquivo de outro pelo id.
     *
     * Também decide o disco: o endpoint de upload confia no destino que o cliente
     * informa, e aqui o canal é certo. Arquivo público em canal privado é movido
     * na mesma transação, então falhar a movimentação é falhar o envio.
     *
     * @param  int[]  $uploadIds
     */
    protected function attachUploads(Channel $channel, Message $message, User $actor, array $uploadIds): void
    {
        $uploadIds = array_values(array_filter(array_map('intval', $uploadIds)));

        if ($uploadIds === []) {
            return;
        }

        $private = UploadPrivacy::requiredFor($channel);

        Upload::query()
            ->whereIn('id', $uploadIds)
            ->where('user_id', $actor->id)
            ->whereNull('message_id')
            // Arquivo enviado para canal privado ou DM não vai para canal público: o
            // acesso ao anexo segue o da mensagem.
            ->when(! $private, fn ($query) => $query->where('is_private', false))
            ->update([
                'message_id' => $message->id,
                'updated_at' => Carbon::now(),
            ]);

        if (! $private) {
            return;
        }

        $public = Upload::query()
            ->whereKey($uploadIds)
            ->where('message_id', $message->id)
            ->where('is_private', false)
            ->get();

        foreach ($public as $upload) {
            $this->uploadPrivacy->privatize($upload);
        }
    }

    /**
     * Responder numa thread inscreve em "always", como no Discourse. Membership
     * existente fica intacta, para não reelevar quem baixou o nível.
     */
    protected function trackThread(Thread $thread, ?User $user): void
    {
        if ($user === null || ! $user->exists) {
            return;
        }

        // Um statement só: o índice único (thread_id, user_id) garante "não mexer na
        // existente" melhor que ler e gravar, que corre contra uma segunda resposta.
        $now = Carbon::now();

        ThreadUser::query()->insertOrIgnore([
            'thread_id'          => $thread->id,
            'user_id'            => $user->id,
            'notification_level' => ThreadUser::LEVEL_ALWAYS,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
    }

    /**
     * Recusa shortcode de sticker de quem não pode enviar sticker. Esconder o
     * botão não basta: o shortcode é texto e vai direto pela API. Só conta
     * shortcode de sticker real, e sem o ramon/stickers nada é checado.
     *
     * @throws ValidationException
     */
    /**
     * As checagens de conteúdo do envio, para a edição: limites de tamanho,
     * permissão de sticker e rate limit. Sem elas o PATCH contornaria as três.
     * @throws ValidationException
     */
    public function assertMayRevise(Channel $channel, User $actor, string $content): void
    {
        $content = trim($content);

        $this->assertValidContent($channel, $content, []);
        $this->assertMaySendStickers($actor, $content);
        $this->rateLimiter->assertWithinLimit($actor);
    }

    protected function assertMaySendStickers(User $actor, string $content): void
    {
        if ($content === '' || $actor->hasPermission('ramon-chat.sendStickers')) {
            return;
        }

        // Pergunta ao gerenciador de extensões, não à existência da tabela, que pode
        // sobrar de uma desinstalação. A tabela é lida direto para não depender do
        // pacote de stickers, que é opcional.
        if (! $this->extensions->isEnabled('ramon-stickers')) {
            return;
        }

        preg_match_all('/:[\w+-]+:/', $content, $matches);

        if ($matches[0] === []) {
            return;
        }

        $used = Message::query()->getConnection()->table('stickers')
            ->whereIn('text_to_replace', array_unique($matches[0]))
            ->exists();

        if (! $used) {
            return;
        }

        throw new ValidationException([
            'content' => $this->translator->trans('ramon-chat.api.stickers_not_allowed'),
        ]);
    }

    /**
     * @param  int[]  $uploadIds
     *
     * @throws ValidationException
     */
    protected function assertValidContent(Channel $channel, string $content, array $uploadIds): void
    {
        $min = (int) $this->settings->get('ramon-chat.min_message_length', 1);

        // O teto do canal, se houver, senão o do fórum. Lido aqui para todo caminho
        // até `send()` ter o mesmo limite que o composer mostra.
        $max = $channel->maxMessageLength(
            (int) $this->settings->get('ramon-chat.max_message_length', 3000)
        );

        // Mensagem só com anexo é válida; o mínimo vale para o texto só quando não há mais nada.
        if ($content === '' && $uploadIds === []) {
            throw new ValidationException([
                'content' => $this->translator->trans('ramon-chat.api.message_empty'),
            ]);
        }

        $length = mb_strlen($content);

        if ($content !== '' && $length < $min) {
            throw new ValidationException([
                'content' => $this->translator->trans('ramon-chat.api.message_too_short', ['min' => $min]),
            ]);
        }

        if ($length > $max) {
            throw new ValidationException([
                'content' => $this->translator->trans('ramon-chat.api.message_too_long', ['max' => $max]),
            ]);
        }
    }
}
