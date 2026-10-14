<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email', 'max:255'], 'password' => ['bail', 'required', 'string', 'max:72',
            function (string $attribute, string $value, Closure $fail): void {
                if (strlen($value) > 72) {
                    $fail('A senha deve ter no máximo 72 bytes.');
                }
            }]];
    }
}
