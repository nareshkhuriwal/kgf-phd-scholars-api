<?php
// app/Http/Requests/SupervisorRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupervisorRequest extends FormRequest
{
    public function authorize(): bool
    {
        // You can tighten this later with policies/Gates
        return $this->user() !== null;
    }

    public function rules(): array
    {
        /** @var \App\Models\User|null $supervisor */
        $supervisor = $this->route('supervisor'); // Route model binding
        $id = $supervisor?->id;

        return [
            'name' => ['required', 'string', 'max:120'],

            'email' => [
                'required',
                'email:rfc,dns',
                'max:190',
                Rule::unique('users', 'email')->ignore($id),
            ],

            'phone' => ['nullable', 'string', 'max:32'],

            'employeeId' => ['required', 'string', 'max:64'],
            // allow either employeeId camelCase or employee_id snakeCase from frontend/back
            'employee_id' => ['sometimes', 'nullable', 'string', 'max:64'],

            'department' => ['required', 'string', 'max:190'],

            'specialization' => ['nullable', 'string', 'max:190'],

            'notes' => ['nullable', 'string'],
        ];
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);

        // Normalise employeeId -> employee_id
        if (isset($data['employeeId']) && !isset($data['employee_id'])) {
            $data['employee_id'] = $data['employeeId'];
            unset($data['employeeId']);
        }

        return $data;
    }
}
