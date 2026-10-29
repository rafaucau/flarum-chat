<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\User\Exception\PermissionDeniedException;
use Flarum\Locale\Translator;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\MessagesWereMoved;
use Ramon\Chat\Event\MessageWasMoved;
use Ramon\Chat\Message;
use Ramon\Chat\Service\UploadPrivacy;
use Ramon\Chat\Thread;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * Move mensagens para outro canal (ação de moderador). É a única operação que
 * quebra a sequência (channel_id, number): cada mensagem é renumerada no destino
 * e os contadores dos dois canais são recalculados depois.
 */
class MoveMessagesController implements RequestHandlerInterface
{
    protected const MAX_MESSAGES = 100;

    public function __construct(
        protected Events $events,
        protected Translator $translator
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        // A permissão crua, não o Gate: `can()` com nome de permissão cai no
        // fallback do Gate, que a policy genérica de qualquer extensão responde.
        if (! $actor->hasPermission('ramon-chat.moderate')) {
            throw new PermissionDeniedException();
        }

        $body = $request->getParsedBody();
        $attributes = (array) Arr::get($body, 'data.attributes', []);

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) Arr::get($attributes, 'messageIds', []))
        )));

        if ($ids === []) {
            throw new ValidationException([
                'messageIds' => $this->translator->trans('ramon-chat.api.move_empty'),
            ]);
        }

        if (count($ids) > self::MAX_MESSAGES) {
            throw new ValidationException([
                'messageIds' => $this->translator->trans('ramon-chat.api.move_too_many', [
                    'max' => self::MAX_MESSAGES,
                ]),
            ]);
        }

        /** @var Channel|null $target */
        $target = Channel::query()
            ->whereVisibleTo($actor)
            ->find((int) Arr::get($attributes, 'channelId'));

        if ($target === null) {
            throw new ValidationException([
                'channelId' => $this->translator->trans('ramon-chat.api.channel_not_found'),
            ]);
        }

        if (! $target->acceptsMessages()) {
            throw new ValidationException([
                'channelId' => $this->translator->trans('ramon-chat.api.move_target_frozen'),
            ]);
        }

        if (! $actor->can('postMessage', $target)) {
            throw new ForbiddenException();
        }

        $messages = Message::query()
            ->whereVisibleTo($actor)
            ->whereIn('id', $ids)
            ->with('channel')
            ->orderBy('id')
            ->get()
            ->reject(fn (Message $m) => $m->channel_id === $target->id);

        if ($messages->isEmpty()) {
            throw new ValidationException([
                'messageIds' => $this->translator->trans('ramon-chat.api.move_empty'),
            ]);
        }

        // Uma mudança parte de um canal só e nunca amplia o público: nada sai de
        // conversa direta, e o que é privado não vai para canal sem privacidade.
        // Os anexos seguem a mensagem.
        $sources = $messages->pluck('channel')->filter()->unique('id');

        if ($sources->count() > 1) {
            throw new ValidationException([
                'messageIds' => $this->translator->trans('ramon-chat.api.move_one_channel'),
            ]);
        }

        $source = $sources->first();

        if ($source !== null && ($source->isDirect() || (UploadPrivacy::requiredFor($source) && ! UploadPrivacy::requiredFor($target)))) {
            throw new ValidationException([
                'channelId' => $this->translator->trans('ramon-chat.api.move_wider_audience'),
            ]);
        }

        $bySource = [];
        $leftThreads = [];

        // Um envio ao destino no meio do move pode pegar o mesmo número. O índice
        // único barra a duplicata e a transação volta inteira; o moderador recebe
        // um pedido para tentar de novo, não um 500.
        try {
            $moved = Message::query()->getConnection()->transaction(function () use ($messages, $target, $actor, &$bySource, &$leftThreads) {
                $affected = [];
                $moved = 0;

                // Threads das mensagens movidas, capturadas antes do loop soltá-las.
                // Os ponteiros (raiz, última resposta) podem apontar para outro canal,
                // que os leitores da thread talvez não vejam; são corrigidos abaixo.
                $threadIds = $messages->pluck('thread_id')->filter()->unique()->values()->all();
                $movedIds = $messages->pluck('id')->map(fn ($id) => (int) $id)->all();

                // Lê o maior número do destino uma vez e numera em PHP: uma passada,
                // sem N subqueries.
                $next = (int) Message::query()
                    ->where('channel_id', $target->id)
                    ->max('number');

                foreach ($messages as $message) {
                    $source = $message->channel;

                    if ($source !== null) {
                        $affected[$source->id] = $source;
                        $bySource[(int) $source->id][] = (int) $message->id;
                    }

                    if ($message->thread_id !== null) {
                        $leftThreads[(int) $message->id] = (int) $message->thread_id;
                    }

                    $message->channel_id = $target->id;
                    $message->number = ++$next;

                    // A thread pertence ao canal: a resposta movida vira mensagem comum
                    // no destino.
                    $message->thread_id = null;

                    // Idem para a resposta inline cujo alvo ficou para trás.
                    if ($message->reply_to_id !== null
                        && ! $messages->contains(fn (Message $m) => $m->id === $message->reply_to_id)) {
                        $message->reply_to_id = null;
                    }

                    $message->save();
                    $moved++;

                    $this->events->dispatch(new MessageWasMoved($message, $source ?? $target, $target, $actor));
                }

                // A thread fica no canal com as respostas; só a raiz sai. As
                // respostas não foram selecionadas, então não vão junto. O painel
                // continua abrindo pela lista (sem título) e os contadores são
                // recontados do que sobrou.
                if ($threadIds !== []) {
                    foreach (Thread::query()->whereKey($threadIds)->get() as $thread) {
                        if ($thread->original_message_id !== null && in_array((int) $thread->original_message_id, $movedIds, true)) {
                            $thread->original_message_id = null;
                        }

                        $thread->refreshMetadata()->save();
                    }
                }

                $affected[$target->id] = $target;

                foreach ($affected as $channel) {
                    $channel->refreshMetadata()->save();
                }

                return $moved;
            });
        } catch (UniqueConstraintViolationException) {
            throw new ValidationException([
                'channelId' => $this->translator->trans('ramon-chat.api.move_conflict'),
            ]);
        }

        // Depois do commit, para o cliente que refaz o fetch no push já ler as
        // linhas no lugar novo.
        $sources = Channel::query()->whereKey(array_keys($bySource))->get()->keyBy('id')->all();

        $this->events->dispatch(new MessagesWereMoved($bySource, $sources, $target, $leftThreads, $actor));

        return new JsonResponse([
            'data' => [
                'type'       => 'chat-message-moves',
                'attributes' => [
                    'moved'     => $moved,
                    'channelId' => $target->id,
                ],
            ],
        ]);
    }
}
