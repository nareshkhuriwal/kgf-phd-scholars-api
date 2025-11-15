<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class ResetPasswordWithOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public endpoint
    }

    public function rules(): array
    {
        return [
            'email'                 => ['required', 'email:rfc,dns'],
            'otp'                   => ['required', 'digits:6'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            // password_confirmation will be validated automatically
        ];
    }

    public function messages(): array
    {
        return [
            'otp.required' => 'OTP is required.',
            'otp.digits'   => 'OTP must be 6 digits.',
        ];
    }
}
