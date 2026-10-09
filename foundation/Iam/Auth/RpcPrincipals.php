<?php

declare(strict_types=1);

namespace Foundation\Iam\Auth;

use Foundation\Common\Auth\ClaimsPrincipal;
use Foundation\Common\Auth\Identity;
use Foundation\Common\Auth\Principal;
use Foundation\Common\Auth\PrincipalResolver;
use Foundation\Iam\Contracts\IamService;

/** The user as iam answers it, whatever proved the identity: no copy in the module, one call to iam (cached by its RpcService). */
final readonly class RpcPrincipals implements PrincipalResolver
{
    public function __construct(private IamService $iam) {}

    public function resolve(Identity $identity): ?Principal
    {
        $user = $this->iam->findUser((int) $identity->id);

        return $user === null ? null : new ClaimsPrincipal(new Identity($user['id'], $user));
    }
}
