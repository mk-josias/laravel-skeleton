<?php

namespace Tests\Feature;

use Apps\Analytics\Models\Signup;
use Apps\Iam\Models\User;
use Foundation\Iam\Contracts\IamService;
use Foundation\Iam\Services\IamRpcService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ModulesTest extends TestCase
{
    public function test_registering_a_user_writes_to_the_iam_database_only(): void
    {
        $this->postJson('/iam/api/v1/users', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Ada')
            ->assertJsonStructure(['success', 'data' => ['id', 'name', 'token']]);

        $this->assertSame(1, DB::connection('iam')->table('iam_users')->count());
        $this->assertSame(0, DB::connection('analytics')->table('iam_users')->count());
    }

    public function test_analytics_records_the_signup_and_keeps_a_copy_of_the_user_once_it_consumes_the_events(): void
    {
        $id = $this->postJson('/iam/api/v1/users', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse'])->json('data.id');

        $this->assertSame(0, $this->inModuleOf(Signup::class, fn (): int => Signup::query()->count()));

        $this->artisan('stream:consume --module=analytics')->assertSuccessful();

        $signup = $this->inModuleOf(Signup::class, fn (): ?Signup => Signup::query()->with('user')->first());

        $this->assertSame($id, $signup?->user_id);
        $this->assertSame('Ada', $signup?->user?->name);
    }

    public function test_a_route_of_another_module_authenticates_with_a_token_issued_by_iam(): void
    {
        $token = $this->postJson('/iam/api/v1/users', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse'])->json('data.token');
        $this->artisan('stream:consume --module=analytics')->assertSuccessful();

        $this->getJson('/analytics/api/v1/signups')->assertUnauthorized();

        $this->getJson('/analytics/api/v1/signups', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.0.user.name', 'Ada');
    }

    public function test_a_valid_token_is_refused_until_the_module_holds_a_copy_of_the_user(): void
    {
        $token = $this->postJson('/iam/api/v1/users', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse'])->json('data.token');

        $this->getJson('/iam/api/v1/me', ['Authorization' => "Bearer {$token}"])->assertOk();
        $this->getJson('/analytics/api/v1/signups', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_a_validation_rule_checks_a_value_against_another_module_through_its_contract(): void
    {
        $response = $this->postJson('/iam/api/v1/users', ['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse']);
        $headers = ['Authorization' => 'Bearer '.$response->json('data.token')];
        $this->artisan('stream:consume --module=analytics')->assertSuccessful();

        $this->getJson('/analytics/api/v1/signups?user_id='.$response->json('data.id'), $headers)
            ->assertOk()
            ->assertJsonPath('data.0.user.name', 'Ada');

        $this->getJson('/analytics/api/v1/signups?user_id=999', $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id' => 'No user has this id.']);
    }

    public function test_a_module_reads_another_module_through_its_contract_in_this_process_on_iam_database(): void
    {
        Http::fake();
        $id = $this->inModuleOf(User::class, fn (): int => User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'correct-horse', 'api_token' => 'x'])->getKey());

        $this->assertInstanceOf(IamRpcService::class, $this->app->make(IamService::class));
        Http::assertNothingSent();
        $this->assertSame(
            ['id' => $id, 'name' => 'Ada'],
            $this->inModuleOf(Signup::class, fn (): ?array => $this->app->make(IamService::class)->findUser($id)),
        );
    }
}
