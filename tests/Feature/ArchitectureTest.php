<?php

namespace Tests\Feature;

use Distributable\Testing\Boundaries;
use Tests\TestCase;

class ArchitectureTest extends TestCase
{
    public function test_no_module_uses_another_module_and_every_module_can_run(): void
    {
        $this->assertSame([], $this->app->make(Boundaries::class)->violations());
        $this->artisan('modules:doctor')->assertSuccessful();
    }
}
