<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Carbon\Carbon;
use Flarum\Discussion\Discussion;
use Flarum\Extension\ExtensionManager;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Post\CommentPost;
use Flarum\Post\Exception\FloodingException;
use Flarum\Post\Post;
use Flarum\Post\PostCreationThrottler;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\ChannelStatusChanged;
use Ramon\Chat\Event\ChannelWasArchived;
use Ramon\Chat\Message;

/**
 * Arquiva um canal copiando a transcrição para uma discussão. Não é destrutivo:
 * o canal continua legível e as mensagens ficam.
 *
 * Publicar é postar: a transcrição sai em nome de quem arquiva, então exige o
 * que ele poderia postar à mão (`reply`, que respeita o lock; `startDiscussion`
 * na categoria; janela de flood; sem precisar de aprovação no flarum/approval,
 * já que não passa pelos endpoints de post). Também não pode ampliar a
 * audiência: canal privado só um admin publica, e canal de categoria restrita só
 * vai para discussão com essa categoria.
 *
 * Os posts são montados aqui, não pelo PostResource: um canal grande vira vários
 * posts, que o flood control da API recusaria. O trabalho é limitado por
 * MAX_MESSAGES. Admins ficam isentos de tudo isso.
 */
class ChannelArchiver
{
    /**
     * Mensagens por post: canal com dezenas de milhares de mensagens é dividido em respostas.
     */
    protected const MESSAGES_PER_POST = 200;

    /**
     * Máximo de mensagens que um não-admin arquiva. A transcrição é gerada na
     * requisição; canais maiores ficam para um admin.
     */
    public const MAX_MESSAGES = 500;

    public function __construct(
        protected Events $events,
        protected Translator $translator,
        protected TranscriptRenderer $transcript,
        protected ExtensionManager $extensions
    ) {
    }

    /**
     * @param  int|null  $discussionId  discussão a complementar; null cria uma
     *
     * @throws ValidationException
     */
    public function archive(
        Channel $channel,
        User $actor,
        ?int $discussionId = null,
        ?string $title = null
    ): Discussion {
        $existing = $discussionId !== null
            ? Discussion::query()->whereVisibleTo($actor)->find($discussionId)
            : null;

        if ($discussionId !== null && $existing === null) {
            throw new ValidationException([
                'discussionId' => $this->translator->trans('ramon-chat.api.archive_discussion_not_found'),
            ]);
        }

        $title = trim((string) ($title ?? $channel->name ?? ''));

        if ($existing === null && $title === '') {
            throw new ValidationException([
                'title' => $this->translator->trans('ramon-chat.api.archive_title_required'),
            ]);
        }

        if (! $actor->isAdmin()) {
            $this->assertMayPublish($channel, $actor, $existing);
        }

        return Channel::query()->getConnection()->transaction(function () use ($channel, $actor, $existing, $title) {
            $discussion = $existing ?? Discussion::start($title, $actor);

            if ($existing === null) {
                // Canal de tag arquiva na própria categoria, onde a conversa pertencia.
                $discussion->save();

                // Não `method_exists($discussion, 'tags')`: o flarum/tags registra a relação
                // dinamicamente via Extend\Model, e o teste dá false mesmo com tags ativo.
                if ($channel->tag_id !== null && $this->extensions->isEnabled('flarum-tags')) {
                    $discussion->tags()->sync([$channel->tag_id]);
                }

                // Perguntado à discussão já salva e com tags, que é o formato da ability;
                // lançar aqui desfaz a discussão.
                if (! $actor->isAdmin()) {
                    $this->assertWithoutApproval($actor, 'startWithoutApproval', $discussion);
                }
            }

            $chunks = $this->chunks($channel);

            if ($chunks === []) {
                // Grava o marcador mesmo assim: canal vazio arquivado não pode parecer não arquivado.
                $chunks = [$this->translator->trans('ramon-chat.api.archive_empty')];
            }

            // O Flarum 2 removeu CommentPost::reply(). Post::boot() define `type` e o
            // `number`; aqui só conteúdo e autoria.
            $isFirst = $existing === null;

            foreach ($chunks as $body) {
                $post = new CommentPost();
                $post->discussion_id = $discussion->id;
                $post->user_id = $actor->id;
                $post->created_at = Carbon::now();
                $post->setContentAttribute($body, $actor);
                $post->save();

                if ($isFirst) {
                    $discussion->setFirstPost($post);
                    $isFirst = false;
                }

                $discussion->refreshCommentCount();
                $discussion->refreshLastPost();
                $discussion->refreshParticipantCount();
                $discussion->save();
            }

            $channel->status = Channel::STATUS_ARCHIVED;
            $channel->archived_discussion_id = $discussion->id;
            $channel->archived_at = Carbon::now();
            $channel->archived_by_id = $actor->id;
            $channel->save();

            $this->events->dispatch(new ChannelWasArchived($channel, $discussion, $actor));

            return $discussion;
        });
    }

    /**
     * Tira o canal do arquivo, fechado: desfazer o arquivamento não significa
     * reabrir, que é mais um clique deliberado.
     *
     * As marcas de arquivo saem, inclusive o link da discussão. A transcrição fica
     * no fórum como discussão comum; um novo arquivamento ganha link novo.
     */
    public function unarchive(Channel $channel, User $actor): Channel
    {
        $previous = $channel->status;

        $channel->status = Channel::STATUS_CLOSED;
        $channel->archived_discussion_id = null;
        $channel->archived_at = null;
        $channel->archived_by_id = null;
        $channel->save();

        $this->events->dispatch(new ChannelStatusChanged($channel, $previous, $actor));

        return $channel;
    }

    /**
     * As checagens do docblock da classe, para um não-admin.
     *
     * @throws ValidationException
     */
    protected function assertMayPublish(Channel $channel, User $actor, ?Discussion $existing): void
    {
        if ($channel->isPrivate()) {
            throw new ValidationException([
                'channel' => $this->translator->trans('ramon-chat.api.archive_private_channel'),
            ]);
        }

        $tag = $channel->tag_id !== null && $this->extensions->isEnabled('flarum-tags')
            ? \Flarum\Tags\Tag::query()->find($channel->tag_id)
            : null;

        if ($existing !== null) {
            $actor->assertCan('reply', $existing);

            $restricted = $this->restrictedTagIds($tag);

            if ($restricted !== []) {
                // @phpstan-ignore method.notFound (flarum/tags relation)
                $carried = $existing->tags()->pluck('tags.id')->map(fn ($id) => (int) $id)->all();

                if (array_diff($restricted, $carried) !== []) {
                    throw new ValidationException([
                        'discussionId' => $this->translator->trans('ramon-chat.api.archive_discussion_wider'),
                    ]);
                }
            }

            $this->assertWithoutApproval($actor, 'replyWithoutApproval', $existing);
        } elseif ($tag !== null) {
            $actor->assertCan('startDiscussion', $tag);
        } else {
            $actor->assertCan('startDiscussion');
        }

        // Chat fechado a visitantes não vira, arquivado, discussão que visitantes leem.
        $guest = new Guest();

        if (! $guest->hasPermission('ramon-chat.use') && $this->guestReads($guest, $existing, $tag)) {
            throw new ValidationException([
                'channel' => $this->translator->trans('ramon-chat.api.archive_audience_wider'),
            ]);
        }

        if (! $actor->can('postWithoutThrottle')
            && Post::query()
                ->where('user_id', $actor->id)
                ->where('created_at', '>=', Carbon::now()->subSeconds(PostCreationThrottler::$timeout))
                ->exists()) {
            throw new FloodingException();
        }

        $count = Message::query()
            ->where('channel_id', $channel->id)
            ->whereNull('deleted_at')
            ->where('type', Message::TYPE_TEXT)
            ->count();

        if ($count > self::MAX_MESSAGES) {
            throw new ValidationException([
                'channel' => $this->translator->trans('ramon-chat.api.archive_too_large', ['max' => self::MAX_MESSAGES]),
            ]);
        }
    }

    protected function guestReads(Guest $guest, ?Discussion $existing, ?object $tag): bool
    {
        if (! $guest->hasPermission('viewForum')) {
            return false;
        }

        if ($existing !== null) {
            return Discussion::query()->whereVisibleTo($guest)->whereKey($existing->id)->exists();
        }

        if ($tag !== null) {
            return \Flarum\Tags\Tag::query()->whereVisibleTo($guest)->whereKey($tag->id)->exists();
        }

        return true;
    }

    /**
     * O flarum/approval só segura conteúdo feito pelos endpoints de post. O que ele
     * seguraria é recusado aqui, em vez de publicado sem revisão.
     *
     * @throws ValidationException
     */
    protected function assertWithoutApproval(User $actor, string $ability, Discussion $discussion): void
    {
        if (! $this->extensions->isEnabled('flarum-approval')) {
            return;
        }

        if (! $actor->can($ability, $discussion)) {
            throw new ValidationException([
                'discussionId' => $this->translator->trans('ramon-chat.api.archive_needs_approval'),
            ]);
        }
    }

    /**
     * A categoria do canal e a mãe, quando restritas: o que o leitor do canal tem
     * garantido e um visitante não.
     *
     * @return int[]
     */
    protected function restrictedTagIds(?object $tag): array
    {
        if ($tag === null) {
            return [];
        }

        $ids = $tag->is_restricted ? [(int) $tag->id] : [];

        $parent = $tag->parent_id !== null ? \Flarum\Tags\Tag::query()->find($tag->parent_id) : null;

        if ($parent !== null && $parent->is_restricted) {
            $ids[] = (int) $parent->id;
        }

        return $ids;
    }

    /**
     * @return string[] corpos da transcrição, em ordem cronológica
     */
    protected function chunks(Channel $channel): array
    {
        $bodies = [];

        Message::query()
            ->where('channel_id', $channel->id)
            ->whereNull('deleted_at')
            ->where('type', Message::TYPE_TEXT)
            ->with(['user', 'uploads', 'webhook'])
            ->orderBy('id')
            ->chunk(self::MESSAGES_PER_POST, function ($messages) use (&$bodies, $channel) {
                $rendered = $this->transcript->render($messages, $channel);

                if ($rendered !== '') {
                    $bodies[] = $rendered;
                }
            });

        return $bodies;
    }
}
