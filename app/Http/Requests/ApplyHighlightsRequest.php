<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyHighlightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'replace' => ['sometimes','boolean'],
            'highlights' => ['required','array','min:1'],
            'highlights.*.page' => ['required','integer','min:1'],
            'highlights.*.rects' => ['required','array','min:1'],
            'highlights.*.rects.*.x' => ['required','numeric','between:0,1'],
            'highlights.*.rects.*.y' => ['required','numeric','between:0,1'],
            'highlights.*.rects.*.w' => ['required','numeric','between:0,1'],
            'highlights.*.rects.*.h' => ['required','numeric','between:0,1'],
        ];
    }
}
