<?php
// app/Http/Requests/Reviews/SaveReviewSectionRequest.php
namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

class SaveReviewSectionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    // public function rules(): array {
    //     return [
    //         'section_key' => ['required','string','max:255'],   // e.g., "Key Issue"
    //         'html'        => ['nullable','string'],
    //     ];
    // }

    public function rules(): array
    {
        if ($this->input('section_key') === 'tags') {
            return [
                'section_key'    => 'required|in:tags',
                'problem_tags'   => 'array',
                'problem_tags.*' => 'integer|exists:tags,id',
                'solution_tags'  => 'array',
                'solution_tags.*'=> 'integer|exists:tags,id',
            ];
        }

        return [
            'section_key' => 'required|string',
            'html'        => 'required|string',
        ];
    }


}
