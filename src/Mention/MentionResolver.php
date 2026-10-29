<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Mention;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Ramon\Chat\Message;
use Ramon\Chat\MessageMention;

/**
 * Extrai as menções de uma mensagem e grava como linhas.
 *
 * Resolver uma vez no envio mantém baratas as notificações e os contadores de menção.
 */
class MentionResolver
{
    /**
     * Casa @username, @"nome exibido", @grupo, @here e @all.
     *
     * O flarum/mentions reescreve menções em `@"nome"#uid` no parse; lê o XML
     * quando existe e cai no texto simples (ex.: mensagens de webhook).
     */
    private const PLAIN_PATTERN = '/(?<![\w\/])@("(?<quoted>[^"]{1,80})"|(?<plain>[a-z0-9_.\-]{1,80}))/i';

    /**
     * Forma parseada do flarum/mentions; traz o id resolvido, então prevalece.
     */
    /**
     * Máximo de pessoas mencionadas que ainda notificam (como o
     * `max_mentions_per_chat_message` do Discourse); acima disso nenhuma notifica.
     */
    public const MAX_USER_MENTIONS = 10;

    /** Nomes soltos (`@nome` sem a marcação do mentions) lidos de uma mensagem. */
    private const MAX_PLAIN_NAMES = 30;

    private const TAG_PATTERN = '/<(?<kind>USERMENTION|GROUPMENTION)[^>]*\b(?<attr>id|username|groupname)="(?<value>[^"]+)"/i';

    /**
     * @return Collection<int, MessageMention> linhas ainda não salvas
     */
    public function resolve(Message $message, bool $allowChannelWide): Collection
    {
        // Lê a forma armazenada direto; o accessor faria o unparse.
        $raw = $message->getAttributes()['content'] ?? '';

        if ($raw === '') {
            return collect();
        }

        $mentions = collect();
        // Só quando um grupo é citado: lê o autor, e a maioria não cita grupo.
        $mayMentionGroupsMemo = null;
        $mayMentionGroups = function () use ($message, &$mayMentionGroupsMemo): bool {
            return $mayMentionGroupsMemo ??= $this->mayMentionGroups($message);
        };
        $seenUsers = [];
        $seenGroups = [];
        $seenChannelWide = [];

        foreach ($this->parsedMentions($raw) as [$type, $id]) {
            if ($type === MessageMention::TYPE_USER && ! isset($seenUsers[$id])) {
                $seenUsers[$id] = true;
                $mentions->push($this->make($message, MessageMention::TYPE_USER, userId: $id));
            }

            if ($type === MessageMention::TYPE_GROUP && ! isset($seenGroups[$id])) {
                $seenGroups[$id] = true;

                // O XML registra o grupo, não se o autor podia citá-lo: o flarum/mentions
                // só checa quando parseia com um ator, e nem todo caminho tem um.
                if (! $mayMentionGroups() || $this->mentionableGroup($id) === null) {
                    continue;
                }

                $mentions->push($this->make($message, MessageMention::TYPE_GROUP, groupId: $id));
            }
        }

        // @here / @all nunca são codificados pelo flarum/mentions.
        if ($allowChannelWide) {
            foreach ($this->channelWideMentions($raw) as $type) {
                if (isset($seenChannelWide[$type])) {
                    continue;
                }

                $seenChannelWide[$type] = true;
                $mentions->push($this->make($message, $type));
            }
        }

        // Nomes só quando o XML não trouxe nada, senão a menção contaria duas vezes.
        if ($seenUsers === [] && $seenGroups === []) {
            // Nomes distintos numa consulta só, cortados no teto. Só `username`:
            // `display_name` não é coluna, e apelidos ficam com o flarum/mentions.
            $names = [];

            foreach ($this->plainNames($raw) as $name) {
                $names[mb_strtolower($name)] ??= $name;

                if (count($names) > self::MAX_PLAIN_NAMES) {
                    break;
                }
            }

            $users = $names === []
                ? collect()
                : User::query()->whereIn('username', array_values($names))->get()->keyBy(fn (User $user) => mb_strtolower($user->username));

            foreach ($names as $key => $name) {
                $user = $users->get($key);

                if ($user !== null && ! isset($seenUsers[$user->id])) {
                    $seenUsers[$user->id] = true;
                    $mentions->push($this->make($message, MessageMention::TYPE_USER, userId: $user->id));

                    continue;
                }

                // Grupo por nome só para quem pode mencionar grupos; sem isso qualquer
                // `@Moderators` alcançava o grupo inteiro.
                $group = $mayMentionGroups() ? $this->findGroupByName($name) : null;

                if ($group !== null && ! isset($seenGroups[$group->id])) {
                    $seenGroups[$group->id] = true;
                    $mentions->push($this->make($message, MessageMention::TYPE_GROUP, groupId: $group->id));
                }
            }
        }

        if (count($seenUsers) > self::MAX_USER_MENTIONS) {
            $mentions = $mentions->reject(fn (MessageMention $mention) => $mention->type === MessageMention::TYPE_USER)->values();
        }

        return $mentions;
    }

    /**
     * Substitui as menções da mensagem por um conjunto novo. Usado no envio e na
     * edição, para que remover uma menção também tire a pendência de não lida.
     */
    public function sync(Message $message, bool $allowChannelWide): Collection
    {
        $message->mentions()->delete();

        $mentions = $this->resolve($message, $allowChannelWide);

        foreach ($mentions as $mention) {
            $mention->save();
        }

        return $mentions;
    }

    /**
     * @return iterable<array{0: string, 1: int}>
     */
    protected function parsedMentions(string $raw): iterable
    {
        if (! preg_match_all(self::TAG_PATTERN, $raw, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            // Só o `id` numérico é confiável; username/groupname podem estar
            // desatualizados após renomear.
            if (strtolower($match['attr']) !== 'id') {
                continue;
            }

            $id = (int) $match['value'];

            if ($id <= 0) {
                continue;
            }

            yield strtoupper($match['kind']) === 'USERMENTION'
                ? [MessageMention::TYPE_USER, $id]
                : [MessageMention::TYPE_GROUP, $id];
        }
    }

    /**
     * @return iterable<string>
     */
    protected function channelWideMentions(string $raw): iterable
    {
        // Limites de palavra evitam casar "@allen" e "email@here.com".
        if (preg_match('/(?<![\w\/])@here\b/i', $raw)) {
            yield MessageMention::TYPE_HERE;
        }

        if (preg_match('/(?<![\w\/])@all\b/i', $raw)) {
            yield MessageMention::TYPE_ALL;
        }
    }

    /**
     * @return iterable<string>
     */
    protected function plainNames(string $raw): iterable
    {
        if (! preg_match_all(self::PLAIN_PATTERN, $raw, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $match) {
            $name = $match['quoted'] !== '' ? $match['quoted'] : ($match['plain'] ?? '');

            if ($name === '' || in_array(strtolower($name), ['here', 'all'], true)) {
                continue;
            }

            yield $name;
        }
    }


    protected function findGroupByName(string $name): ?Group
    {
        return $this->mentionableGroups()
            ->where(function ($query) use ($name) {
                $query->where('name_singular', $name)
                    ->orWhere('name_plural', $name);
            })
            ->first();
    }

    protected function mentionableGroup(int $id): ?Group
    {
        return $this->mentionableGroups()->whereKey($id)->first();
    }

    /**
     * Grupos mencionáveis: nunca convidados nem membros (o fórum inteiro) nem
     * grupos ocultos. As mesmas exclusões do flarum/mentions em posts.
     *
     * @return Builder<Group>
     */
    protected function mentionableGroups(): Builder
    {
        $query = Group::query();

        // Instruções separadas: os where* são tipados como query builder base e
        // perderiam o Eloquent.
        $query->where('is_hidden', false);
        $query->whereNotIn('id', [Group::GUEST_ID, Group::MEMBER_ID]);

        return $query;
    }

    /**
     * `mentionGroups` é a permissão do core para o mesmo em posts. Mensagem de
     * bot ou webhook não tem autor e não menciona grupo.
     */
    protected function mayMentionGroups(Message $message): bool
    {
        if ($message->user_id === null) {
            return false;
        }

        $author = $message->relationLoaded('user') ? $message->user : $message->user()->first();

        return $author instanceof User && $author->hasPermission('mentionGroups');
    }

    protected function make(Message $message, string $type, ?int $userId = null, ?int $groupId = null): MessageMention
    {
        $mention = new MessageMention();

        $mention->message_id = $message->id;
        $mention->type = $type;
        $mention->user_id = $userId;
        $mention->group_id = $groupId;
        $mention->created_at = Carbon::now();

        return $mention;
    }
}
