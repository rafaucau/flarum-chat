<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\Post\Event\Posted;
use Ramon\Chat\Channel;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Service\MembershipManager;
use Illuminate\Contracts\Events\Dispatcher as Events;

/**
 * Inscreve o usuário no canal vinculado à categoria em que ele respondeu, se o
 * canal tiver `auto_join_on_reply`. Diferente de `auto_join`, adiciona uma
 * pessoa por vez, conforme o interesse demonstrado.
 */
class JoinChannelsOnReply
{
    public function __construct(
        protected MembershipManager $memberships,
        protected Events $events
    ) {
    }

    public function handle(Posted $event): void
    {
        $post = $event->post;
        $actor = $event->actor;

        // Posted::$actor pode ser null: job em segundo plano ou importação.
        if ($actor === null || ! $actor->exists) {
            return;
        }

        // Quem não tem acesso ao chat não é inscrito em silêncio.
        if (! $actor->can('useChat')) {
            return;
        }

        $discussion = $post->discussion;

        if ($discussion === null) {
            return;
        }

        $tagIds = $this->tagIdsFor($discussion);

        if ($tagIds === []) {
            return;
        }

        $channels = Channel::query()
            ->where('auto_join_on_reply', true)
            ->where('type', Channel::TYPE_CATEGORY)
            ->where('status', Channel::STATUS_OPEN)
            ->whereNull('deleted_at')
            ->whereIn('tag_id', $tagIds)
            ->get();

        foreach ($channels as $channel) {
            // Respeita a saída deliberada: quem saiu não volta a cada post na categoria.
            $everJoined = ChannelUser::query()
                ->where('channel_id', $channel->id)
                ->where('user_id', $actor->id)
                ->exists();

            if ($everJoined) {
                continue;
            }

            // O canal herda as permissões da tag, e a discussão pode ser visível a quem
            // o canal não é; checa antes de entrar.
            if (! Channel::whereVisibleTo($actor)->whereKey($channel->id)->exists()) {
                continue;
            }

            $this->memberships->join($channel, $actor);
            $channel->refreshMetadata()->save();

            // `automatic`: a pessoa respondeu, não pediu para entrar. Anunciar viraria
            // uma linha de sistema a cada primeira resposta.
            $this->events->dispatch(new UserJoinedChannel($channel, $actor, $actor, automatic: true));
        }
    }

    /**
     * Ids das tags da discussão, com as mães, para que um canal vinculado à mãe
     * também pegue respostas nas filhas.
     *
     * @return int[]
     */
    protected function tagIdsFor(object $discussion): array
    {
        // Não `method_exists($discussion, 'tags')`: o flarum/tags registra a relação
        // dinamicamente via Extend\Model, e method_exists() retorna false.
        try {
            $tags = $discussion->tags;
        } catch (\Throwable $e) {
            // flarum/tags ausente ou relação indisponível.
            return [];
        }

        if ($tags === null) {
            return [];
        }

        $ids = [];

        foreach ($tags as $tag) {
            $ids[] = (int) $tag->id;

            if (! empty($tag->parent_id)) {
                $ids[] = (int) $tag->parent_id;
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }
}
