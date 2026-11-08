<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_barber_can_upload_and_replace_a_profile_photo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'barber']);
        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => UploadedFile::fake()->image('photo.jpg'),
        ])->assertSessionHasNoErrors();
        $oldPath = $user->fresh()->photo_path;
        Storage::disk('public')->assertExists($oldPath);
        $this->get(route('barber.photo', $user))->assertOk();

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => UploadedFile::fake()->image('new.png'),
        ])->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($user->fresh()->photo_path);
    }

    public function test_invalid_profile_photo_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'barber']);
        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => UploadedFile::fake()->create('file.txt', 10, 'text/plain'),
        ])->assertSessionHasErrors('photo');
    }

    public function test_non_barber_cannot_upload_a_profile_photo(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['role' => 'client']);
        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => UploadedFile::fake()->image('photo.jpg'),
        ])->assertSessionHasErrors('photo');
        $this->assertNull($user->fresh()->photo_path);
    }

    public function test_photo_larger_than_two_megabytes_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'barber']);
        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'photo' => UploadedFile::fake()->image('photo.jpg')->size(2049),
        ])->assertSessionHasErrors('photo');
    }

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('home'));

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->fresh());
    }
}
