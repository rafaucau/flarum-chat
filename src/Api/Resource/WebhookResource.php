<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Resource;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\Translator;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Ramon\Chat\Channel;
use Ramon\Chat\Webhook;
use Tobyz\JsonApiServer\Context as OriginalContext;

/**
 * Webhooks de entrada, geridos no painel admin. Tudo exige `isAdmin()`: o webhook
 * posta *como o fórum* e a chave dispensa sessão. A chave é `$hidden` no model e
 * só aparece na criação e no rotate, nunca na listagem (iria parar em logs).
 *
 * @extends AbstractDatabaseResource<Webhook>
 */
class WebhookResource extends AbstractDatabaseResource
{
    /**
     * O webhook que o rotate acabou de salvar, para os getters saberem que a chave
     * é nova e pode sair. Propriedade de instância porque o Api\Context não
     * identifica endpoint custom, e `withInternal()` devolve uma cópia que o
     * serializer não vê. Guarda a instância, não um booleano: o resource vive
     * entre requests, e a marca ligada mostraria as chaves na próxima listagem.
     */
    protected ?Webhook $minted = null;

    public function __construct(
        protected Translator $translator,
        protected UrlGenerator $url
    ) {
    }

    protected function keyVisible(Webhook $webhook, Context $context): bool
    {
        return $context->creating() || $this->minted === $webhook;
    }

    public function type(): string
    {
        return 'chat-webhooks';
    }

    public function model(): string
    {
        return Webhook::class;
    }

    public function scope(Builder $query, OriginalContext $context): void
    {
        // Reforço às checagens por endpoint: um endpoint sem trava por engano não
        // vaza linhas para quem não é admin.
        if (! $context->getActor()->isAdmin()) {
            $query->whereRaw('1 = 0');
        }
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->authenticated()
                ->visible(fn (Context $context) => $context->getActor()->isAdmin())
                ->defaultInclude(['channel', 'user'])
                ->defaultSort('-createdAt'),

            Endpoint\Show::make()
                ->authenticated()
                ->visible(fn (Webhook $webhook, Context $context) => $context->getActor()->isAdmin())
                ->defaultInclude(['channel', 'user']),

            Endpoint\Create::make()
                ->authenticated()
                ->visible(fn (Context $context) => $context->getActor()->isAdmin())
                ->defaultInclude(['channel', 'user']),

            Endpoint\Update::make()
                ->authenticated()
                ->visible(fn (Webhook $webhook, Context $context) => $context->getActor()->isAdmin())
                ->defaultInclude(['channel', 'user']),

            Endpoint\Delete::make()
                ->authenticated()
                ->visible(fn (Webhook $webhook, Context $context) => $context->getActor()->isAdmin()),

            // Rotacionar invalida a configuração do lado que envia, então é ação
            // explícita, não efeito de um update.
            Endpoint\Endpoint::make('rotate')
                ->route('POST', '/{id}/rotate')
                ->authenticated()
                ->visible(fn (Webhook $webhook, Context $context) => $context->getActor()->isAdmin())
                ->action(function (Context $context) {
                    /** @var Webhook $webhook */
                    $webhook = $context->model;

                    $webhook->key = Webhook::generateKey();
                    $webhook->save();

                    $this->minted = $webhook;

                    return $webhook;
                })
                ->defaultInclude(['channel', 'user']),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('name')
                ->requiredOnCreate()
                ->writable()
                ->maxLength(100),

            Schema\Str::make('description')
                ->nullable()
                ->writable()
                ->maxLength(255),

            // Como a mensagem entregue aparece atribuída no stream.
            Schema\Str::make('username')
                ->nullable()
                ->writable()
                ->maxLength(60),

            Schema\Str::make('emoji')
                ->nullable()
                ->writable()
                ->maxLength(64),

            Schema\Boolean::make('active')
                ->writable(),

            Schema\Integer::make('channelId')
                ->requiredOnCreate()
                ->writable()
                ->set(function (Webhook $webhook, $value) {
                    $channel = Channel::query()->whereNull('deleted_at')->find((int) $value);

                    if ($channel === null) {
                        throw new ValidationException([
                            'channelId' => $this->translator->trans('ramon-chat.api.channel_not_found'),
                        ]);
                    }

                    $webhook->channel_id = $channel->id;
                }),

            // Autor das entregas: conta de um admin, ou null para o bot. Só admins,
            // porque quem tem a chave fala como essa conta.
            Schema\Integer::make('userId')
                ->nullable()
                ->writable()
                ->set(function (Webhook $webhook, $value) {
                    if ($value === null || (int) $value <= 0) {
                        $webhook->user_id = null;

                        return;
                    }

                    $user = User::query()->with('groups')->find((int) $value);

                    if ($user === null || ! $user->isAdmin()) {
                        throw new ValidationException([
                            'userId' => $this->translator->trans('ramon-chat.api.webhook_user_not_admin'),
                        ]);
                    }

                    $webhook->user_id = $user->id;
                }),

            Schema\Integer::make('deliveriesCount'),
            Schema\DateTime::make('lastDeliveredAt')->nullable(),
            Schema\DateTime::make('createdAt'),

            // Só preenchido logo após gerar a chave (create e rotate). Na listagem
            // é null, para a chave não ser colhida de cache, log ou screenshot.
            Schema\Str::make('key')
                ->nullable()
                ->get(fn (Webhook $webhook, Context $context) => $this->keyVisible($webhook, $context) ? $webhook->key : null),

            // URL completa para configurar no lado que envia.
            Schema\Str::make('url')
                ->nullable()
                ->get(fn (Webhook $webhook, Context $context) => $this->keyVisible($webhook, $context)
                    ? $this->url->to('api')->route('chat.webhooks.deliver', ['key' => $webhook->key])
                    : null),

            Schema\Relationship\ToOne::make('channel')->type('chat-channels')->includable(),
            Schema\Relationship\ToOne::make('creator')->type('users')->includable(),
            Schema\Relationship\ToOne::make('user')->type('users')->includable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt'),
            SortColumn::make('name'),
            SortColumn::make('deliveriesCount'),
        ];
    }

    public function newModel(OriginalContext $context): Webhook
    {
        $webhook = new Webhook();

        // Defaults explícitos: boolean não setado sai null, e sem chave o webhook
        // não recebe nada.
        $webhook->key = Webhook::generateKey();
        $webhook->active = true;
        $webhook->deliveries_count = 0;
        $webhook->creator_id = $context->getActor()->id;

        return $webhook;
    }

    public function deleteModel(object $model, OriginalContext $context): void
    {
        $model->delete();
    }
}
