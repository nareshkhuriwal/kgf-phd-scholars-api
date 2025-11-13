<?php
// app/Http/Requests/Reviews/SaveReviewRequest.php
namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

class SaveReviewRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'html'             => ['nullable','string'],
            'status'          => ['nullable', 'in:draft,in_progress,done,archived'],
            'key_issue'        => ['nullable','string'],
            'remarks'          => ['nullable','string'],
            'review_sections'  => ['nullable','array'],      // NEW
            'review_sections.*'=> ['string'],                // each tab value is HTML string
        ];
    }
}
