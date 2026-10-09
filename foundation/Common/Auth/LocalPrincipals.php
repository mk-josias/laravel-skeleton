<?php

declare(strict_types=1);

namespace Foundation\Common\Auth;

/** Loads the user from the database of the running module, through auth.principal: iam's copy, which iam itself replaces with its User. */
final readonly class LocalPrincipals implements PrincipalResolver
{
    public function resolve(Identity $identity): ?Principal
    {
        $model = config('auth.principal');

        if (! is_string($model)) {
            return null;
        }

        $principal = $model::query()->find($identity->id);

        return $principal instanceof Principal ? $principal : null;
    }
}
