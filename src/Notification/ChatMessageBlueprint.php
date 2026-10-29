<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;
use Ramon\Chat\Message;

/**
 * "Há uma mensagem nova em um canal que você acompanha."
 *
 * Sem canal de entrega de propósito: atividade do chat aparece no próprio chat,
 * não no sino nem por e-mail. Mantido porque ainda há notificações desse tipo
 * gravadas que precisam renderizar.
 */
class ChatMessageBlueprint implements BlueprintInterface
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
        return 'chatMessage';
    }

    public static function getSubjectModel(): string
    {
        return Message::class;
    }
}
