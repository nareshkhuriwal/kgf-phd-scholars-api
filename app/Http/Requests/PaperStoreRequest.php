<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaperStoreRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'Title' => ['required','string'],
            'Year' => ['nullable','string','max:10'],
            'DOI' => ['nullable','string','max:255'],
        ];
    }
}
