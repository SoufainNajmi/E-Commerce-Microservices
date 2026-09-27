<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;

class RegisterRequest extends LoginRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['bail', 'required', 'string', 'max:72', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols(), function ($attribute, $value, $fail) {
                if (strlen($value) > 72) {
                    $fail('The password must not exceed 72 bytes.');
                }
            }],
            'role' => ['prohibited'],
        ];
    }
}
