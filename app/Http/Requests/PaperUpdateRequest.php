<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaperUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'Title' => ['sometimes','string'],
            'Year' => ['sometimes','string','max:10'],
            'DOI' => ['sometimes','string','max:255'],
        ];
    }
}
