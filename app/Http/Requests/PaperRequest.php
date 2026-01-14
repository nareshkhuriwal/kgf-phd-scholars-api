<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaperRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'paper_code' => ['nullable','string','max:190'],
            'title'      => ['required','string','max:500'],
            'authors'    => ['nullable','string','max:1000'],
            'doi'        => ['nullable','string','max:255'],
            'year'       => ['nullable','string','max:10'],
            'category'   => ['nullable','string','max:255'],
            'journal'    => ['nullable','string','max:500'],
            'issn_isbn'  => ['nullable','string','max:255'],
            'publisher'  => ['nullable','string','max:255'],
            'place'      => ['nullable','string','max:255'],
            'volume'     => ['nullable','string','max:50'],
            'issue'      => ['nullable','string','max:50'],
            'page_no'    => ['nullable','string','max:50'],
            'area'       => ['nullable','string','max:255'],
            'citation_type_code'     => ['nullable','string','max:255'],

            // ROL HTML fields (optional)
            // 'key_issue'  => ['nullable','string'],
            // 'review_html'            => ['nullable','string'],
            // 'solution_method_html'   => ['nullable','string'],
            // 'related_work_html'      => ['nullable','string'],
            // 'input_params_html'      => ['nullable','string'],
            // 'hw_sw_html'             => ['nullable','string'],
            // 'results_html'           => ['nullable','string'],
            // 'advantages_html'        => ['nullable','string'],
            // 'limitations_html'       => ['nullable','string'],
            // 'remarks_html'           => ['nullable','string'],

            'meta' => ['nullable','array'],
            'file' => ['nullable','file','mimes:pdf','max:51200'], // 50MB
        ];
    }
}
