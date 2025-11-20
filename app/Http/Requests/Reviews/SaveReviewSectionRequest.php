<?php
// app/Http/Requests/Reviews/SaveReviewSectionRequest.php
namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

class SaveReviewSectionRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'section_key' => ['required','string','max:255'],   // e.g., "Key Issue"
            'html'        => ['nullable','string'],
        ];
    }
}
