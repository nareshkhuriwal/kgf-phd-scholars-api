<?php
namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'name'          => ['required', 'string', 'max:120'],
            'email'         => ['required', 'email:rfc,dns', 'max:190', 'unique:users,email'],
            'password'      => ['required', 'string', 'min:8', 'confirmed'],
            'phone'         => ['nullable', 'string', 'max:32'],
            'terms'         => ['accepted'],
            'role'          => ['required', 'string', Rule::in(['researcher', 'supervisor', 'admin'])],
        ];

        // Role-specific validation rules
        $role = $this->input('role');

        if ($role === 'researcher') {
            $rules['research_area'] = ['nullable', 'string', 'max:255'];
            $rules['researchArea'] = ['nullable', 'string', 'max:255']; // Accept both formats
            $rules['department'] = ['nullable', 'string', 'max:150'];
        }

        if ($role === 'supervisor') {
            $rules['employee_id'] = ['required', 'string', 'max:50', 'unique:users,employee_id'];
            $rules['employeeId'] = ['required', 'string', 'max:50', 'unique:users,employee_id']; // Accept both formats
            $rules['department'] = ['required', 'string', 'max:150'];
            $rules['specialization'] = ['nullable', 'string', 'max:255'];
            $rules['organization'] = ['nullable', 'string', 'max:150'];
        }

        if ($role === 'admin') {
            $rules['organization'] = ['required', 'string', 'max:150'];
            // Trial fields (optional, sent from frontend when user clicks "Start Trial")
            $rules['trial'] = ['nullable', 'integer', 'in:0,1'];
            $rules['trial_start_date'] = ['nullable', 'date'];
            $rules['trial_end_date'] = ['nullable', 'date', 'after:trial_start_date'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'terms.accepted' => 'You must accept the Terms & Privacy.',
            'role.required' => 'Please select an account role.',
            'role.in' => 'Invalid account role selected.',
            'employee_id.required' => 'Employee ID is required for supervisor accounts.',
            'employeeId.required' => 'Employee ID is required for supervisor accounts.',
            'employee_id.unique' => 'This employee ID is already registered.',
            'employeeId.unique' => 'This employee ID is already registered.',
            'department.required' => 'Department is required for this account type.',
            'organization.required' => 'Organization is required for this account type.',
            'trial_end_date.after' => 'Trial end date must be after start date.',
        ];
    }
}