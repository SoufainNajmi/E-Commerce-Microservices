<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\DevelopmentAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function registration(array $overrides = []): array
    {
        return array_replace(['name' => 'Test Customer', 'email' => 'customer@example.test', 'password' => 'SecurePassword123!', 'password_confirmation' => 'SecurePassword123!'], $overrides);
    }

    public function test_registration_hashes_password_and_assigns_customer(): void
    {
        $response = $this->postJson('/api/auth/register', $this->registration())->assertCreated()->assertJsonPath('success', true)->assertJsonPath('data.user.role', 'CUSTOMER')->assertJsonMissingPath('data.user.password');
        $this->assertTrue(Hash::check('SecurePassword123!', User::first()->password));
        $this->assertNotSame($response->json('data.token'), User::first()->tokens()->first()->token);
    }

    public function test_duplicate_email_is_case_insensitive(): void
    {
        User::factory()->create(['email' => 'customer@example.test']);
        $this->postJson('/api/auth/register', $this->registration(['email' => 'CUSTOMER@example.test']))->assertUnprocessable()->assertJsonValidationErrors('email')->assertJsonPath('success', false);
    }

    public function test_invalid_registration_data(): void
    {
        $this->postJson('/api/auth/register', ['name' => '', 'email' => 'invalid', 'password' => 'short'])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_password_confirmation_is_required(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['password_confirmation' => 'different']))->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_self_assigned_roles_are_rejected(): void
    {
        foreach (Role::cases() as $role) {
            $this->postJson('/api/auth/register', $this->registration(['role' => $role->value]))->assertUnprocessable()->assertJsonValidationErrors('role');
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_and_me_with_real_bearer_token(): void
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'TestPassword123!'])->assertOk()->assertJsonPath('message', 'Login successful')->json('data.token');
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('data.user.id', $user->id)->assertJsonMissingPath('data.user.password');
    }

    public function test_invalid_credentials(): void
    {
        $user = User::factory()->create();
        foreach ([$user->email, 'missing@example.test'] as $email) {
            $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong'])->assertUnauthorized()->assertJsonPath('message', 'Invalid credentials');
        }
    }

    public function test_protected_routes_require_authentication_even_without_accept_header(): void
    {
        $this->get('/api/auth/me')->assertUnauthorized()->assertJsonPath('success', false);
        $this->postJson('/api/auth/logout')->assertUnauthorized();
        $this->postJson('/api/auth/refresh')->assertUnauthorized();
    }

    public function test_logout_revokes_only_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('first')->plainTextToken;
        $other = $user->createToken('second')->plainTextToken;
        $this->postJson('/api/auth/logout', [], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$other])->assertOk();
    }

    public function test_refresh_rotates_token_and_rejects_replay(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('old')->plainTextToken;
        $new = $this->postJson('/api/auth/refresh', [], ['Authorization' => 'Bearer '.$token])->assertOk()->json('data.token');
        $this->assertNotSame($token, $new);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/refresh', [], ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$new])->assertOk();
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = User::factory()->create()->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_login_rate_limit_has_consistent_error_format(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'bad@example.test', 'password' => 'wrong'])->assertUnauthorized();
        }
        $this->postJson('/api/auth/login', ['email' => 'bad@example.test', 'password' => 'wrong'])->assertStatus(429)->assertJsonPath('success', false)->assertHeader('Retry-After');
    }

    public function test_health(): void
    {
        $this->getJson('/health')->assertOk()->assertExactJson(['service' => 'auth-service', 'status' => 'healthy']);
    }

    public function test_only_auth_database_is_configured(): void
    {
        $this->assertSame(['pgsql'], array_keys(config('database.connections')));
        $this->assertSame('auth_db', DB::selectOne('select current_database() as name')->name);
    }

    public function test_admin_seeder_uses_environment_configuration_and_is_idempotent(): void
    {
        config(['auth.admin_email' => 'admin@example.test', 'auth.admin_password' => 'AdminPassword123!']);
        $this->seed(DevelopmentAdminSeeder::class);
        $this->seed(DevelopmentAdminSeeder::class);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame(Role::ADMIN, User::first()->role);
        $this->assertTrue(Hash::check('AdminPassword123!', User::first()->password));
    }

    public function test_database_role_cannot_connect_to_other_databases(): void
    {
        $role = DB::selectOne('select rolsuper, rolcreatedb, rolcreaterole from pg_roles where rolname = current_user');
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolcreatedb);
        $this->assertFalse($role->rolcreaterole);
        $databases = DB::select("select datname from pg_database where datallowconn and has_database_privilege(current_user, datname, 'CONNECT')");
        $this->assertSame(['auth_db'], array_column($databases, 'datname'));
    }

    public function test_malformed_login_input_returns_validation_error(): void
    {
        $this->postJson('/api/auth/login', ['email' => ['unexpected'], 'password' => []])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_development_seeder_does_not_promote_existing_customer(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.test']);
        config(['auth.admin_email' => $user->email, 'auth.admin_password' => 'AdminPassword123!']);
        $this->seed(DevelopmentAdminSeeder::class);
        $this->assertSame(Role::CUSTOMER, $user->refresh()->role);
        $this->assertTrue(Hash::check('TestPassword123!', $user->password));
    }

    public function test_development_seeder_is_disabled_in_production(): void
    {
        $this->app->instance('env', 'production');
        config(['auth.admin_email' => 'admin@example.test', 'auth.admin_password' => 'AdminPassword123!']);
        $this->artisan('db:seed', ['--class' => DevelopmentAdminSeeder::class, '--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_non_string_and_multibyte_overlong_passwords_are_validation_errors(): void
    {
        foreach ([['unexpected'], str_repeat('é', 35).'Aa1!'] as $password) {
            $this->postJson('/api/auth/register', $this->registration(['password' => $password, 'password_confirmation' => $password]))->assertUnprocessable()->assertJsonValidationErrors('password');
        }
    }
}
