<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Storage;

/**
 * Falha ao gravar, ler ou remover um arquivo, ou store indisponível. A mensagem é
 * para o log, nunca para o cliente: pode trazer bucket ou path.
 */
class StorageFailed extends \RuntimeException
{
}
