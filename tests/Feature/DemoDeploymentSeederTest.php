<?php

namespace Tests\Feature;

use App\Models\Track;
use App\Models\User;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoDeploymentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_catalog_seeds_metadata_without_audio_and_is_idempotent(): void
    {
        $this->seed(DemoCatalogSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $this->assertSame(3, Track::query()->count());
        $this->assertNull(Track::query()->firstOrFail()->audio_path);
    }

    public function test_production_seeding_does_not_create_an_admin_account(): void
    {
        config(['app.env' => 'production']);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(3, Track::query()->count());
        $this->assertSame(0, User::query()->count());
    }
}
