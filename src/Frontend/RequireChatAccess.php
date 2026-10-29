<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Frontend;

use Flarum\Frontend\Document;
use Flarum\Http\Exception\RouteNotFoundException;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Content callback das rotas `/chat/*`: 404 para quem não pode usar o chat,
 * como as demais rotas restritas do Flarum. É só o portão externo; a API
 * continua checando cada canal.
 */
class RequireChatAccess
{
    public function __invoke(Document $document, ServerRequestInterface $request): void
    {
        $actor = RequestUtil::getActor($request);

        // `can('useChat')` e não `hasPermissionLike()`: este casa qualquer permissão
        // terminada no nome e ignora a GlobalPolicy, divergindo do `canUseChat` do cliente.
        if (! $actor->can('useChat')) {
            throw new RouteNotFoundException();
        }
    }
}
