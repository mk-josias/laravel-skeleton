<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;

/** Run once by create-project: removes the example modules you don't keep, and sets the token strategy. */
final class SetupSkeleton extends Command
{
    protected $signature = 'skeleton:setup
        {--remove=* : An example module to remove: analytics, notifications}
        {--strategy= : jwt, rpc or gateway}';

    protected $description = 'Choose the example modules to keep and the token validation strategy';

    /**
     * iam stays: the other modules, authentication and permissions rely on it.
     *
     * @var array<string, array{tests: list<string>, lines: array<string, list<string>>}>
     */
    private const array REMOVABLE = [
        'analytics' => [
            'tests' => ['ModulesTest'],
            'lines' => ['config/auth.php' => [
                "use Foundation\\Analytics\\Enums\\AnalyticsPermission;\n",
                "        AnalyticsPermission::class,\n",
            ]],
        ],
        'notifications' => [
            'tests' => [],
            'lines' => [],
        ],
    ];

    public function handle(): int
    {
        $present = array_values(array_filter(array_keys(self::REMOVABLE), fn (string $module): bool => array_key_exists($module, (array) config('modules.declared'))));

        /** @var list<string> $remove */
        $remove = $this->option('remove') !== [] ? (array) $this->option('remove') : ($present === [] ? [] : array_values(array_diff($present, multiselect(
            label: 'Which example modules do you keep? iam stays: the others rely on it.',
            options: $present,
            default: $present,
        ))));

        foreach (array_intersect($remove, $present) as $module) {
            $this->remove($module);
        }

        $strategy = (string) ($this->option('strategy') ?? select(
            label: 'How do the modules validate a token? See the README, "Choosing a strategy".',
            options: ['jwt' => 'jwt: a JWT checked with iam\'s public key', 'rpc' => 'rpc: an opaque token iam looks up', 'gateway' => 'gateway: an X-Identity header set by your gateway'],
            default: 'jwt',
        ));

        $this->setEnv('AUTH_TOKEN_VALIDATION_STRATEGY', $strategy);

        if ($strategy === 'gateway' && (string) config('auth.token_validation.gateway.secret') === '') {
            $this->setEnv('AUTH_GATEWAY_SECRET', Str::random(64));
        }

        $this->components->info("Token strategy: {$strategy}.");
        $this->removeItself();

        return self::SUCCESS;
    }

    /** Run again later, it would delete a module that has since become the application's own code. */
    private function removeItself(): void
    {
        $this->strip('composer.json', [
            "            \"@php artisan skeleton:setup --ansi\",\n",
            "            \"App\\\\\": \"app/\",\n",
        ]);
        $this->strip('phpstan.neon.dist', ["        - app\n"]);
        $this->strip('phpunit.xml', ["            <directory>app</directory>\n"]);
        @unlink(__FILE__);
        @rmdir(dirname(__FILE__));
        @rmdir(dirname(__FILE__, 2));
        @rmdir(dirname(__FILE__, 3));
    }

    private function remove(string $module): void
    {
        $this->call('modules:delete', ['name' => $module, '--force' => true]);

        foreach (self::REMOVABLE[$module]['tests'] as $test) {
            @unlink(base_path("tests/Feature/{$test}.php"));
        }

        foreach (self::REMOVABLE[$module]['lines'] as $file => $lines) {
            $this->strip($file, $lines);
        }

        $this->removeFromCompose($module);
        $this->components->info("Example module [{$module}] removed.");
    }

    /** Drops the module's service and its names from the lists the containers read. */
    private function removeFromCompose(string $module): void
    {
        foreach (['docker-compose.yml', 'docker-compose.mono.yml'] as $file) {
            $contents = (string) file_get_contents(base_path($file));
            $contents = (string) preg_replace("/^  {$module}:\n(?:(?:    .*)?\n)*/m", '', $contents);
            $contents = (string) preg_replace_callback(
                '/^(\s+(?:WITH_CONSUMERS|MODULE_DATABASES): ")([^"]*)"/m',
                fn (array $match): string => $match[1].implode(str_contains($match[2], ',') ? ',' : ' ', array_values(array_diff(
                    (array) preg_split('/[ ,]/', $match[2]),
                    [$module, "app_{$module}"],
                ))).'"',
                $contents,
            );
            file_put_contents(base_path($file), $contents);
        }
    }

    /** @param list<string> $lines */
    private function strip(string $file, array $lines): void
    {
        file_put_contents(base_path($file), str_replace($lines, '', (string) file_get_contents(base_path($file))));
    }

    private function setEnv(string $key, string $value): void
    {
        $path = base_path('.env');
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        $line = "{$key}={$value}";

        $contents = preg_match("/^{$key}=.*$/m", $contents) === 1
            ? (string) preg_replace("/^{$key}=.*$/m", $line, $contents)
            : rtrim($contents, "\n")."\n{$line}\n";

        file_put_contents($path, $contents);
    }
}
