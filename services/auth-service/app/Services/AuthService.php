<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    public function register(array $data): array
    {
        try {
            return DB::transaction(function () use ($data) {
                $user = User::create($data)->refresh();

                return $this->issueToken($user);
            });
        } catch (UniqueConstraintViolationException $e) {
            throw ValidationException::withMessages(['email' => ['The email has already been taken.']]);
        }
    }

    public function login(array $data): array
    {
        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw new AuthenticationException('Invalid credentials');
        }
        if (Hash::needsRehash($user->password)) {
            $user->update(['password' => $data['password']]);
        }

        return $this->issueToken($user);
    }

    public function refresh(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $token = PersonalAccessToken::whereKey($user->currentAccessToken()->getKey())->lockForUpdate()->first();
            if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
                throw new AuthenticationException;
            }
            $token->delete();

            return $this->issueToken($user);
        });
    }

    private function issueToken(User $user): array
    {
        $expiresAt = now()->addMinutes(config('auth.token_ttl'));
        $token = $user->createToken('auth-session', ['*'], $expiresAt);

        return ['user' => new UserResource($user), 'token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expiresAt->toIso8601String()];
    }
}
