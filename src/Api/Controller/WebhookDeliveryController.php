<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Foundation\ValidationException;
use Flarum\Locale\Translator;
use Flarum\Post\Exception\FloodingException;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\Guest;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Chat\Event\MessageWasSent;
use Ramon\Chat\Message;
use Ramon\Chat\Service\ActionThrottle;
use Ramon\Chat\Service\UnreadTracker;
use Ramon\Chat\Webhook;
use Tobyz\JsonApiServer\Exception\ForbiddenException;

/**
 * Webhook de entrada compatível com o Slack (body JSON ou campo `payload`
 * form-encoded). Sem CSRF (ver extend.php), porque quem entrega não tem sessão: a
 * chave no path é a única credencial, comparada em tempo constante e nunca
 * serializada de volta.
 */
class WebhookDeliveryController implements RequestHandlerInterface
{
    protected const MAX_PER_IP_PER_MINUTE = 120;

    protected const MAX_PER_WEBHOOK_PER_MINUTE = 60;

    public function __construct(
        protected Events $events,
        protected Translator $translator,
        protected SettingsRepositoryInterface $settings,
        protected UnreadTracker $unread,
        protected ActionThrottle $throttle
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // `getAttribute()` é null fora do ResolveRoute, e `null['key']` daria fatal.
        $key = (string) Arr::get((array) $request->getAttribute('routeParameters'), 'key', '');

        // Chave geral do admin, antes de olhar a chave: com webhooks desligados,
        // URL válida não adianta.
        if (! (bool) $this->settings->get('ramon-chat.webhooks_enabled', true)) {
            throw new ForbiddenException();
        }

        // Por IP, antes da busca da chave: a rota não tem autenticação, e só isso
        // segura um scanner testando chaves sem fim.
        $ip = (string) ($request->getAttribute('ipAddress') ?? Arr::get($request->getServerParams(), 'REMOTE_ADDR', ''));

        if (! $this->throttle->attempt('webhook.ip.'.sha1($ip), self::MAX_PER_IP_PER_MINUTE, 60)) {
            throw new FloodingException();
        }

        $webhook = $this->resolve($key);

        if ($webhook === null) {
            throw new ForbiddenException();
        }

        // E por webhook, para uma chave vazada não inundar o canal.
        if (! $this->throttle->attempt('webhook.hook.'.$webhook->id, self::MAX_PER_WEBHOOK_PER_MINUTE, 60)) {
            throw new FloodingException();
        }

        $channel = $webhook->channel;

        if ($channel === null || ! $channel->acceptsMessages()) {
            throw new ValidationException([
                'channel' => $this->translator->trans('ramon-chat.api.webhook_channel_unavailable'),
            ]);
        }

        $text = $this->extractText($request);

        // O limite do próprio canal, se houver, senão o padrão do fórum.
        $max = $channel->maxMessageLength(
            (int) $this->settings->get('ramon-chat.max_message_length', 3000)
        );

        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
        }

        // Postada como o admin escolhido no webhook ou como o bot. Mensagem de bot
        // não tem `user_id`; o cliente tira nome e avatar das settings do bot.
        $author = $webhook->author();

        // Quem chama só tem a chave: o texto é interpretado como visitante, nunca
        // com as permissões do admin escolhido como autor.
        $message = $author !== null
            ? Message::build($channel, $author, $text, parseAs: new Guest())
            : Message::buildBot($channel, null, $text);

        $message->webhook_id = $webhook->id;
        $message->save();

        $channel->last_message_id = $message->id;
        $channel->last_message_at = $message->created_at;
        $channel->messages_count++;
        $channel->save();

        $message->setRelation('channel', $channel);

        // Conta como não lida, senão canal de integração nunca ganharia badge.
        $this->unread->recordNewMessage($message);

        $webhook->recordDelivery()->save();

        $this->events->dispatch(new MessageWasSent($message, $author));

        return new JsonResponse([
            'data' => [
                'type'       => 'chat-messages',
                'id'         => (string) $message->id,
                'attributes' => ['channelId' => $channel->id],
            ],
        ], 201);
    }

    /**
     * Comparação em tempo constante. `where('key', $key)` seria um oráculo de
     * tempo no índice; busca por prefixo + hash_equals não vaza a chave caractere
     * a caractere.
     */
    protected function resolve(string $key): ?Webhook
    {
        // Chaves vêm de Str::random, só alfanuméricas; o resto nem chega ao banco.
        if (! preg_match('/\A[A-Za-z0-9]{16,128}\z/', $key)) {
            return null;
        }

        // Prefixo escapado: vem da URL, e `%` ou `_` virariam curinga sobre todas
        // as chaves ativas, vazando pelo tempo quantas casam com o padrão.
        $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], substr($key, 0, 8));

        $candidates = Webhook::query()
            ->where('active', true)
            ->where('key', 'like', $prefix.'%')
            ->with(['channel', 'user.groups'])
            ->get();

        foreach ($candidates as $candidate) {
            if (hash_equals($candidate->key, $key)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @throws ValidationException
     */
    protected function extractText(ServerRequestInterface $request): string
    {
        $body = $request->getParsedBody();

        if (is_array($body) && isset($body['payload']) && is_string($body['payload'])) {
            $decoded = json_decode($body['payload'], true);

            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        if (! is_array($body)) {
            $decoded = json_decode((string) $request->getBody(), true);
            $body = is_array($decoded) ? $decoded : [];
        }

        $text = (string) (
            Arr::get($body, 'text')
            ?? Arr::get($body, 'data.attributes.text')
            ?? ''
        );

        // Sem `text`, o corpo vem nos "attachments" do Slack; a maioria das
        // integrações prontas depende disso.
        if (trim($text) === '') {
            $fallbacks = [];

            foreach ((array) Arr::get($body, 'attachments', []) as $attachment) {
                foreach (['fallback', 'text', 'title', 'pretext'] as $field) {
                    $value = Arr::get((array) $attachment, $field);

                    if (is_string($value) && trim($value) !== '') {
                        $fallbacks[] = trim($value);
                        break;
                    }
                }
            }

            $text = implode("\n", $fallbacks);
        }

        $text = trim($text);

        if ($text === '') {
            throw new ValidationException([
                'text' => $this->translator->trans('ramon-chat.api.webhook_empty'),
            ]);
        }

        return $text;
    }
}
