<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        return $this->success('Registration successful', $this->auth->register($request->safe()->only(['name', 'email', 'password'])), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->success('Login successful', $this->auth->login($request->validated()));
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success('Authenticated user', ['user' => new UserResource($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success('Logout successful', (object) []);
    }

    public function refresh(Request $request): JsonResponse
    {
        return $this->success('Token refreshed', $this->auth->refresh($request->user()));
    }

    private function success(string $message, mixed $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
