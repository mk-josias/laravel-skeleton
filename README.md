# Laravel skeleton

A starting point for a Laravel project: a Laravel application set up with
[laravel-distributable-modules](https://github.com/mk-josias/laravel-distributable-modules). It comes with three example
modules, `iam`, `analytics` and `notifications`, each with its own database. You can read them to see how a module
is written, then replace them with your own.

```bash
composer create-project mk-josias/laravel-skeleton my-app
```

Requires PHP 8.4+, and Redis for the event stream.

`create-project` asks two questions, through `php artisan skeleton:setup`:

| Question | Default | What it changes |
|---|---|---|
| Which example modules do you keep? | all | removes `analytics` or `notifications`: its folders, its declaration, its tests, its `auth.permissions` entry and its Docker service. `iam` always stays: authentication, permissions and the user copies rely on it. |
| How do the modules validate a token? | `jwt` | `AUTH_TOKEN_VALIDATION_STRATEGY` in `.env`, and a random `AUTH_GATEWAY_SECRET` for `gateway`. See [Choosing a strategy](#choosing-a-strategy). |

Without a terminal (`--no-interaction`), it keeps everything and `jwt`. It runs once: it then
deletes itself and its line in `composer.json`, so it can never remove a module that has become
yours.

## Running it

```bash
cp .env.example .env
php artisan key:generate
php artisan auth:jwt-keys     # the RSA pair iam signs tokens with, in storage/jwt-*.key
php artisan migrate           # migrates each module's database
php artisan iam:sync-permissions --prune   # the permissions listed in auth.permissions, see Permissions
php artisan serve
```

`composer create-project` runs every step but `serve` itself. The modules use sqlite files by
default (`database/iam.sqlite`, `database/analytics.sqlite`, `database/notifications.sqlite`).
`migrate` creates them.

Register a user in `iam`:

```bash
curl -X POST http://127.0.0.1:8000/iam/api/v1/users \
    -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d '{"name":"<name>","email":"<email>","password":"<password, 8 characters or more>"}'
```

```json
{"success":true,"data":{"id":1,"name":"<name>","token":"<token>"}}
```

The token lasts an hour (`AUTH_JWT_TTL`). Ask for a new one with the same email and password:

```bash
curl -X POST http://127.0.0.1:8000/iam/api/v1/tokens \
    -H 'Accept: application/json' -H 'Content-Type: application/json' \
    -d '{"email":"<email>","password":"<password>"}'
```

Both routes accept 6 requests a minute. A wrong password and an unknown email get the same 401.

`iam` announces the registration with an event. Start a consumer per module to handle it, each
in its own terminal:

```bash
php artisan stream:consume --module=analytics
php artisan stream:consume --module=notifications
```

`analytics` now has a signup and `notifications` a welcome message, and each keeps its own copy of
the user. Read them with the token `iam` returned:

```bash
curl http://127.0.0.1:8000/analytics/api/v1/signups \
    -H 'Accept: application/json' -H 'Authorization: Bearer <token>'
curl http://127.0.0.1:8000/notifications/api/v1/notifications \
    -H 'Accept: application/json' -H 'Authorization: Bearer <token>'
```

`php artisan analytics:compute` builds the hourly datasets (the scheduler runs it every hour), and
`GET /analytics/api/v1/datasets?group=day` reads them.

## What is in the skeleton

```
config/modules.php              declares the modules, and where they run when they run elsewhere;
                                calls and events keep laravel-microservices' defaults; each module
                                declares its handlers in $handlers of its service provider
apps/
├── Iam/              owns the users
├── Analytics/        records signups, computes datasets, keeps a copy of the users
└── Notifications/    a personal inbox, fed by events, keeps a copy of the users
foundation/
├── FoundationServiceProvider.php
├── Common/           base classes every module uses (see below)
└── Iam/              what iam shares with the other modules
```

### The `iam` module

| File | What it does |
|---|---|
| `app/Models/User.php` | The user, stored in the `iam_users` table. The `name` field is copied to the modules that keep a copy. |
| `app/Actions/RegisterUser.php` | Creates the user and emits `iam.user.registered` in the same transaction. |
| `app/Actions/IssueToken.php` | Issues the user's token: a JWT under the `jwt` strategy, an opaque token otherwise. |
| `app/Http/Controllers/UserController.php` | `POST /iam/api/v1/users` and `GET /iam/api/v1/me`. |
| `app/Http/Controllers/TokenController.php` | `POST /iam/api/v1/tokens` checks the email and password and issues a new token. |
| `app/Services/IamService.php` | Answers the `IamService` contract with iam's own data. Declared in `IamServiceProvider::$services`, it serves every caller, in this process or another. |
| `config/database.php` | The `iam` connection, and the `owner` role that migrates it. |
| `config/auth.php` | Names `User` as the authenticated user of iam's routes. |

### The foundation

`foundation/Iam/` holds what the other modules are allowed to use from `iam`:

| File | What it does |
|---|---|
| `Contracts/IamService.php` | The contract: `findUser()` and `findUserByToken()`. |
| `Services/IamRpcService.php` | Every module calls `iam` through it: in this process when `iam` runs here, over HTTP otherwise. |
| `Auth/JwtTokens.php`, `Auth/RpcTokens.php`, `Auth/GatewayTokens.php` | The three ways to turn a token into a user id (see [Authentication](#authentication)). `RpcTokens` keeps what iam answered as the identity's claims. |
| `Auth/RpcPrincipals.php` | The request's user as iam answers `findUser()`, for a module that keeps no copy. |
| `Events/IamEvent.php`, `Events/UserRegisteredPayload.php` | The names of the events iam publishes, and the typed payload of each: iam builds it, a consumer reads it with `UserRegisteredPayload::from($payload)`. The payload class also declares its versions (`version()`, `upcast()`), so a handler only ever receives the current shape. |
| `Shadows/UserShadow.php` | The copy of iam's users, used as it is by every module that lists it in `$shadows`, and their authenticated user (`auth.principal`). |
| `database/shadows/` | The migration that creates the copy's table in the module that keeps it. |

`foundation/FoundationServiceProvider.php` maps `IamService` to `IamRpcService`. The package then
runs iam's implementation in iam's context, so `Apps\Iam\Services\IamService` queries iam's
database whichever module calls it.

### The `analytics` module

| File | What it does |
|---|---|
| `app/Handlers/RecordSignup.php` | Handles `iam.user.registered` and stores a signup. |
| `app/Providers/AnalyticsServiceProvider.php` | `$shadows = [UserShadow::class]`: analytics keeps iam's users in its own `iam_users` table. |
| `app/Http/Controllers/SignupController.php` | `GET /analytics/api/v1/signups` lists the signups. `GET /analytics/api/v1/users/{id}` asks `iam` through the contract. |
| `app/Http/Resources/SignupResource.php` | Adds the user to each signup with `UserResource`, read from the copy: no call to `iam` per row. |
| `app/Rules/ExistingUser.php` | A `ReferenceRule` that asks `iam` through the contract. `?user_id=` on the signups list uses it. |
| `app/Console/ComputeDatasets.php` | `analytics:compute` rebuilds `analytics_datasets`, one row per hour, from the signups. `AnalyticsServiceProvider` schedules it hourly. |
| `app/Enums/DatasetMeasure.php`, `MeasureNature.php` | The measures, and how a period folds them: a flow (`signups_count`) adds up, a state (`users_total`) keeps the last value. |
| `app/Enums/DatasetGroup.php`, `app/Queries/ReadDatasets.php` | `GET /analytics/api/v1/datasets?group=hour\|day\|week\|month\|none&measures[]=…&from=…&to=…` folds the hourly rows onto the group. |
| `app/Providers/AnalyticsServiceProvider.php` | Declares the handler in `$handlers` and schedules `analytics:compute`. |

A reading never walks the signups: it filters and folds the pre-computed rows, so its cost
depends on the period, not on the volume.

A module reads another module's data in two ways, and the skeleton shows both:

| | Through the contract (`ExistingUser`) | From the copy (`SignupResource`) |
|---|---|---|
| Cost | one call per value, an HTTP call when `iam` runs elsewhere | a local query, joins included |
| Freshness | always current | current once the consumer has handled the events |
| Use it for | checking one value, reading one record | lists, filters, sorts |

### The `notifications` module

| File | What it does |
|---|---|
| `app/Handlers/SendWelcome.php` | Handles `iam.user.registered` and stores a welcome for the new user, once however many times the event arrives. |
| `app/Models/Notification.php` | What one person was told, in `notifications_inbox`. It is addressed to its recipient, so every query scopes on the authenticated user: there is no policy to write. |
| `app/Enums/NotificationType.php` | What a notification is about; it renders the title from `lang/en/messages.php` and the recipient's copy. |
| `app/Http/Controllers/NotificationController.php` | `GET /notifications/api/v1/notifications?filter[unread]=1&sort=-created_at&paginate=20` and `PATCH /notifications/api/v1/notifications/{id}/read`. Both scope on `principalIdOrFail()`: someone else's notification is a 404. |
| `app/Repositories/NotificationRepository.php` | The example of `EloquentRepository`: it declares the filters and sorts a request may use (any other is a 400), and the controller passes the recipient scope as `$constrain`. |
| `app/Providers/NotificationsServiceProvider.php` | `$shadows = [UserShadow::class]`: its copy of iam's users, in `iam_users`, the authenticated user of its routes. |
| `app/Observers/NotificationObserver.php` | Once the row is committed: pushes it live, then queues one job per channel its type names. |
| `app/Events/NotificationPushed.php` | The live push, on `private-user.{id}` (Reverb), in the shape the inbox returns; a client that was offline finds it in the inbox. |
| `app/Enums/Channel.php`, `app/Jobs/SendMail.php` | The channels beyond the inbox. `SendMail` asks iam for the address (`IamService::mailAddress()`), so the address never sits in a copy. A new channel (SMS, push) is a case and a job. |
| `routes/api.php` | Also `POST /notifications/api/v1/broadcasting/auth`: Echo joins `private-user.{id}` with the API token; only that user may join (`Foundation\Common\Broadcasting\PrivateUserChannel`). |

```
iam ─ user.registered ─► consumer ─► SendWelcome ─► notifications_inbox
                                                      ├─► NotificationPushed ─► Reverb :8080 ─► browser
                                                      └─► SendMail (queue) ─► worker ─► mail
```

### `foundation/Common`

`Foundation\Common\` holds the base classes every module uses. Like the rest of the foundation,
any module can use it. These classes are yours: the package doesn't depend on them, so you can
change or delete them.

How strongly each piece is meant is labelled:

| Label | Meaning |
|---|---|
| Mechanism | the modules rely on it working this way across processes; replace it only with something that keeps the same guarantee |
| Default | a sensible choice that works as shipped; swap it freely |
| Proposal | one way to structure the code, shown so you can judge it |
| Taste | a style choice, nothing depends on it |

| Path | What it does | Label |
|---|---|---|
| `Http/Controller`, `ApiRequest`, `Resource`, `ApiErrorCode` | Base controller and request, and the response format: `{success, data}` or `{success, code, message}`. | Taste |
| `Auth/Authenticate`, `TokenValidator`, `PrincipalResolver`, `PermissionSource`, `Identity`, `Principal` | Token authentication for module routes, in three replaceable steps: see [Authentication](#authentication). | Mechanism |
| `Auth/LocalPrincipals`, `ClaimsPrincipals`, `ClaimsPermissions`, `IsPrincipal`, `HasPrincipal` | The shipped answers to those steps, and the traits a user model and a controller use. | Default |
| `Policies/Policy` | Checks a permission against the `PermissionSource`. | Mechanism |
| `Contracts/Action`, `Operation` | One class per use case. | Proposal |
| `Contracts/Repository`, `Database/EloquentRepository`, `Searchable` | Queries with filters, sorts and includes, and a single place for writes. | Proposal |
| `Data/Dto` | Typed input and event payloads, built on spatie/laravel-data. | Default |
| `Validation/ReferenceRule` | A field holding the id of another module's record: checks it exists through that module's contract, then runs the constraints a subclass adds to `$checks`. | Mechanism |
| `Exceptions/DomainException`, `IntegrityException` | Business errors (400) and system errors (500). | Taste |

`bootstrap/app.php` renders the exceptions that implement `RendersApiEnvelope` in the response
format above.

## Authentication

A route is protected with the `Foundation\Common\Auth\Authenticate` middleware:

```php
Route::get('me', [UserController::class, 'me'])->middleware(Authenticate::class);
```

Three questions are answered in turn, each by an interface whose class `config/auth.php` names:

```
request ─► TokenValidator ─► Identity ─► PrincipalResolver ─► Principal ─► PermissionSource ─► Policy
           who is it?        id, claims   where is the user?               what may it do?
```

| Interface | Config key | Shipped | To add your own |
|---|---|---|---|
| `TokenValidator` | `auth.token_validation.strategy`, among `strategies` | `jwt`, `rpc`, `gateway` (below) | add a line to `strategies`; a `header` key in the strategy's config makes it read that header instead of the bearer token |
| `PrincipalResolver` | `auth.principal_resolver` | `LocalPrincipals`: the user's row in the running module's own database, through `auth.principal`: `UserShadow` in the modules that keep it, `User` in iam, which sets it in its `config/auth.php`. `ClaimsPrincipals`: the user is built from the token, no row (with `rpc`, from what iam answered). `RpcPrincipals`: the user iam returns for the id, whatever the strategy | name your class |
| `PermissionSource` | `auth.permission_source` | `IamPermissions`: `IamService::grants()`. `ClaimsPermissions`: the `permissions` claim of the token | name your class |

The three strategies, picked by `AUTH_TOKEN_VALIDATION_STRATEGY`:

| Strategy | The token | How the module checks it | Calls iam |
|---|---|---|---|
| `jwt` (default) | a JWT iam signs at registration | with the issuer's public key, `AUTH_JWT_PUBLIC_KEY`; iam signs with `AUTH_JWT_PRIVATE_KEY` | no |
| `rpc` | an opaque token, iam stores its hash | `IamService::findUserByToken()` | yes |
| `gateway` | `X-Identity: {id}.{exp}.{hmac}`, set by a gateway that already authenticated the client | the HMAC, with `AUTH_GATEWAY_SECRET` | no |

A token is refused (401, Laravel's `AuthenticationException`) when it proves nothing. With
`LocalPrincipals` it is also refused while the module has no copy of the user: a user who has just
registered reaches `analytics` once it has consumed `iam.user.registered`.

In a controller that extends `Foundation\Common\Http\Controller`, `$this->principalId()` returns the id of
the authenticated user.

### Choosing a strategy

Every process reads one strategy, and every process of an application must read the same one:
iam issues its tokens for it.

| | `jwt` | `rpc` | `gateway` |
|---|---|---|---|
| Token iam issues | a JWT, `sub` = the user id, valid `AUTH_JWT_TTL` seconds | an opaque token; iam stores its hash, one per user | a JWT, as under `jwt`: the gateway verifies it |
| Cost per request | a signature check, no call | one RPC call to iam (`findUserByToken` is not cached) | an HMAC check, no call |
| Revoking a token | not possible before it expires | issuing a new one (`POST /iam/api/v1/tokens`) replaces it at once | up to the gateway |
| Who is trusted | whoever holds iam's private key | iam | the gateway: anyone who can reach a module directly with a valid `X-Identity` is that user |
| Settings | `AUTH_JWT_PUBLIC_KEY` everywhere, `AUTH_JWT_PRIVATE_KEY` on iam | none | `AUTH_GATEWAY_SECRET` everywhere, the JWT keys on iam and the gateway |

### Behind a gateway

A gateway in front of the modules, in any language, works with the `gateway` strategy:

```
client ── Bearer <JWT from iam> ──► gateway ── X-Identity: {id}.{exp}.{hmac} ──► module routes
                                     │ verifies the JWT with iam's public key
                                     │ signs hex(hmac_sha256(AUTH_GATEWAY_SECRET, "{id}.{exp}"))
```

The gateway forwards `POST /iam/api/v1/users` and `/tokens` as they are, and adds `X-Identity` to
every other request. It must also:

- drop any `X-Identity` the client sent;
- never forward `/*/rpc/*`: those routes accept any caller holding the RPC secret;
- be the only way in: the modules' ports stay private.

A proxy (Traefik, nginx, Envoy) can be that gateway without any code of its own: its forward-auth
asks iam, and `GET /iam/api/v1/identity` answers 204 with the `X-Identity` header for a valid JWT,
401 otherwise. With Traefik, for instance:

```yaml
# the labels of the module containers, behind Traefik
traefik.http.middlewares.identity.forwardauth.address: "http://iam.svc:8000/iam/api/v1/identity"
traefik.http.middlewares.identity.forwardauth.authResponseHeaders: "X-Identity"   # replaces the client's
traefik.http.routers.analytics.rule: "PathPrefix(`/analytics/api`)"                # /analytics/rpc stays out
traefik.http.routers.analytics.middlewares: "identity"
```

This configuration hasn't been run against a Traefik instance; the route it calls is tested in
`apps/Iam/tests/Feature/TokensTest.php`. With nginx, `auth_request` and `auth_request_set` do the same.

To let the gateway emit events or call the modules itself, see "Services in other languages" in
the package's README.

### An identity provider outside the application

The modules can be the core of a larger system whose users are managed elsewhere (a Node service,
Keycloak, Auth0). Nothing of iam is needed then: the token says who the user is and what it may do.

```dotenv
AUTH_TOKEN_VALIDATION_STRATEGY=jwt
AUTH_JWT_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----…"            # the provider's RS256 public key
AUTH_PRINCIPAL_RESOLVER=Foundation\Common\Auth\ClaimsPrincipals     # no quotes: a quoted backslash doesn't parse
AUTH_PERMISSION_SOURCE=Foundation\Common\Auth\ClaimsPermissions
```

| The provider's token | What the modules read |
|---|---|
| `sub` | the user's id, an integer or a string (`usr_7f3a`) |
| `permissions` (`auth.permissions_claim`) | the list the policies check, such as `analytics.datasets.read` |
| any other claim | the user is a `ClaimsPrincipal`: `$principal->claim('email')` |

A provider that signs differently, or an API key, is a `TokenValidator` of your own.
`apps/Analytics/tests/Feature/ExternalIdentityTest.php` runs both cases. To keep the users' names
locally all the same, the provider can feed the modules' copies: see "Being the source of a copy"
in the package's `docs/other-languages.md`.

### Without authentication

The package has no authentication of its own: all of it lives in this skeleton, in
`foundation/Common/Auth`, `foundation/Common/Policies` and `foundation/Iam/Auth`. These use them:

| File | What to remove |
|---|---|
| `foundation/FoundationServiceProvider.php` | the three bindings of `register()` |
| `apps/*/routes/api.php` | the `Authenticate` middleware |
| `foundation/Common/Http/Controller.php`, `ApiRequest.php` | `use HasPrincipal` |
| `apps/Iam/app/Models/User.php`, `foundation/Iam/Shadows/UserShadow.php` | `IsPrincipal` and `implements Principal` |
| `apps/Analytics/app/Policies/DatasetPolicy.php` | the policy, and `authorize()` in `DatasetController` |
| `tests/ModuleTestCase.php`, `apps/*/tests`, `tests/Feature/ModulesTest.php` | the tests that send a token |

## Permissions

Roles and permissions belong to `iam`, which keeps them with `spatie/laravel-permission` in its
`iam_*` tables. Every other module asks for them through `IamService::grants($userId)`. That call
goes through RPC and the answer is cached.

- A module declares its permissions as an enum in the foundation, for example
  `Foundation\Analytics\Enums\AnalyticsPermission`, and lists it in `auth.permissions`.
  `php artisan iam:sync-permissions` then creates the declared permissions and drops every
  cached grant. With `--prune` it also deletes the permissions no enum declares, and every role's
  and user's hold on them: an enum missing from the list loses its grants. `composer setup`,
  `create-project` and the container whose `SYNC_PERMISSIONS` is `true` (iam's) run it with `--prune`.
- A policy extends `Foundation\Common\Policies\Policy` and checks
  `$this->allows($user, AnalyticsPermission::ReadDatasets)`. A controller calls
  `$this->authorize('viewAny', Dataset::class)`, which answers 403 when the user lacks the permission.
- Change permissions only through iam's actions, never by calling spatie directly, so the cache
  stays right. Each action clears the cache once its transaction commits:

  | Action | Clears |
  |---|---|
  | `GrantRole::grant()` / `revoke()` | that user's cached grants |
  | `SetRolePermissions::execute()` | every user's cached grants |

## Running a module in its own process

Every module runs in one process by default. To move `iam` to its own process, start two copies
of the same code with different settings:

```dotenv
# the iam process
RUN_MODULES=iam

# the analytics process
RUN_MODULES=analytics
IAM_HOST=http://iam.internal:8000
```

`config/modules.php` declares every module in every process, so `analytics` still listens to
iam's events and calls iam over HTTP through `IamRpcService`. Both processes must share `APP_KEY`,
or the same `MICROSERVICES_RPC_SECRET`.

When you build an image for one module, delete the folders of the others before
`composer dump-autoload`:

```bash
RUN_MODULES=analytics php artisan modules:purge --force
```

Starting that image with `RUN_MODULES=iam` then fails at boot, because iam's folder is gone.

## Docker

The skeleton ships both setups, on Postgres (one database per module) and Redis (the event
stream), served by Octane with Swoole:

```bash
docker compose -f docker-compose.mono.yml up -d   # every module in one container, on :8000
docker compose up -d                              # one container per module: iam on :8001, analytics on :8002, notifications on :8003
```

`APP_PORT`, `IAM_PORT`, `ANALYTICS_PORT` and `NOTIFICATIONS_PORT` change the published ports. In the second setup, each
image is built with `--build-arg RUN_MODULES=<module>`, so it holds only its module: the
Dockerfile runs `modules:purge` before `composer dump-autoload`. A container migrates only
the database of the module it runs, so two containers never migrate the same one. Both compose files validate tokens with the `rpc` strategy: they ship no JWT
keys. With `jwt`, give every container `AUTH_JWT_PUBLIC_KEY`, and iam `AUTH_JWT_PRIVATE_KEY`.

| File | What it does |
|---|---|
| `docker/Dockerfile` | Installs the dependencies, purges the modules the image doesn't run and the packages only they required, then builds the PHP and Swoole runtime. |
| `docker/entrypoint.sh` | Runs `optimize`, migrates (on the `http` role only), writes one consumer per module of `WITH_CONSUMERS`, then starts supervisord. |
| `docker/supervisord.conf` | The roles a container can take, each switched on by a variable. |
| `docker/postgres/` | A Postgres image that creates the databases of `MODULE_DATABASES`, owned by `distributable`, written by `distributable_app`. |

| Role | Variable | Default | Run at most |
|---|---|---|---|
| `http` (Octane) | `WITH_HTTP` | `true` | as many as you need |
| `worker` (`queue:work`) | `WITH_WORKER` | `false` | as many as you need |
| `reverb` (WebSocket, port 8080) | `WITH_REVERB` | `false` | one per container that pushes: it pushes to its own, on localhost |
| `publisher` (`stream:publish`) | `WITH_PUBLISHER` | `false` | one per module set: two would publish the outbox out of order |
| `scheduler` (supercronic) | `WITH_SCHEDULER` | `false` | one per module set: two would run each task twice |
| `consumer-<module>` | `WITH_CONSUMERS=iam,analytics,notifications` | none | one per module: two would break the order it reads in |

The values in the compose files are for development. In production, set real secrets.

## Tests

```bash
php artisan test
```

`tests/TestCase.php` gives each module an empty sqlite file and runs `migrate` before every
test.

A module is tested alone, the way it runs once it has its own process. Its tests live in
`apps/{Module}/tests/` and extend `Tests\ModuleTestCase`, which runs that module only
(`RUN_MODULES`) and stands in for iam:

```php
class InboxTest extends ModuleTestCase
{
    protected string $module = 'notifications';

    public function test_a_registered_user_finds_a_welcome(): void
    {
        $headers = $this->user(1, 'ada');                                        // its copy, and a token
        $this->receive('notifications', IamEvent::UserRegistered->value, ['id' => 1]);   // as iam would announce it

        $this->getJson('/notifications/api/v1/notifications', $headers)->assertJsonCount(1, 'data');
    }
}
```

| Helper | What it does |
|---|---|
| `user($id, $name, $permissions)` | writes the user's copy in the module's database, makes iam answer `$permissions` to `grants()`, and returns the `Authorization` header of a valid token |
| `receive($module, $name, $payload)` | hands the module an event as its emitter would have announced it (from the package) |

Because a module's tests name no other module's class, `Boundaries` holds for them too.

| Where | What it holds |
|---|---|
| `apps/{Module}/tests/` | the module alone; `phpunit.xml` lists them in the `Modules` suite |
| `tests/Feature/ModulesTest.php` | the one flow that crosses modules, every module in one process: registration, then what analytics makes of it |
| `tests/Feature/ArchitectureTest.php` | no module uses another module's classes (`Distributable\Testing\Boundaries`), and `modules:doctor` passes |

`php artisan make:test InvoiceTest --module=billing` writes a test in the module.

To write to a module's database in a test, use `inModuleOf`. The module is read from the class, so
the test names no module:

```php
$user = $this->inModuleOf(User::class, fn () => User::query()->create([...]));
```

`composer check` runs Pint, PHPStan and the tests.

## Adding code to a module

Laravel's `make:*` commands take `--module`:

```bash
php artisan modules:make billing --database          # a new module, declared in config/modules.php and composer.json
php artisan make:model Invoice -mf --module=billing          # apps/Billing/app/Models, its migration and its factory
php artisan make:controller InvoiceController --module=billing
```

`composer.json` lists each module and the foundation under `autoload.psr-4`. The application doesn't
need those entries: the package autoloads the modules itself. They let your IDE resolve the classes.

A library only one module uses goes in that module's `composer.json`, as `spatie/laravel-permission`
does in `apps/Iam/composer.json`. The root `composer.json` merges `apps/*/composer.json`
(`wikimedia/composer-merge-plugin`), so there is still one `composer.lock` and one `vendor/`:

```bash
composer require barryvdh/laravel-dompdf --working-dir=apps/Billing --no-update   # declares it in the module
composer update barryvdh/laravel-dompdf                                           # locks and installs it
```

What every module uses (`foundation/`) stays in the root file. An image built for one module leaves
out the packages only the other modules declared: the analytics image has no
`spatie/laravel-permission`.

## License

MIT.
