<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Carbon\Carbon;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Access\AbstractPolicy;
use Flarum\User\User;
use Ramon\Chat\Message;
use Ramon\Chat\Service\ChannelOwnership;

/**
 * Permissões sobre mensagens.
 *
 * @see ChannelPolicy sobre por que os métodos retornam `?bool` e não `bool`.
 */
class MessagePolicy extends AbstractPolicy
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected VisibilityCache $cache,
        protected ChannelOwnership $ownership
    ) {
    }

    /**
     * Respondido a partir da mensagem em mãos e memoizado: o resource resolve
     * várias abilities que caem aqui para cada linha, e um `EXISTS` por linha
     * custaria caro. Sem o canal carregado, a query continua sendo a autoridade.
     */
    public function view(User $actor, Message $message): ?bool
    {
        $visible = $this->cache->remember(
            $actor,
            'message',
            (int) $message->id,
            function () use ($actor, $message) {
                $channel = $message->channel;

                if ($channel === null) {
                    return Message::whereVisibleTo($actor)->whereKey($message->id)->exists();
                }

                return ScopeMessageVisibility::rowVisibleTo(
                    $actor,
                    $message,
                    ScopeChannelVisibility::visibleTo($actor, $channel, $this->cache)
                        && ScopeChannelVisibility::readsContents($actor, $channel)
                );
            }
        );

        return $visible ?: null;
    }

    /**
     * Só o autor edita. Moderadores podem apagar, nunca editar: alterar em silêncio
     * a fala de alguém é um poder diferente de removê-la.
     */
    public function edit(User $actor, Message $message): ?bool
    {
        if ($message->isSystem() || $message->isDeleted() || $message->user_id === null) {
            return false;
        }

        if ($actor->id !== $message->user_id) {
            return false;
        }

        if (! $this->view($actor, $message)) {
            return false;
        }

        $channel = $message->channel;

        if ($channel === null || ! $channel->acceptsMessages()) {
            return false;
        }

        // Editar é postar de novo: quem saiu do canal ou perdeu o direito de
        // postar não pode mais mudar o que aparece com o nome dele.
        if (! $actor->can('postMessage', $channel)) {
            return false;
        }

        return $this->withinEditWindow($message);
    }

    /**
     * Moderadores apagam qualquer coisa; o dono do canal e os moderadores dele
     * também. Restaurar fica com a moderação do chat, porque a mensagem apagada só
     * é visível para o autor e para ela (ScopeMessageVisibility).
     */
    public function delete(User $actor, Message $message): ?bool
    {
        if ($message->isDeleted()) {
            return false;
        }

        if ($actor->hasPermission('ramon-chat.moderate')) {
            return true;
        }

        if ($message->channel !== null
            && $this->ownership->moderates($actor, $message->channel)
            && ! $this->authorOutranks($actor, $message)) {
            return true;
        }

        if ($message->isSystem() || $message->user_id === null) {
            return false;
        }

        if ($actor->id === $message->user_id && $this->view($actor, $message)) {
            return true;
        }

        return null;
    }

    /**
     * Remove a linha de vez. Só sobre mensagem já apagada, para o ato irreversível
     * nunca estar a um clique da conversa viva. A raiz de thread é recusada:
     * `chat_threads.original_message_id` não tem foreign key e ficaria órfã.
     */
    public function forceDelete(User $actor, Message $message): ?bool
    {
        if (! $message->isDeleted()) {
            return false;
        }

        if ($message->isThreadRoot()) {
            return false;
        }

        return $actor->hasPermission('ramon-chat.moderate') ? true : false;
    }

    public function restore(User $actor, Message $message): ?bool
    {
        if (! $message->isDeleted()) {
            return false;
        }

        return $actor->hasPermission('ramon-chat.moderate') ? true : null;
    }

    public function react(User $actor, Message $message): ?bool
    {
        if ($message->isDeleted()) {
            return false;
        }

        $channel = $message->channel;

        if ($channel === null) {
            return false;
        }

        // Não é `postMessage`: num canal de anúncios só moderadores escrevem, e
        // reagir é como os demais mostram que leram. O resto vale igual: o canal
        // aceita mensagens, a mensagem é visível e o ator é membro.
        if (! $channel->acceptsMessages()) {
            return false;
        }

        if (! $this->view($actor, $message)) {
            return false;
        }

        if (! $channel->includes($actor)) {
            return false;
        }

        return $actor->can('ramon-chat.react') ? true : null;
    }

    public function bookmark(User $actor, Message $message): ?bool
    {
        if (! $actor->exists) {
            return false;
        }

        return $this->view($actor, $message);
    }

    /**
     * Denunciar à moderação. Recusado na própria mensagem, na apagada e na de
     * sistema (sem autor a responsabilizar). Moderadores também podem denunciar.
     */
    public function flag(User $actor, Message $message): ?bool
    {
        if (! $actor->exists) {
            return false;
        }

        if ($message->isDeleted() || $message->isSystem() || $message->user_id === null) {
            return false;
        }

        if ($actor->id === $message->user_id) {
            return false;
        }

        if (! $this->view($actor, $message)) {
            return false;
        }

        return $actor->hasPermission('ramon-chat.flagMessage') ? true : false;
    }

    public function reply(User $actor, Message $message): ?bool
    {
        if ($message->isDeleted()) {
            return false;
        }

        $channel = $message->channel;

        return $channel !== null && $actor->can('postMessage', $channel) ? true : false;
    }

    /**
     * Abrir uma thread a partir de uma mensagem: threads ligadas no canal, direito
     * de postar e `ramon-chat.createThread`. Responder dentro de uma thread que já
     * existe é só `postMessage`.
     */
    public function createThread(User $actor, Message $message): ?bool
    {
        $channel = $message->channel;

        if ($channel === null || ! $channel->threading_enabled || $message->isDeleted()) {
            return false;
        }

        // Threads têm um nível só, e a raiz já tem a sua.
        if ($message->thread_id !== null) {
            return false;
        }

        if (! $actor->hasPermission('ramon-chat.createThread')) {
            return false;
        }

        return $actor->can('postMessage', $channel) ? true : false;
    }

    public function move(User $actor, Message $message): ?bool
    {
        return $actor->hasPermission('ramon-chat.moderate') ? true : null;
    }

    /**
     * Fixar é editorial, não moderação: tem permissão própria (admins por padrão).
     */
    public function pin(User $actor, Message $message): ?bool
    {
        if ($message->isDeleted() || $message->isSystem()) {
            return false;
        }

        // Respostas de thread também: a lista de fixadas pede `includeThreadReplies`.
        return $actor->hasPermission('ramon-chat.pinMessage') ? true : false;
    }

    public function viewRevisions(User $actor, Message $message): ?bool
    {
        return $this->view($actor, $message);
    }

    /**
     * Janela vazia ou zero: a edição nunca expira.
     */
    protected function withinEditWindow(Message $message): bool
    {
        $minutes = (int) $this->settings->get('ramon-chat.message_edit_window_minutes', 0);

        if ($minutes <= 0) {
            return true;
        }

        return $message->created_at !== null
            && $message->created_at->greaterThan(Carbon::now()->subMinutes($minutes));
    }

    /**
     * A mesma hierarquia de `removeMember`: quem modera o canal não apaga a
     * mensagem de admins e moderadores do chat, e um moderador do canal não apaga
     * a do dono nem a de outro moderador.
     */
    protected function authorOutranks(User $actor, Message $message): bool
    {
        $author = $message->user;

        if ($author === null || (int) $author->id === (int) $actor->id) {
            return false;
        }

        if ($author->isAdmin() || $author->hasPermission('ramon-chat.moderate')) {
            return true;
        }

        $channel = $message->channel;

        if ($channel === null || $this->ownership->controls($actor, $channel)) {
            return false;
        }

        return (int) $channel->creator_id === (int) $author->id
            || $channel->membershipFor($author)?->isModerator() === true;
    }
}
