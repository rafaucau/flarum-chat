<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Notification\NotificationSyncer;
use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Access\PermissionSetVisibility;
use Ramon\Chat\Access\ScopeMessageVisibility;
use Ramon\Chat\ChannelUser;
use Ramon\Chat\Event\MessageWasSent;
use Ramon\Chat\Message;
use Ramon\Chat\Notification\ChatMentionBlueprint;
use Ramon\Chat\Notification\ChatMessageBlueprint;
use Ramon\Chat\Service\UnreadTracker;
use Ramon\Chat\ThreadUser;

/**
 * Distribui uma mensagem nova em notificações.
 *
 * Dois públicos; quem está nos dois recebe só a menção, o sinal mais forte.
 */
class SendChatNotifications
{
    public function __construct(
        protected NotificationSyncer $notifications,
        protected UnreadTracker $unread,
        protected LoggerInterface $log
    ) {
    }

    public function handle(MessageWasSent $event): void
    {
        $message = $event->message;

        // Mensagens de sistema não geram notificação.
        if ($message->isSystem()) {
            return;
        }

        $channel = $message->channel;

        if ($channel === null) {
            return;
        }

        $mentionedIds = $this->unread->mentionedUserIds($message);

        $mentioned = $this->recipientsFor($message, $mentionedIds, isMention: true);

        // A menção só sai por e-mail, então esta preferência é o interruptor, além do
        // `notify_chatMention_email` do core. Os dois começam ligados; qualquer um
        // desligado corta o e-mail. Os observadores abaixo continuam excluindo todos
        // os mencionados, com e-mail ou não.
        $mailable = array_values(array_filter(
            $mentioned,
            fn (User $user) => (bool) $user->getPreference('ramon-chat.emailNotifications', true)
        ));

        if ($mailable !== []) {
            $this->dispatch(new ChatMentionBlueprint($message), $mailable);
        }

        // Resolver observadores custa uma varredura das memberships e uma consulta
        // de visibilidade por destinatário. ChatMessageBlueprint não tem canal de
        // entrega, então perguntar antes tira esse custo de toda mensagem.
        if (! $this->delivers(ChatMessageBlueprint::class)) {
            return;
        }

        // Observadores, menos quem já foi notificado pela menção.
        $watcherIds = ChannelUser::query()
            ->where('channel_id', $channel->id)
            ->whereNull('left_at')
            ->where('muted', false)
            ->where('notification_level', ChannelUser::LEVEL_ALWAYS)
            ->when(
                $message->user_id !== null,
                fn ($q) => $q->where('user_id', '!=', $message->user_id)
            )
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Participantes de uma thread são notificados mesmo com nível de canal mais
        // baixo: seguir a thread é um sinal mais forte.
        if ($message->thread_id !== null) {
            $threadWatcherIds = ThreadUser::query()
                ->where('thread_id', $message->thread_id)
                ->where('notification_level', ThreadUser::LEVEL_ALWAYS)
                ->when(
                    $message->user_id !== null,
                    fn ($q) => $q->where('user_id', '!=', $message->user_id)
                )
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $watcherIds = array_merge($watcherIds, $threadWatcherIds);
        }

        $alreadyNotified = array_map(fn (User $u) => (int) $u->id, $mentioned);

        $watcherIds = array_values(array_diff(array_unique($watcherIds), $alreadyNotified));

        if ($watcherIds === []) {
            return;
        }

        $watchers = $this->recipientsFor($message, $watcherIds, isMention: false);

        if ($watchers !== []) {
            $this->dispatch(new ChatMessageBlueprint($message), $watchers);
        }
    }

    /**
     * Se algum driver entregaria o blueprint. Perguntado à classe para que
     * adicionar ou tirar um canal de entrega mude só o blueprint.
     */
    protected function delivers(string $blueprint): bool
    {
        // `Flarum\Notification\AlertableInterface`, não `…\Blueprint\…`: `is_a()`
        // com classe inexistente retorna false sem reclamar.
        return is_a($blueprint, AlertableInterface::class, true)
            || is_a($blueprint, MailableInterface::class, true);
    }

    /**
     * Envia a notificação sem deixar falha de entrega levar a mensagem junto.
     *
     * Com fila sync, o NotificationSyncer manda e-mail na hora; um servidor de
     * e-mail fora do ar abortaria o envio. A mensagem aceita fica aceita.
     * @param  User[]  $recipients
     */
    protected function dispatch(BlueprintInterface $blueprint, array $recipients): void
    {
        try {
            $this->notifications->sync($blueprint, $recipients);
        } catch (\Throwable $e) {
            $this->log->warning(
                '[ramon/chat] notification delivery failed for '.$blueprint::getType().': '.$e->getMessage()
            );
        }
    }

    /**
     * Resolve ids para usuários que podem receber a notificação. Precisa de:
     *  - ainda ver a mensagem (permissões mudam, e menção não pode vazar conteúdo);
     *  - não ter desativado o chat;
     *  - em menção de canal inteiro, não ter desativado @here/@all.
     * @param  int[]  $userIds
     * @return User[]
     */
    protected function recipientsFor(Message $message, array $userIds, bool $isMention): array
    {
        if ($userIds === []) {
            return [];
        }

        // Buckets de permissão compartilhados só valem para DMs após checar a membership.
        if ($message->channel?->isDirect()) {
            $userIds = ChannelUser::query()
                ->where('channel_id', $message->channel_id)
                ->whereNull('left_at')
                ->whereIn('user_id', $userIds)
                ->pluck('user_id')
                ->all();
        }

        $isChannelWide = $isMention && $message->mentions->contains(fn ($m) => $m->isChannelWide());

        $recipients = [];

        // Uma resposta por conjunto de permissões, compartilhada entre chunks: o
        // `EXISTS` de visibilidade com a subconsulta de tags é caro demais por membro.
        $visibility = new PermissionSetVisibility();
        $channelId = (int) $message->channel_id;

        // Em chunks para não carregar todos os membros de uma vez; `groups` vem junto
        // porque a chave do bucket o lê. `whereKey` em vez de `whereIn('id', ...)`:
        // este volta tipado como query builder e perde o tipo User.
        foreach (array_chunk($userIds, 200) as $chunk) {
            $users = User::query()->whereKey($chunk)->with('groups')->get();

            foreach ($users as $user) {
                if ($user->getPreference('ramon-chat.enabled') === false) {
                    continue;
                }

                if ($isChannelWide && $user->getPreference('ramon-chat.allowChannelWideMentions') === false) {
                    continue;
                }

                // Acesso por usuário antes, para um estranho não semear uma negação compartilhada.
                if (
                    ! ScopeMessageVisibility::rowVisibleTo($user, $message, true)
                    || ! $visibility->channelVisible($user, $channelId)
                ) {
                    continue;
                }

                $recipients[] = $user;
            }
        }

        return $recipients;
    }
}
