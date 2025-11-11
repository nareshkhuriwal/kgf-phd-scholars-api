<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:120'],
            'email'         => ['required', 'email:rfc,dns', 'max:190', 'unique:users,email'],
            'password'      => ['required', 'string', 'min:8', 'confirmed'], // expects password_confirmation
            'phone'         => ['nullable', 'string', 'max:32'],
            'organization'  => ['nullable', 'string', 'max:150'],
            'terms'         => ['accepted'], // frontend sends boolean true
        ];
    }

    public function messages(): array
    {
        return [
            'terms.accepted' => 'You must accept the Terms & Privacy.',
        ];
    }
}
