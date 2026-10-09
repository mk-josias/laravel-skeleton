<?php

namespace Tests;

use Firebase\JWT\JWT;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** Runs one module alone, as its own process would: iam is elsewhere, and what it would answer is stated by the test. */
abstract class ModuleTestCase extends TestCase
{
    /** The only module this process runs. */
    protected string $module;

    /** @var array<int, list<string>> what iam answers to grants(), per user id */
    private array $grants = [];

    /** @var array<int, string> what iam answers to mailAddress(), per user id */
    protected array $mailAddresses = [];

    protected function setUp(): void
    {
        $this->setEnvironment(['RUN_MODULES' => $this->module, 'IAM_HOST' => 'http://iam.test']);

        parent::setUp();

        Http::fake([
            'iam.test/iam/rpc/grants' => fn (Request $request) => Http::response($this->grants[$request['arguments']['userId']] ?? []),
            'iam.test/iam/rpc/mailAddress' => fn (Request $request) => Http::response((string) json_encode($this->mailAddresses[$request['arguments']['userId']] ?? null)),
        ]);
    }

    protected function tearDown(): void
    {
        $this->setEnvironment(['RUN_MODULES' => null, 'IAM_HOST' => null]);

        parent::tearDown();
    }

    /**
     * A user the module knows: its copy is in the module's database, iam grants it $permissions.
     *
     * @param  list<string>  $permissions
     * @return array{Authorization: string} the headers of a request it sends
     */
    protected function user(int $id, string $name, array $permissions = []): array
    {
        $this->inModule($this->module, function () use ($id, $name): void {
            $copy = (string) config('auth.principal');
            $copy::sync($id, ['name' => $name]);
        });
        $this->grants[$id] = $permissions;

        $token = JWT::encode(['sub' => (string) $id, 'exp' => time() + 60], (string) config('auth.token_validation.jwt.private_key'), 'RS256');

        return ['Authorization' => "Bearer {$token}"];
    }

    /** @param array<string, string|null> $variables */
    protected function setEnvironment(array $variables): void
    {
        foreach ($variables as $name => $value) {
            if ($value === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv("{$name}={$value}");
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
        }
    }
}
