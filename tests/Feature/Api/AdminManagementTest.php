<?php

namespace Tests\Feature\Api;

use App\Models\SongReport;
use App\Models\Track;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users_and_lock_and_unlock_an_account(): void
    {
        $admin = User::factory()->create();
        $user = User::factory()->create();
        $user->createToken('test-token');
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);

        $this->patchJson("/api/admin/users/{$user->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->patchJson("/api/admin/users/{$user->id}", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_regular_users_cannot_access_admin_endpoints(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_locked_accounts_cannot_use_authenticated_api_routes(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        Sanctum::actingAs($user);

        $this->getJson('/api/profile')->assertForbidden();
    }

    public function test_admin_cannot_lock_their_own_account(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$admin->id}", ['is_active' => false])
            ->assertUnprocessable();
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_can_deactivate_a_song_and_remove_its_audio(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $audioPath = "tracks/uploads/{$admin->id}/admin-song.mp3";
        Storage::disk('public')->put($audioPath, 'audio');
        $track = Track::create([
            'title' => 'Admin song',
            'artist' => 'Artist',
            'audio_path' => $audioPath,
            'uploaded_by' => $admin->id,
        ]);
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/tracks/{$track->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->getJson('/api/tracks')->assertJsonPath('meta.total', 0);
        $this->getJson("/api/tracks/{$track->id}")->assertNotFound();

        $this->deleteJson("/api/admin/tracks/{$track->id}")->assertOk();
        Storage::disk('public')->assertMissing($audioPath);
    }

    public function test_admin_can_review_a_song_report(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $reporter = User::factory()->create();
        $track = Track::create(['title' => 'Reported song', 'artist' => 'Artist']);
        $report = SongReport::create([
            'track_id' => $track->id,
            'user_id' => $reporter->id,
            'reason' => 'copyright',
            'details' => 'I own this recording.',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/reports')
            ->assertOk()
            ->assertJsonPath('data.0.track.title', 'Reported song')
            ->assertJsonPath('data.0.reporter.email', $reporter->email);

        $this->patchJson("/api/admin/reports/{$report->id}", ['status' => 'resolved'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.reviewer.id', $admin->id);
    }

    public function test_admin_promotion_command_promotes_an_existing_account_by_email(): void
    {
        $user = User::factory()->create(['email' => 'new-admin@example.com']);

        $this->artisan('users:promote-admin', ['email' => $user->email])
            ->expectsOutput('new-admin@example.com is now an administrator.')
            ->assertSuccessful();

        $this->assertSame('admin', $user->fresh()->role);
    }
}
