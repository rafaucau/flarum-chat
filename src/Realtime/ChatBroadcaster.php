<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Realtime;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Queue;
use Psr\Log\LoggerInterface;
use Pusher\Pusher;
use Ramon\Chat\Channel;
use Ramon\Chat\Realtime\Job\SendChatEventJob;

/**
 * Entrega eventos do chat pelo websocket do flarum/realtime. Nunca usa o canal
 * `public`, que todo cliente conectado (até visitante) assina: cada payload vai
 * para os `private-user=<id>` de quem pode ver o canal.
 *
 * O job roda na hora por padrão: na fila `database` do Flarum o worker roda uma
 * vez por minuto, e chat não espera um minuto. `ramon-chat.queue_realtime` manda
 * para a fila, mas só vale com worker contínuo (QueueKind).
 */
class ChatBroadcaster
{
    public function __construct(
        protected Container $container,
        protected Bus $bus,
        protected Queue $queue,
        protected SettingsRepositoryInterface $settings,
        protected LoggerInterface $log
    ) {
    }

    /**
     * Envia para todo membro elegível do canal.
     *
     * @param  array<string, mixed>  $payload
     * @param  int|null  $exceptUserId  O ator, que já sabe o que aconteceu.
     * @param  bool  $queue  Fora da request independentemente da configuração,
     *                       para audiências grandes demais.
     */
    public function toChannelMembers(
        Channel $channel,
        string $event,
        array $payload,
        ?int $exceptUserId = null,
        bool $queue = false
    ): void {
        $this->dispatch(new SendChatEventJob(
            event: $event,
            payload: $payload,
            channelId: (int) $channel->id,
            exceptUserId: $exceptUserId
        ), $queue);
    }

    /**
     * Envia para o canal privado de um usuário.
     *
     * @param  array<string, mixed>  $payload
     */
    public function toUser(int $userId, string $event, array $payload): void
    {
        $this->dispatch(new SendChatEventJob(
            event: $event,
            payload: $payload,
            userId: $userId
        ));
    }

    /**
     * Envia para todos com `ramon-chat.moderate`.
     *
     * @param  array<string, mixed>  $payload
     */
    public function toModerators(string $event, array $payload, ?int $exceptUserId = null): void
    {
        $this->dispatch(new SendChatEventJob(
            event: $event,
            payload: $payload,
            exceptUserId: $exceptUserId,
            moderators: true
        ));
    }

    /**
     * Nunca derruba a ação que gerou o evento: a mensagem já foi gravada e o
     * cliente reconcilia pela API. Sem binding do Pusher não faz nada, o que poupa
     * cada tecla digitada num fórum sem realtime.
     */
    protected function dispatch(SendChatEventJob $job, bool $queue = false): void
    {
        if (! class_exists(Pusher::class) || ! $this->container->bound(Pusher::class)) {
            return;
        }

        try {
            // Só fila com worker contínuo; em `sync` e `database` roda aqui.
            if (($queue || $this->settings->get('ramon-chat.queue_realtime'))
                && QueueKind::defers($this->queue)) {
                $this->queue->push($job);

                return;
            }

            // `dispatchNow`, não `dispatchSync`: o QueueFactory do Flarum ignora o
            // nome 'sync' e devolveria o job para a fila configurada.
            $this->bus->dispatchNow($job);
        } catch (\Throwable $e) {
            $this->log->warning('[ramon/chat] realtime dispatch failed: '.$e->getMessage());
        }
    }
}
