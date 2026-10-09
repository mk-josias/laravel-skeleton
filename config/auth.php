<?php

use Foundation\Analytics\Enums\AnalyticsPermission;
use Foundation\Common\Auth\LocalPrincipals;
use Foundation\Iam\Auth\GatewayTokens;
use Foundation\Iam\Auth\IamPermissions;
use Foundation\Iam\Auth\JwtTokens;
use Foundation\Iam\Auth\RpcTokens;
use Foundation\Iam\Shadows\UserShadow;

return [

    // Authenticate sets the guard user itself: the provider never loads one, so it names no model.
    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => env('AUTH_MODEL'),
        ],
    ],

    // How a token proves an identity, see the README's Authentication section. A strategy is a TokenValidator: add yours here.
    'token_validation' => [
        'strategy' => env('AUTH_TOKEN_VALIDATION_STRATEGY', 'jwt'),
        'strategies' => [
            'jwt' => JwtTokens::class,
            'rpc' => RpcTokens::class,
            'gateway' => GatewayTokens::class,
        ],
        'jwt' => [
            // The PEM itself, or else the file `php artisan auth:jwt-keys` writes.
            'public_key' => env('AUTH_JWT_PUBLIC_KEY') ?: (is_file($public = storage_path('jwt-public.key')) ? file_get_contents($public) : null),
            'private_key' => env('AUTH_JWT_PRIVATE_KEY') ?: (is_file($private = storage_path('jwt-private.key')) ? file_get_contents($private) : null),
            'ttl' => (int) env('AUTH_JWT_TTL', 3600),
        ],
        'gateway' => [
            'header' => 'X-Identity',
            'secret' => env('AUTH_GATEWAY_SECRET'),
        ],
    ],

    // Where the user of a request comes from: LocalPrincipals reads the module's own database, ClaimsPrincipals only the token, RpcPrincipals asks iam.
    'principal_resolver' => env('AUTH_PRINCIPAL_RESOLVER', LocalPrincipals::class),

    // The model LocalPrincipals reads users from: iam's copy, kept by the modules that list it in $shadows; iam sets its User.
    'principal' => UserShadow::class,

    // Who says what a user may do: IamPermissions asks iam, ClaimsPermissions reads the claim named below in the token.
    'permission_source' => env('AUTH_PERMISSION_SOURCE', IamPermissions::class),
    'permissions_claim' => 'permissions',

    // The permission enums of every module, in the foundation so iam knows them wherever it runs: iam:sync-permissions creates them.
    'permissions' => [
        AnalyticsPermission::class,
    ],

];
