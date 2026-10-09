<?php

declare(strict_types=1);

namespace Foundation\Iam\Auth;

use Foundation\Common\Auth\Identity;
use Foundation\Common\Auth\TokenValidator;
use Foundation\Iam\Contracts\IamService;

/** The token is opaque: only iam knows whose it is, and what iam answers travels as the identity's claims. */
final readonly class RpcTokens implements TokenValidator
{
    public function __construct(private IamService $iam) {}

    public function validate(string $token): ?Identity
    {
        $user = $this->iam->findUserByToken($token);

        return $user === null ? null : new Identity($user['id'], $user);
    }
}
