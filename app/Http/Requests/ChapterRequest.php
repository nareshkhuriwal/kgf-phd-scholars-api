<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChapterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array {
        return [
            'title'         => ['required','string','max:255'],
            'chapter_type'         => ['required','string','max:255'],
            'collection_id' => ['nullable','exists:collections,id'],
            'order_index'   => ['nullable','integer'],
            'body_html'     => ['nullable','string'],
        ];
    }
}
