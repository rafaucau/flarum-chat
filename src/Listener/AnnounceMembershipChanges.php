<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Psr\Log\LoggerInterface;
use Ramon\Chat\Channel;
use Ramon\Chat\Event\UserJoinedChannel;
use Ramon\Chat\Event\UserLeftChannel;
use Ramon\Chat\Service\MessageDispatcher;

/**
 * Publica uma mensagem de sistema quando alguém entra ou sai de um canal.
 *
 * Renderizada por chave de locale (ver Message::TYPE_SYSTEM e
 * ChatMessage.systemRow). Quem foi adicionado por outro aparece como "X foi
 * adicionado por Y", não "X entrou". Não anuncia entrada oculta (nem a saída
 * correspondente) nem entrada automática por `auto_join_on_reply`.
 *
 * Canais para todos nunca chegam aqui para seus membros: a linha de membership
 * é criada sem o evento, senão a sala se narraria em milhares de linhas.
 */
class AnnounceMembershipChanges
{
    public function __construct(
        protected MessageDispatcher $dispatcher,
        protected LoggerInterface $log
    ) {
    }

    public function whenJoined(UserJoinedChannel $event): void
    {
        // Entrada oculta não é comentada.
        if ($event->hidden) {
            return;
        }

        // Efeito colateral de responder em outro lugar não é chegada.
        if ($event->automatic) {
            return;
        }

        // Convite aceito: a sala vê quem convidou, então ninguém aparece do nada num
        // canal privado. Adicionado por outro membro é narrado como adição.
        if ($event->acceptedInvite) {
            if ($event->invitedBy !== null) {
                $this->announce($event->channel, 'user_accepted_invite', [
                    'username' => $event->user->display_name,
                    'actor'    => $event->invitedBy->display_name,
                ]);

                return;
            }

            $this->announce($event->channel, 'user_joined', [
                'username' => $event->user->display_name,
            ]);

            return;
        }

        if ($this->addedBySomeoneElse($event)) {
            $this->announce($event->channel, 'user_added', [
                'username' => $event->user->display_name,
                'actor'    => $event->actor->display_name,
            ]);

            return;
        }

        $this->announce($event->channel, 'user_joined', [
            'username' => $event->user->display_name,
        ]);
    }

    public function whenLeft(UserLeftChannel $event): void
    {
        // Quem entrou sem anúncio sai sem anúncio, senão a sala descobriria pela saída
        // que a pessoa estava lá.
        if ($event->hidden) {
            return;
        }

        $this->announce($event->channel, 'user_left', [
            'username' => $event->user->display_name,
        ]);
    }

    /**
     * Entrada feita por outro ator que não o próprio usuário. Ator null ou igual
     * ao usuário é chegada, não adição.
     */
    protected function addedBySomeoneElse(UserJoinedChannel $event): bool
    {
        return $event->actor !== null
            && (int) $event->actor->id !== (int) $event->user->id;
    }

    /**
     * @param  array<string, string>  $data
     */
    protected function announce(Channel $channel, string $key, array $data): void
    {
        // Canal congelado não recebe linhas novas, nem de sistema.
        if (! $channel->acceptsMessages()) {
            return;
        }

        try {
            $this->dispatcher->sendSystem($channel, $key, $data);
        } catch (\Throwable $e) {
            // A mudança de membership já foi gravada; falhar na narração não pode
            // desfazê-la nem virar erro para quem saiu.
            $this->log->warning('[ramon/chat] could not announce '.$key.': '.$e->getMessage());
        }
    }
}
