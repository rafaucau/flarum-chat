<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\User\User;
use WeakMap;

/**
 * Memoiza "este ator vê a linha N" durante uma request. A chave é a instância do
 * User num WeakMap, não o id: um worker de fila serve vários atores no mesmo
 * processo, e a entrada morre junto com o objeto.
 */
class VisibilityCache
{
    /**
     * ArrayObject, não array: os resolvers se aninham (thread pergunta pelo canal
     * dentro do próprio `remember()`), e um array copiado perderia a gravação
     * interna.
     *
     * @var WeakMap<User, \ArrayObject<string, bool>>
     */
    private WeakMap $entries;

    public function __construct()
    {
        $this->entries = new WeakMap();
    }

    /**
     * Resposta memoizada para `$type:$id`; `$resolve` roda uma vez só.
     */
    public function remember(User $actor, string $type, int|string $id, callable $resolve): bool
    {
        $key = $type.':'.$id;
        $forActor = $this->entries[$actor] ??= new \ArrayObject();

        if ($forActor->offsetExists($key)) {
            return $forActor[$key];
        }

        return $forActor[$key] = (bool) $resolve();
    }

    /**
     * Para processos longos que reaproveitam o container entre jobs, onde um User
     * pode sobreviver à unidade de trabalho que o carregou.
     */
    public function flush(): void
    {
        $this->entries = new WeakMap();
    }
}
