<?php

namespace App\Http\Requests;

class UpdatePasswordRequest extends RegisterRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void {}

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:72'],
            'password' => parent::rules()['password'],
        ];
    }
}
