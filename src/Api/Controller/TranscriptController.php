<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\Translator;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Message;
use Ramon\Chat\Service\TranscriptRenderer;

/**
 * Transcrição das mensagens selecionadas, em markup ("Citar na discussão") ou
 * texto puro ("Copiar"). Renderizada no servidor para sair igual ao arquivamento
 * e o cliente não reimplementar as regras de citação.
 */
class TranscriptController implements RequestHandlerInterface
{
    protected const MAX_MESSAGES = 200;

    public function __construct(
        protected Translator $translator,
        protected TranscriptRenderer $transcript
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $body = $request->getParsedBody();
        $attributes = (array) Arr::get($body, 'data.attributes', []);

        $ids = array_values(array_unique(array_filter(
            array_map('intval', (array) Arr::get($attributes, 'messageIds', []))
        )));

        if ($ids === []) {
            throw new ValidationException([
                'messageIds' => $this->translator->trans('ramon-chat.api.transcript_empty'),
            ]);
        }

        if (count($ids) > self::MAX_MESSAGES) {
            throw new ValidationException([
                'messageIds' => $this->translator->trans('ramon-chat.api.transcript_too_many', [
                    'max' => self::MAX_MESSAGES,
                ]),
            ]);
        }

        // whereVisibleTo é toda a fronteira de segurança: a seleção pode citar
        // qualquer id, e só volta o que o ator pode ler.
        $messages = Message::query()
            ->whereVisibleTo($actor)
            ->whereIn('id', $ids)
            ->with(['user', 'uploads', 'webhook', 'channel'])
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            throw new ValidationException([
                'messageIds' => $this->translator->trans('ramon-chat.api.transcript_empty'),
            ]);
        }

        $format = Arr::get($attributes, 'format') === 'plain' ? 'plain' : 'markup';

        $rendered = $format === 'plain'
            ? $this->transcript->renderPlain($messages)
            : $this->transcript->render($messages);

        return new JsonResponse([
            'data' => [
                'type'       => 'chat-transcripts',
                'attributes' => [
                    'format'  => $format,
                    'content' => $rendered,
                    // O que entrou de fato, para o cliente avisar quando ids
                    // ilegíveis ficaram de fora.
                    'count'   => $messages->count(),
                    'omitted' => count($ids) - $messages->count(),
                ],
            ],
        ]);
    }
}
