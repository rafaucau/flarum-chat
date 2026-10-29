<?php

/*
 * This file is part of ramon/chat.
 *
 * Copyright (c) Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Content;

use Flarum\Api\Client;
use Flarum\Frontend\Compiler\VersionerInterface;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Str;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Coloca no payload de boot tudo o que o chat desenha na primeira pintura
 * (canais, rascunhos, mensagens, fixada), em `app.data.ramonChat`. O ChatState
 * lê isso antes do primeiro view(); o que faltar cai no caminho assíncrono.
 *
 * Registrado no frontend inteiro porque o terceiro argumento de
 * Extend\Frontend::route() já é o RequireChatAccess. Fora das rotas do chat só
 * roda com o cookie do drawer aberto (ver persistDrawerCookie no ChatState).
 */
class PreloadChat
{
    /**
     * Gravado pelo cliente com o drawer aberto: id do canal, ou 0 sem seleção.
     */
    private const DRAWER_COOKIE = 'ramon_chat_drawer';
    /**
     * Lista explícita, não prefixo `chat.`: outra extensão pode usar o prefixo.
     */
    private const ROUTES = [
        'chat.index',
        'chat.channel',
        'chat.thread',
        'chat.browse',
        'chat.browse.filter',
        'chat.threads',
        'chat.search',
        'chat.bookmarks',
        'chat.flags',
    ];

    /**
     * Rotas que mostram uma conversa; só elas pagam as consultas de mensagens.
     * As de seção preenchem o painel sozinhas (ChatPage::mainPane).
     */
    private const CHANNEL_ROUTES = [
        'chat.index',
        'chat.channel',
        'chat.thread',
    ];

    /** Igual ao page[limit] de ChatState.loadChannels(). */
    private const CHANNEL_LIMIT = 50;

    /** Igual ao PAGE_SIZE do ChatState. */
    private const MESSAGE_LIMIT = 50;

    /**
     * Chunk lazy da UI, com o mesmo nome usado por `loadChatUi()` no cliente.
     */
    private const UI_CHUNK = 'ramon-chat/forum/chatUi.js';

    public function __construct(
        protected Client $api,
        protected FilesystemFactory $filesystem,
        protected VersionerInterface $versioner
    ) {
    }

    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $route = (string) $request->getAttribute('routeName');
        $onChatRoute = in_array($route, self::ROUTES, true);
        $drawerChannel = $this->drawerChannel($request);

        if (! $onChatRoute && $drawerChannel === null) {
            return;
        }

        $actor = RequestUtil::getActor($request);

        // O RequireChatAccess já barra isso nas rotas, mas este callback é do
        // frontend inteiro: uma rota nova sem o guard não pode vazar canais.
        if (! $actor->exists || ! $actor->can('useChat')) {
            return;
        }

        // O forum.js só pede o chunk depois do boot; o preload baixa os dois em paralelo.
        $uiChunk = $this->uiChunkUrl();

        if ($uiChunk !== null) {
            $document->preloads[] = ['href' => $uiChunk, 'as' => 'script'];
        }

        $channels = $this->fetch($request, '/chat-channels', [
            'filter' => ['following' => true],
            'sort' => '-lastMessageAt',
            'page' => ['limit' => self::CHANNEL_LIMIT],
        ]);

        // Sem a lista nada mais serve ao cliente; deixa o caminho normal rodar.
        if ($channels === null) {
            return;
        }

        $payload = ['channels' => $channels];

        $drafts = $this->fetch($request, '/chat/drafts', []);

        if ($drafts !== null) {
            $payload['drafts'] = $drafts;
        }

        $channelId = null;

        if (in_array($route, self::CHANNEL_ROUTES, true)) {
            $channelId = $this->activeChannelId($request, $channels, $drawerChannel);
        } elseif (! $onChatRoute && $drawerChannel > 0) {
            $channelId = $drawerChannel;
        }

        if ($channelId !== null) {
            // O cliente não consegue deduzir: em /chat sem seleção, o canal é
            // decidido aqui.
            $payload['channelId'] = $channelId;

            $messages = $this->fetch($request, '/chat-messages', [
                'filter' => ['channel' => $channelId],
                'sort' => '-id',
                'page' => ['limit' => self::MESSAGE_LIMIT],
            ]);

            if ($messages !== null) {
                $payload['messages'] = $messages;
            }

            $pinned = $this->fetch($request, '/chat-messages', [
                'filter' => [
                    'channel' => $channelId,
                    'pinned' => true,
                    'includeThreadReplies' => true,
                ],
                'sort' => '-pinnedAt',
                'page' => ['limit' => 1],
            ]);

            if ($pinned !== null) {
                $payload['pinned'] = $pinned;
            }
        }

        $threadId = $onChatRoute ? $this->threadId($request) : null;

        if ($channelId !== null && $threadId !== null) {
            $payload['threadId'] = $threadId;

            $replies = $this->fetch($request, '/chat-messages', [
                'filter' => ['thread' => $threadId],
                'sort' => '-id',
                'page' => ['limit' => self::MESSAGE_LIMIT],
            ]);

            if ($replies !== null) {
                $payload['threadMessages'] = $replies;
            }
        }

        $document->payload['ramonChat'] = $payload;
    }

    /**
     * A conversa que a página vai mostrar, como em ChatPage::boot: o id da rota,
     * o do cookie do drawer ou o primeiro da lista. Em /chat sem cookie o
     * primeiro canal é um palpite; errar só custa o carregamento assíncrono.
     *
     * @param array<string, mixed> $channels
     */
    private function activeChannelId(ServerRequestInterface $request, array $channels, ?int $remembered): ?int
    {
        $id = $this->routeParameter($request, 'id');

        if ($id !== null) {
            return $id;
        }

        if ($remembered !== null && $remembered > 0) {
            return $remembered;
        }

        $first = $channels['data'][0]['id'] ?? null;

        return is_numeric($first) && (int) $first > 0 ? (int) $first : null;
    }

    /**
     * URL idêntica à de ExportRegistry#chunkUrl() no cliente, com a revisão;
     * qualquer diferença faz o navegador baixar o chunk duas vezes. No Windows o
     * manifest usa barra invertida, tentada só como fallback.
     */
    private function uiChunkUrl(): ?string
    {
        $assets = $this->filesystem->disk('flarum-assets');

        if (! $assets instanceof Cloud) {
            return null;
        }

        $url = $assets->url('js').'/'.self::UI_CHUNK;
        $key = ltrim(Str::replaceFirst(rtrim($assets->url(''), '/'), '', $url), '/');
        $revision = $this->versioner->getRevision($key)
            ?? $this->versioner->getRevision(str_replace('/', DIRECTORY_SEPARATOR, $key));

        return $revision ? $url.'?v='.$revision : $url;
    }

    private function threadId(ServerRequestInterface $request): ?int
    {
        return $this->routeParameter($request, 'threadId');
    }

    /**
     * Id do cookie do drawer, ou null. Escrito pelo cliente: só aceita dígitos, e
     * o id ainda passa pela API como o ator.
     */
    private function drawerChannel(ServerRequestInterface $request): ?int
    {
        $value = $request->getCookieParams()[self::DRAWER_COOKIE] ?? null;

        if (! is_string($value) || ! preg_match('/^\d{1,10}$/', $value)) {
            return null;
        }

        return (int) $value;
    }

    private function routeParameter(ServerRequestInterface $request, string $name): ?int
    {
        $parameters = $request->getAttribute('routeParameters');
        $value = is_array($parameters) ? ($parameters[$name] ?? null) : null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Chamada à API como o visitante: `withParentRequest` aplica as mesmas
     * policies e scopes de uma requisição HTTP. Falha vira null; o preload é
     * opcional e não pode derrubar a página.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetch(ServerRequestInterface $request, string $path, array $params): ?array
    {
        try {
            $body = (string) $this->api
                ->withoutErrorHandling()
                ->withParentRequest($request)
                ->withQueryParams($params)
                ->get($path)
                ->getBody();

            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }
}
