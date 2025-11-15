<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user must be authenticated via middleware; we still allow here
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password'       => ['required', 'string'],
            'password'               => ['required', 'string', 'min:8', 'confirmed'],
            // expects password_confirmation automatically
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => 'Current password is required.',
            'password.required'         => 'New password is required.',
            'password.min'              => 'New password must be at least :min characters.',
            'password.confirmed'        => 'New password confirmation does not match.',
        ];
    }
}
