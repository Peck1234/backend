<?php

namespace Tests\Feature;

use App\Models\Nurse;
use App\Models\PasswordResetCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// Full HTTP-level coverage for /api/register and /api/login, running against
// an in-memory SQLite database (migrated fresh per test via RefreshDatabase).
// Unlike NurseCodeGeneratorTest (Unit), this exercises the real Nurse::create
// + NurseCodeGenerator::forId round trip and the validation rules together.
class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function register_creates_a_nurse_and_assigns_a_sequential_qr_code()
    {
        $response = $this->postJson('/api/register', [
            'full_name' => 'สมหญิง ใจดี',
            'username' => 'somying',
            'email' => 'somying@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'full_name' => 'สมหญิง ใจดี',
                'username' => 'somying',
                'email' => 'somying@example.com',
            ])
            ->assertJsonStructure(['nurse_id', 'full_name', 'username', 'email', 'qr_code_nurse', 'token']);

        $nurse = Nurse::first();
        $this->assertSame('NURSE-' . str_pad((string) $nurse->id, 3, '0', STR_PAD_LEFT), $nurse->qr_code_nurse);
        $this->assertTrue(Hash::check('secret123', $nurse->password));
    }

    /** @test */
    public function register_issues_a_usable_sanctum_token()
    {
        $response = $this->postJson('/api/register', [
            'full_name' => 'สมหญิง ใจดี',
            'username' => 'somying',
            'email' => 'somying@example.com',
            'password' => 'secret123',
        ]);

        $token = $response->json('token');
        $this->assertNotEmpty($token);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->putJson('/api/profile', [
                'full_name' => 'สมหญิง ใจดี',
                'username' => 'somying',
                'email' => 'somying@example.com',
            ])
            ->assertStatus(200);
    }

    /** @test */
    public function register_rejects_a_duplicate_username()
    {
        Nurse::create([
            'full_name' => 'Existing',
            'username' => 'somying',
            'email' => 'existing@example.com',
            'password' => Hash::make('whatever1'),
            'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->postJson('/api/register', [
            'full_name' => 'สมหญิง คนใหม่',
            'username' => 'somying',
            'email' => 'somying-new@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('username');
    }

    /** @test */
    public function register_rejects_a_duplicate_email()
    {
        Nurse::create([
            'full_name' => 'Existing',
            'username' => 'existing',
            'email' => 'somying@example.com',
            'password' => Hash::make('whatever1'),
            'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->postJson('/api/register', [
            'full_name' => 'สมหญิง คนใหม่',
            'username' => 'somying2',
            'email' => 'somying@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /** @test */
    public function register_rejects_a_username_with_disallowed_characters()
    {
        $response = $this->postJson('/api/register', [
            'full_name' => 'สมหญิง ใจดี',
            'username' => 'som ying!',
            'email' => 'somying@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('username');
    }

    /** @test */
    public function register_rejects_a_password_shorter_than_six_characters()
    {
        $response = $this->postJson('/api/register', [
            'full_name' => 'สมหญิง ใจดี',
            'username' => 'somying',
            'email' => 'somying@example.com',
            'password' => '123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /** @test */
    public function register_rejects_a_malformed_email()
    {
        $response = $this->postJson('/api/register', [
            'full_name' => 'สมหญิง ใจดี',
            'username' => 'somying',
            'email' => 'not-an-email',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /** @test */
    public function login_succeeds_with_correct_credentials()
    {
        Nurse::create([
            'full_name' => 'สมหญิง ใจดี',
            'username' => 'somying',
            'email' => 'somying@example.com',
            'password' => Hash::make('secret123'),
            'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'somying',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'username' => 'somying',
                'qr_code_nurse' => 'NURSE-001',
            ])
            ->assertJsonStructure(['token']);
    }

    /** @test */
    public function login_fails_with_the_wrong_password()
    {
        Nurse::create([
            'full_name' => 'สมหญิง ใจดี',
            'username' => 'somying',
            'email' => 'somying@example.com',
            'password' => Hash::make('secret123'),
            'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'somying',
            'password' => 'wrongpass',
        ]);

        $response->assertStatus(401)->assertJson(['detail' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง']);
    }

    /** @test */
    public function login_fails_for_a_username_that_does_not_exist()
    {
        $response = $this->postJson('/api/login', [
            'username' => 'nobody',
            'password' => 'whatever1',
        ]);

        $response->assertStatus(401)->assertJson(['detail' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง']);
    }

    // ---- forgot-password / reset-password ----

    /** @test */
    public function forgot_password_sends_a_code_when_the_email_exists()
    {
        Mail::fake();

        Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->postJson('/api/forgot-password', ['email' => 'somying@example.com']);

        $response->assertStatus(200);
        Mail::assertSent(\App\Mail\NursePasswordResetCodeMail::class);
        $this->assertDatabaseCount('password_reset_codes', 1);
    }

    /** @test */
    public function forgot_password_returns_the_identical_response_for_an_unknown_email_no_enumeration()
    {
        Mail::fake();

        $known = Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);

        $knownResponse = $this->postJson('/api/forgot-password', ['email' => 'somying@example.com']);
        $unknownResponse = $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com']);

        $knownResponse->assertStatus(200);
        $unknownResponse->assertStatus(200);
        $this->assertSame($knownResponse->json(), $unknownResponse->json());
        Mail::assertSent(\App\Mail\NursePasswordResetCodeMail::class, 1);
        $this->assertDatabaseCount('password_reset_codes', 1);
    }

    /** @test */
    public function forgot_password_replaces_any_previous_unused_code_for_the_same_email()
    {
        Mail::fake();
        Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);

        $this->postJson('/api/forgot-password', ['email' => 'somying@example.com']);
        $this->postJson('/api/forgot-password', ['email' => 'somying@example.com']);

        $this->assertDatabaseCount('password_reset_codes', 1);
    }

    /** @test */
    public function reset_password_succeeds_with_the_correct_unexpired_code()
    {
        Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);
        PasswordResetCode::create([
            'email' => 'somying@example.com',
            'code' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email' => 'somying@example.com', 'code' => '123456', 'new_password' => 'newpass456',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('newpass456', Nurse::first()->password));
    }

    /** @test */
    public function reset_password_rejects_an_incorrect_code()
    {
        Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);
        PasswordResetCode::create([
            'email' => 'somying@example.com',
            'code' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email' => 'somying@example.com', 'code' => '000000', 'new_password' => 'newpass456',
        ]);

        $response->assertStatus(422)->assertJson(['detail' => 'รหัสยืนยันไม่ถูกต้องหรือหมดอายุ']);
        $this->assertTrue(Hash::check('secret123', Nurse::first()->password));
    }

    /** @test */
    public function reset_password_rejects_an_expired_code()
    {
        Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);
        PasswordResetCode::create([
            'email' => 'somying@example.com',
            'code' => Hash::make('123456'),
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this->postJson('/api/reset-password', [
            'email' => 'somying@example.com', 'code' => '123456', 'new_password' => 'newpass456',
        ]);

        $response->assertStatus(422)->assertJson(['detail' => 'รหัสยืนยันไม่ถูกต้องหรือหมดอายุ']);
    }

    /** @test */
    public function reset_password_code_cannot_be_reused_after_a_successful_reset()
    {
        Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);
        PasswordResetCode::create([
            'email' => 'somying@example.com',
            'code' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/reset-password', [
            'email' => 'somying@example.com', 'code' => '123456', 'new_password' => 'newpass456',
        ])->assertStatus(200);

        $second = $this->postJson('/api/reset-password', [
            'email' => 'somying@example.com', 'code' => '123456', 'new_password' => 'yetanother789',
        ]);

        $second->assertStatus(422)->assertJson(['detail' => 'รหัสยืนยันไม่ถูกต้องหรือหมดอายุ']);
    }

    // ---- profile / change-password (Sanctum-protected) ----

    private function authHeader(Nurse $nurse): array
    {
        $token = $nurse->createToken('test')->plainTextToken;
        return ['Authorization' => "Bearer {$token}"];
    }

    /** @test */
    public function profile_update_requires_authentication()
    {
        $response = $this->putJson('/api/profile', [
            'full_name' => 'X', 'username' => 'somying', 'email' => 'somying@example.com',
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function profile_update_changes_the_authenticated_nurses_own_data()
    {
        $nurse = Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->withHeaders($this->authHeader($nurse))->putJson('/api/profile', [
            'full_name' => 'สมหญิง แก้ไขแล้ว', 'username' => 'somying2', 'email' => 'new@example.com',
        ]);

        $response->assertStatus(200)->assertJson([
            'full_name' => 'สมหญิง แก้ไขแล้ว', 'username' => 'somying2', 'email' => 'new@example.com',
        ]);
        $this->assertSame('somying2', $nurse->fresh()->username);
    }

    /** @test */
    public function profile_update_allows_keeping_ones_own_existing_username_and_email()
    {
        $nurse = Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->withHeaders($this->authHeader($nurse))->putJson('/api/profile', [
            'full_name' => 'สมหญิง (แก้ชื่อ)', 'username' => 'somying', 'email' => 'somying@example.com',
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function profile_update_rejects_a_username_already_used_by_another_nurse()
    {
        Nurse::create([
            'full_name' => 'คนอื่น', 'username' => 'other', 'email' => 'other@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);
        $nurse = Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-002',
        ]);

        $response = $this->withHeaders($this->authHeader($nurse))->putJson('/api/profile', [
            'full_name' => 'สมหญิง ใจดี', 'username' => 'other', 'email' => 'somying@example.com',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('username');
    }

    /** @test */
    public function change_password_requires_authentication()
    {
        $response = $this->postJson('/api/change-password', [
            'current_password' => 'secret123', 'new_password' => 'newpass456',
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function change_password_succeeds_with_the_correct_current_password()
    {
        $nurse = Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->withHeaders($this->authHeader($nurse))->postJson('/api/change-password', [
            'current_password' => 'secret123', 'new_password' => 'newpass456',
        ]);

        $response->assertStatus(200);
        $this->assertTrue(Hash::check('newpass456', $nurse->fresh()->password));
    }

    /** @test */
    public function change_password_rejects_an_incorrect_current_password()
    {
        $nurse = Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->withHeaders($this->authHeader($nurse))->postJson('/api/change-password', [
            'current_password' => 'wrongpass', 'new_password' => 'newpass456',
        ]);

        $response->assertStatus(422)->assertJson(['detail' => 'รหัสผ่านปัจจุบันไม่ถูกต้อง']);
        $this->assertTrue(Hash::check('secret123', $nurse->fresh()->password));
    }

    // ---- profile photo upload ----

    /** @test */
    public function upload_profile_photo_requires_authentication()
    {
        $response = $this->postJson('/api/profile/photo', [
            'photo' => UploadedFile::fake()->image('photo.jpg'),
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function login_response_has_a_null_photo_url_when_no_photo_was_ever_uploaded()
    {
        Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->postJson('/api/login', ['username' => 'somying', 'password' => 'secret123']);

        $response->assertStatus(200)->assertJson(['profile_photo_url' => null]);
    }

    /** @test */
    public function upload_profile_photo_saves_the_file_and_returns_a_reachable_url()
    {
        Storage::fake('public');
        $nurse = Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->withHeaders($this->authHeader($nurse))->postJson('/api/profile/photo', [
            'photo' => UploadedFile::fake()->image('photo.jpg', 300, 300),
        ]);

        $response->assertStatus(200);
        $path = $nurse->fresh()->profile_photo_path;
        $this->assertNotNull($path);
        $this->assertStringStartsWith('profile-photos/nurse-' . $nurse->id . '-', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertSame('http://localhost/storage/' . $path, $response->json('profile_photo_url'));
    }

    /** @test */
    public function upload_profile_photo_deletes_the_previous_photo_after_saving_the_new_one()
    {
        Storage::fake('public');
        $nurse = Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);

        $first = $this->withHeaders($this->authHeader($nurse))->postJson('/api/profile/photo', [
            'photo' => UploadedFile::fake()->image('first.jpg'),
        ]);
        $firstPath = $nurse->fresh()->profile_photo_path;
        Storage::disk('public')->assertExists($firstPath);

        $this->withHeaders($this->authHeader($nurse))->postJson('/api/profile/photo', [
            'photo' => UploadedFile::fake()->image('second.jpg'),
        ])->assertStatus(200);
        $secondPath = $nurse->fresh()->profile_photo_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    /** @test */
    public function upload_profile_photo_rejects_a_non_image_file()
    {
        Storage::fake('public');
        $nurse = Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->withHeaders($this->authHeader($nurse))->postJson('/api/profile/photo', [
            'photo' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->assertNull($nurse->fresh()->profile_photo_path);
    }

    /** @test */
    public function upload_profile_photo_rejects_a_file_larger_than_5mb()
    {
        Storage::fake('public');
        $nurse = Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->withHeaders($this->authHeader($nurse))->postJson('/api/profile/photo', [
            'photo' => UploadedFile::fake()->image('huge.jpg')->size(6000),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    /** @test */
    public function upload_profile_photo_rejects_a_missing_file()
    {
        $nurse = Nurse::create([
            'full_name' => 'สมหญิง ใจดี', 'username' => 'somying', 'email' => 'somying@example.com',
            'password' => Hash::make('secret123'), 'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->withHeaders($this->authHeader($nurse))->postJson('/api/profile/photo', []);

        $response->assertStatus(422)->assertJsonValidationErrors('photo');
    }
}
