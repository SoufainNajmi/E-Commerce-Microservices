<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class DevelopmentAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            $this->command?->warn('Development admin seeding is disabled outside local/testing.');

            return;
        }
        $data = ['email' => mb_strtolower(trim((string) config('auth.admin_email'))), 'password' => config('auth.admin_password')];
        if (! $data['email'] || ! $data['password']) {
            $this->command?->warn('Set ADMIN_EMAIL and ADMIN_PASSWORD to seed a development admin.');

            return;
        }
        Validator::make($data, ['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:72', Password::min(12)->mixedCase()->numbers()->symbols()]])->validate();
        if (User::where('email', $data['email'])->exists()) {
            $this->command?->warn('Account already exists; no credentials or role changed.');

            return;
        }
        $user = new User(['name' => 'Development Admin', ...$data]);
        $user->role = Role::ADMIN;
        $user->save();
    }
}
