<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Api\Controller;

use Flarum\Foundation\AbstractImageValidator;

/**
 * Valida o avatar do bot. Tudo vem do AbstractImageValidator do core (tamanho
 * antes de decodificar, mime lido dos bytes); a subclasse existe só porque o nome
 * do validador aparece na mensagem de erro, e "logo" confundiria.
 */
class BotAvatarValidator extends AbstractImageValidator
{
}
