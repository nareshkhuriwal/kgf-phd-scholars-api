<?php
// app/Http/Requests/Researchers/StoreResearcherInviteRequest.php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreResearcherInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only authenticated Superwise users can create invites
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // Superwise will send ONLY this from UI
            'researcher_email' => ['required', 'email', 'max:255'],

            // Optional extras – keep them if you ever want to use them
            'expires_in_days'  => ['nullable', 'integer', 'min:1', 'max:365'],
            'message'          => ['nullable', 'string'],
            'notes'            => ['nullable', 'string'],
            // We DO NOT expect supervisor_name / role / allowed_domain from the request anymore.
        ];
    }

    public function messages(): array
    {
        return [
            'researcher_email.required' => 'Researcher email is required.',
            'researcher_email.email'    => 'Please provide a valid researcher email address.',
        ];
    }
}
