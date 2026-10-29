<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Listener;

use Flarum\Database\AbstractModel;
use Illuminate\Contracts\Queue\Queue;
use Psr\Log\LoggerInterface;
use Ramon\Chat\Event\ChannelWasDeleted;
use Ramon\Chat\Event\ChannelWasEdited;
use Ramon\Chat\Event\MessageWasMoved;
use Ramon\Chat\Service\UploadPrivacy;
use Ramon\Chat\Storage\Job\PrivatizeTagChannels;

/**
 * Tira do disco público os anexos que passam a pertencer a um canal privado.
 *
 * O envio é coberto pelo MessageDispatcher. Aqui chegam: canal que vira privado
 * ou é vinculado a categoria restrita, mensagem movida para um canal assim, e
 * categoria que fica restrita. O que nenhum evento cobre (o flarum/tags reordena
 * com updates em massa) fica para o `chat:uploads:privatize-pending` de hora em hora.
 */
class KeepUploadsPrivate
{
    public function __construct(
        protected UploadPrivacy $privacy,
        protected Queue $queue,
        protected LoggerInterface $log
    ) {
    }

    public function whenChannelEdited(ChannelWasEdited $event): void
    {
        $channel = $event->channel;

        // `wasChanged` lê o que o save gravou: editar só a descrição não percorre os anexos.
        if (! $channel->wasChanged(['is_private', 'tag_id']) || ! UploadPrivacy::requiredFor($channel)) {
            return;
        }

        $this->privacy->privatizeChannel($channel);
    }

    /** Canal apagado sai da lista, mas os anexos continuavam publicados. */
    public function whenChannelDeleted(ChannelWasDeleted $event): void
    {
        $this->privacy->privatizeChannel($event->channel);
    }

    public function whenMessageMoved(MessageWasMoved $event): void
    {
        if (! UploadPrivacy::requiredFor($event->to)) {
            return;
        }

        $this->privacy->privatizeMessages([$event->message->id]);
    }

    /**
     * Tag do flarum/tags salva. Tipado de forma solta para não citar classe de uma
     * extensão que pode faltar; só é registrado com o flarum/tags ativo.
     *
     * A tag é salva a cada post (colunas de último post), então o teste olha o que
     * mudou sem consulta; os canais são buscados num job.
     */
    public function whenTagSaved(AbstractModel $tag): void
    {
        $restricted = $tag->wasChanged('is_restricted') && $tag->getAttribute('is_restricted');
        $moved = $tag->wasChanged('parent_id') && $tag->getAttribute('parent_id') !== null;

        if ($tag->wasRecentlyCreated || (! $restricted && ! $moved)) {
            return;
        }

        try {
            $this->queue->push(new PrivatizeTagChannels((int) $tag->getKey()));
        } catch (\Throwable $e) {
            // A edição da tag já foi gravada e não pode falhar por isso; a varredura de
            // hora em hora encontra os canais.
            $this->log->error('[ramon-chat] could not queue privatizing the channels of a restricted tag', [
                'tag'   => $tag->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
