<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Service;

use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ramon\Chat\Channel;

/**
 * Quem cria e administra canais: uma chave global, as permissões da seção
 * "Membros" e um papel por canal.
 *
 * Em MODE_ADMIN só administradores criam canais e ninguém tem direitos sobre
 * eles. Em MODE_MEMBERS:
 *
 *  - `createChannel` abre um canal;
 *  - `manageOwnChannels` torna o criador dono (membros, moderadores, fechar,
 *    arquivar, apagar, remover mensagens, threads);
 *  - `editOwnChannels` deixa o criador mudar as configurações;
 *  - o dono promove membros a moderador do canal, que gerencia membros,
 *    mensagens e threads, mas não configura, arquiva, apaga nem promove.
 *
 * O dono é `creator_id`, então transferir (OwnershipTransfers) é uma escrita só.
 * Dono e moderadores do canal ignoram o modo lento; moderadores do chat
 * (`ramon-chat.moderate`) mandam em todo canal nos dois modos.
 */
class ChannelOwnership
{
    public const SETTING = 'ramon-chat.channel_ownership';

    public const MODE_ADMIN = 'admin';

    public const MODE_MEMBERS = 'members';

    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function membersOwnChannels(): bool
    {
        return $this->settings->get(self::SETTING, self::MODE_ADMIN) === self::MODE_MEMBERS;
    }

    /**
     * Se o ator é dono do canal: modo membros, canal de categoria dele e
     * `manageOwnChannels` concedida.
     */
    public function manages(User $actor, Channel $channel): bool
    {
        return $this->owns($actor, $channel) && $actor->hasPermission('ramon-chat.manageOwnChannels');
    }

    /**
     * Se o ator pode mudar as configurações como criador. Ser dono implica isso,
     * como `moderate` global implica `editChannel`.
     */
    public function edits(User $actor, Channel $channel): bool
    {
        if ($this->manages($actor, $channel)) {
            return true;
        }

        return $this->owns($actor, $channel) && $actor->hasPermission('ramon-chat.editOwnChannels');
    }

    /**
     * Se o ator foi promovido a moderador do canal pelo dono.
     *
     * Só no modo membros e só com membership ativa: o papel numa linha de quem saiu
     * não vale até a pessoa voltar.
     */
    public function moderatesChannel(User $actor, Channel $channel): bool
    {
        if (! $this->membersOwnChannels() || ! $channel->isCategory()) {
            return false;
        }

        $membership = $channel->membershipFor($actor);

        return $membership !== null && ! $membership->hasLeft() && $membership->isModerator();
    }

    /**
     * `ramon-chat.moderate` global, ser dono ou moderador do canal: o necessário
     * para agir sobre mensagens alheias e membros. Toda policy lê daqui, para as
     * três não divergirem.
     *
     * `hasPermission` em vez de `can`: lê os grupos direto e atalha para admins,
     * sem reentrar no Gate.
     */
    public function moderates(User $actor, Channel $channel): bool
    {
        return $actor->hasPermission('ramon-chat.moderate')
            || $this->manages($actor, $channel)
            || $this->moderatesChannel($actor, $channel);
    }

    /**
     * `ramon-chat.moderate` global ou ser dono: atos irreversíveis (arquivar,
     * apagar) e escolher moderadores. Moderador do canal não chega a isso.
     */
    public function controls(User $actor, Channel $channel): bool
    {
        return $actor->hasPermission('ramon-chat.moderate') || $this->manages($actor, $channel);
    }

    /**
     * Se o ritmo do canal não se aplica ao ator: quem conduz a sala é quem
     * responde nela, e o cooldown só a deixaria mais lenta.
     */
    public function exemptFromSlowMode(User $actor, Channel $channel): bool
    {
        return $this->manages($actor, $channel) || $this->moderatesChannel($actor, $channel);
    }

    /**
     * Se o ator pode saber que existem membros ocultos: só as permissões de entrar
     * oculto ou supervisionar quem entra. Dono não está entre elas.
     */
    public function seesHiddenMembers(User $actor): bool
    {
        return $actor->hasPermission('ramon-chat.moderate')
            || $actor->hasPermission('ramon-chat.inspectChannels');
    }

    protected function owns(User $actor, Channel $channel): bool
    {
        return $this->membersOwnChannels() && $channel->isOwnedBy($actor);
    }
}
