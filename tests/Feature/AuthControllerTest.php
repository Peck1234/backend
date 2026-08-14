<?php

namespace Tests\Feature;

use App\Models\Nurse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            'password' => 'secret123',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'full_name' => 'สมหญิง ใจดี',
                'username' => 'somying',
            ])
            ->assertJsonStructure(['nurse_id', 'full_name', 'username', 'qr_code_nurse']);

        $nurse = Nurse::first();
        $this->assertSame('NURSE-' . str_pad((string) $nurse->id, 3, '0', STR_PAD_LEFT), $nurse->qr_code_nurse);
        $this->assertTrue(Hash::check('secret123', $nurse->password));
    }

    /** @test */
    public function register_rejects_a_duplicate_username()
    {
        Nurse::create([
            'full_name' => 'Existing',
            'username' => 'somying',
            'password' => Hash::make('whatever1'),
            'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->postJson('/api/register', [
            'full_name' => 'สมหญิง คนใหม่',
            'username' => 'somying',
            'password' => 'secret123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('username');
    }

    /** @test */
    public function register_rejects_a_username_with_disallowed_characters()
    {
        $response = $this->postJson('/api/register', [
            'full_name' => 'สมหญิง ใจดี',
            'username' => 'som ying!',
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
            'password' => '123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /** @test */
    public function login_succeeds_with_correct_credentials()
    {
        Nurse::create([
            'full_name' => 'สมหญิง ใจดี',
            'username' => 'somying',
            'password' => Hash::make('secret123'),
            'qr_code_nurse' => 'NURSE-001',
        ]);

        $response = $this->postJson('/api/login', [
            'username' => 'somying',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)->assertJson([
            'username' => 'somying',
            'qr_code_nurse' => 'NURSE-001',
        ]);
    }

    /** @test */
    public function login_fails_with_the_wrong_password()
    {
        Nurse::create([
            'full_name' => 'สมหญิง ใจดี',
            'username' => 'somying',
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
}
