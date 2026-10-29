<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;
use Ramon\Chat\Message;

/**
 * "Você foi mencionado em uma mensagem do chat."
 *
 * Só e-mail, não alerta: menções já têm contador e cor próprios no chat
 * (`unread_mentions_count`). O e-mail alcança a pessoa com o fórum fechado.
 */
class ChatMentionBlueprint implements BlueprintInterface, MailableInterface
{
    public function __construct(
        public Message $message
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->message;
    }

    public function getFromUser(): ?User
    {
        return $this->message->user;
    }

    /**
     * Leva o suficiente para renderizar na lista sem carregar o canal.
     */
    public function getData(): array
    {
        return [
            'channelId'   => $this->message->channel_id,
            'channelName' => $this->message->channel?->name,
            'threadId'    => $this->message->thread_id,
        ];
    }

    public static function getType(): string
    {
        return 'chatMention';
    }

    public static function getSubjectModel(): string
    {
        return Message::class;
    }

    /**
     * O Flarum espera as duas views; o NotificationMailer renderiza o par.
     */
    public function getEmailViews(): array
    {
        return [
            'text' => 'ramon-chat::emails.plain.mentioned',
            'html' => 'ramon-chat::emails.html.mentioned',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ramon-chat.email.mentioned.subject', [
            'channel' => $this->message->channel->name
                ?? $translator->trans('ramon-chat.email.mentioned.direct_channel'),
        ]);
    }
}
