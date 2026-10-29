<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Notification;

use Flarum\Database\AbstractModel;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\User\User;
use Ramon\Chat\Message;
use Ramon\Chat\MessageFlag;

/**
 * "Uma mensagem do chat foi denunciada." Vai aos moderadores.
 *
 * AlertableInterface não tem métodos, mas sem ela o AlertNotificationDriver
 * descarta a notificação em silêncio. Sem e-mail: denúncias vêm em rajadas.
 */
class MessageFlaggedBlueprint implements AlertableInterface, BlueprintInterface
{
    public function __construct(
        public MessageFlag $flag,
        public Message $message,
        public ?User $reporter = null
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->message;
    }

    public function getFromUser(): ?User
    {
        return $this->reporter;
    }

    /**
     * Só ids e a chave do motivo.
     *
     * A coluna `data` volta a cada destinatário sem checar policy, então o texto
     * denunciado nunca vai aqui. O nome do canal é exceção segura: só moderadores recebem.
     */
    public function getData(): array
    {
        return [
            'flagId'      => (int) $this->flag->id,
            'messageId'   => (int) $this->message->id,
            'channelId'   => (int) $this->message->channel_id,
            'channelName' => (string) ($this->message->channel->name ?? ''),
            'reason'      => (string) $this->flag->reason,
        ];
    }

    public static function getType(): string
    {
        return 'chatMessageFlagged';
    }

    public static function getSubjectModel(): string
    {
        return Message::class;
    }
}
