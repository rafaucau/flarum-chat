<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Ramon\Chat\MessageFlag;

/**
 * O que o fórum diz ao cliente sobre o chat do ator: cada controle da interface
 * é desenhado a partir disso, e o servidor continua sendo quem recusa.
 */
class ForumResourceFields
{
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('canUseChat')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('useChat')),

            Schema\Boolean::make('canCreateChatChannel')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('createChannel')),

            // Linhas @here / @all do autocomplete do composer. Oferecer o que o
            // servidor vai recusar é pior do que não oferecer.
            Schema\Boolean::make('canMentionChatChannelWide')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.mentionChannelWide')),

            // Controle "mover mensagens" da barra de seleção: a única ação de
            // seleção para a qual o MoveMessagesController exige `moderate`.
            Schema\Boolean::make('canModerateChat')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.moderate')),

            // Chaves de público do formulário do canal (auto-join, auto-join ao
            // responder, anunciar discussões). O ChannelResource recusa a mudança
            // de qualquer outra pessoa.
            Schema\Boolean::make('canCurateChatChannels')
                ->get(fn ($forum, Context $context) => $context->getActor()->isAdmin()
                    || $context->getActor()->hasPermission('ramon-chat.moderate')
                    || $context->getActor()->hasPermission('ramon-chat.editChannel')),

            // Botão de sticker. O dispatcher de mensagens também confere: esconder
            // o botão é cortesia, não trava.
            Schema\Boolean::make('canSendChatStickers')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.sendStickers')),

            Schema\Boolean::make('canUploadChatFiles')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.upload')),

            Schema\Boolean::make('canStartChatDirect')
                ->get(fn ($forum, Context $context) => $context->getActor()->can('startDirect')),

            // Botão de denúncia. A policy ainda decide por mensagem (não dá para
            // denunciar a própria nem uma apagada); isto só diz se o ator pode
            // denunciar alguma coisa.
            Schema\Boolean::make('canFlagChatMessages')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.flagMessage')),

            // O composer reinicia a contagem sozinho após o envio, sem esperar o
            // servidor, e precisa saber a quem ela não se aplica. Mesma resposta de
            // `slowModeRemaining`, para quando não há payload novo do canal.
            Schema\Boolean::make('canBypassChatSlowMode')
                ->get(fn ($forum, Context $context) => $context->getActor()->hasPermission('ramon-chat.bypassSlowMode')),

            // Badge e link de moderação. Só conta para moderadores, para a carga
            // normal da página não rodar a query.
            Schema\Integer::make('chatOpenFlagsCount')
                ->get(function ($forum, Context $context) {
                    $actor = $context->getActor();

                    if (! $actor->hasPermission('ramon-chat.moderate')) {
                        return 0;
                    }

                    // `whereHas` antes de `whereNull`: este é tipado como query
                    // builder, não Eloquent, e depois dele o método de relação some.
                    // `whereVisibleTo` é scope do ScopeVisibilityTrait, invisível
                    // para a análise estática.
                    return MessageFlag::query()
                        // @phpstan-ignore method.notFound (Flarum model scope)
                        ->whereHas('message', fn ($query) => $query->whereVisibleTo($actor))
                        ->whereNull('resolved_at')
                        ->count();
                }),
        ];
    }
}
