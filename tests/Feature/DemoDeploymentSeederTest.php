<?php

namespace Tests\Feature;

use App\Models\Track;
use App\Models\User;
use Database\Seeders\DemoAdminSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
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

    public function test_demo_admin_is_created_once_and_its_password_is_not_reset(): void
    {
        config([
            'demo.admin_email' => 'admin@example.test',
            'demo.admin_password' => 'first-secure-password',
        ]);

        $this->seed(DemoAdminSeeder::class);

        $admin = User::query()->where('email', 'admin@example.test')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check('first-secure-password', $admin->password));

        config(['demo.admin_password' => 'replacement-password']);
        $this->seed(DemoAdminSeeder::class);

        $admin->refresh();
        $this->assertTrue(Hash::check('first-secure-password', $admin->password));
    }

    public function test_demo_admin_rejects_a_password_shorter_than_sixteen_characters(): void
    {
        config([
            'demo.admin_email' => 'admin@example.test',
            'demo.admin_password' => 'too-short',
        ]);

        $this->expectException(RuntimeException::class);
        $this->seed(DemoAdminSeeder::class);
    }
}
