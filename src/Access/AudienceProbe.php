<?php

/*
 * This file is part of ramon/chat.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Chat\Access;

use Flarum\Group\Group;
use Flarum\Group\Permission;
use Flarum\User\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Conta em memória com exatamente as permissões dadas, para perguntar se o leitor
 * menos privilegiado de um lugar veria algo. Copiar uma discussão para um canal só
 * é seguro se ele já a via. Id 0 e sem grupos: nada é "dele" e nunca é admin.
 * Nunca é salva.
 */
final class AudienceProbe extends User
{
    /**
     * @param  string[]  $extra  Permissões além das de visitantes e membros.
     */
    public static function memberWith(array $extra = []): self
    {
        $base = Permission::query()
            ->whereIn('group_id', [Group::GUEST_ID, Group::MEMBER_ID])
            ->pluck('permission')
            ->all();

        $probe = new self();
        $probe->id = 0;
        $probe->exists = true;
        $probe->is_email_confirmed = true;
        $probe->setRelation('groups', new Collection());

        // Preenchido para `getPermissions()` não derivar nada dos grupos.
        $probe->permissions = array_values(array_unique(array_merge($base, $extra)));

        return $probe;
    }

    public function save(array $options = []): bool
    {
        return false;
    }
}
