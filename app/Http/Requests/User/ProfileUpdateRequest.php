<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Hash;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $userId = $this->user()->id;

        $rules = [
            'name'          => ['sometimes', 'required', 'string', 'max:120'],
            'email'         => ['sometimes', 'required', 'email:rfc,dns', 'max:190', Rule::unique('users','email')->ignore($userId)],
            'phone'         => ['sometimes', 'nullable', 'string', 'max:32'],
            'organization'  => ['sometimes', 'nullable', 'string', 'max:150'],
        ];

        // optional password change
        if ($this->filled('password')) {
            $rules['current_password'] = ['required'];
            $rules['password'] = ['required', 'string', 'min:8', 'confirmed'];
        }

        return $rules;
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            if ($this->filled('password')) {
                if (! Hash::check($this->input('current_password'), $this->user()->password)) {
                    $v->errors()->add('current_password', 'The current password is incorrect.');
                }
            }
        });
    }
}
