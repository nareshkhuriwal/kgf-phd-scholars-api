<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CollectionRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array {
        return [
            'name'        => ['required','string','max:255'],
            'description' => ['nullable','string'],
            'purpose'     => ['nullable','in:ROL,Thesis,Survey,Misc'],
            'status'      => ['nullable','in:draft,active,archived'],
        ];
    }
}
