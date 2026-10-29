<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\Extension\ExtensionManager;
use Flarum\Tags\Tag;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Chat\Channel;

/**
 * Restringe as queries de canais ao que o ator pode ver. Canais de categoria
 * herdam o `viewForum` da tag vinculada, sem um sistema de permissões paralelo;
 * canais diretos só aparecem para os participantes.
 */
class ScopeChannelVisibility
{
    /**
     * Memoizado. Não usa `$actor->can('view', $channel)`: o fallback de admin do
     * Gate diria que o admin vê um canal que o scope exclui.
     */
    public static function visibleTo(User $actor, Channel $channel, VisibilityCache $cache): bool
    {
        return $cache->remember(
            $actor,
            'channel',
            (int) $channel->id,
            fn () => Channel::whereVisibleTo($actor)->whereKey($channel->id)->exists()
        );
    }

    public function __construct(protected ExtensionManager $extensions)
    {
    }

    public function __invoke(User $actor, Builder $query): void
    {
        // Gate global: ler e participar são a mesma permissão.
        if (! $actor->can('useChat')) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereNull('chat_channels.deleted_at');

        // Não `class_exists(Tag::class)`: a classe existe em vendor/ mesmo com a
        // extensão desligada e sem a tabela `tags`, e a subquery daria 500.
        $tagsAvailable = $this->extensions->isEnabled('flarum-tags');

        $query->where(function (Builder $query) use ($actor, $tagsAvailable) {
            // Canais diretos: só participantes que não saíram.
            if ($actor->exists) {
                $query->orWhere(function (Builder $query) use ($actor) {
                    $query
                        ->where('chat_channels.type', 'direct')
                        ->whereExists(function ($sub) use ($actor) {
                            $sub->selectRaw(1)
                                ->from('chat_channel_user')
                                ->whereColumn('chat_channel_user.channel_id', 'chat_channels.id')
                                ->where('chat_channel_user.user_id', $actor->id)
                                ->whereNull('chat_channel_user.left_at');
                        });
                });
            }

            // Canais de categoria sem tag.
            $query->orWhere(function (Builder $query) use ($actor) {
                $query
                    ->where('chat_channels.type', 'category')
                    ->whereNull('chat_channels.tag_id');

                $this->restrictPrivate($query, $actor);
            });

            // Canais vinculados a tag. Sem flarum/tags ficam ocultos, não
            // públicos: com permissão herdada, só falhar fechado é seguro.
            if ($tagsAvailable) {
                $query->orWhere(function (Builder $query) use ($actor) {
                    $query
                        ->where('chat_channels.type', 'category')
                        ->whereNotNull('chat_channels.tag_id')
                        ->whereIn('chat_channels.tag_id', function ($sub) use ($actor) {
                            Tag::query()
                                ->setQuery($sub->from('tags'))
                                ->whereHasPermission($actor, 'viewForum')
                                ->select('tags.id');
                        });

                    // Soma-se à checagem da tag, não a substitui.
                    $this->restrictPrivate($query, $actor);
                });
            }
        });
    }

    /**
     * Canal privado só para membros (ou convidados). `accessPrivateChannels`,
     * `moderate` e `inspectChannels` dispensam a filiação para ver, não para
     * postar (ChannelPolicy::postMessage).
     */
    protected function restrictPrivate(Builder $query, User $actor): void
    {
        if (self::bypassesPrivacy($actor)) {
            return;
        }

        $query->where(function (Builder $query) use ($actor) {
            $query->where('chat_channels.is_private', false);

            if (! $actor->exists) {
                return;
            }

            $query->orWhereExists(function ($sub) use ($actor) {
                $sub->selectRaw(1)
                    ->from('chat_channel_user')
                    ->whereColumn('chat_channel_user.channel_id', 'chat_channels.id')
                    ->where('chat_channel_user.user_id', $actor->id)
                    ->whereNull('chat_channel_user.left_at');
            });

            // Convite pendente abre só a linha: o convidado vê para onde foi
            // chamado e o core lista a notificação. O conteúdo fica atrás de
            // `restrictContents()`.
            $query->orWhereExists(function ($sub) use ($actor) {
                $sub->selectRaw(1)
                    ->from('chat_channel_invites')
                    ->whereColumn('chat_channel_invites.channel_id', 'chat_channels.id')
                    ->where('chat_channel_invites.user_id', $actor->id);
            });
        });
    }

    /**
     * Se o ator lê o que há dentro do canal. Só difere de ver o canal no privado
     * com convite pendente. Forma PHP de `restrictContents()`; as duas andam juntas.
     */
    public static function readsContents(User $actor, Channel $channel): bool
    {
        if (! $channel->isPrivate()) {
            return true;
        }

        if (self::bypassesPrivacy($actor)) {
            return true;
        }

        return $channel->membershipFor($actor) !== null;
    }

    /**
     * Aplicado pelos scopes de mensagem e thread sobre `whereVisibleTo`.
     */
    public static function restrictContents(Builder $query, User $actor): void
    {
        if (self::bypassesPrivacy($actor)) {
            return;
        }

        $query->where(function (Builder $query) use ($actor) {
            $query->where('chat_channels.is_private', false);

            if (! $actor->exists) {
                return;
            }

            $query->orWhereExists(function ($sub) use ($actor) {
                $sub->selectRaw(1)
                    ->from('chat_channel_user')
                    ->whereColumn('chat_channel_user.channel_id', 'chat_channels.id')
                    ->where('chat_channel_user.user_id', $actor->id)
                    ->whereNull('chat_channel_user.left_at');
            });
        });
    }

    /**
     * As três permissões que veem todo canal privado.
     */
    protected static function bypassesPrivacy(User $actor): bool
    {
        return $actor->hasPermission('ramon-chat.accessPrivateChannels')
            || $actor->hasPermission('ramon-chat.moderate')
            || $actor->hasPermission('ramon-chat.inspectChannels');
    }
}
