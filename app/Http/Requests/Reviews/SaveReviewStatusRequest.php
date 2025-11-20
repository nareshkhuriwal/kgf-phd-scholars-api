<?php
// app/Http/Requests/Reviews/SaveReviewStatusRequest.php
namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

class SaveReviewStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return ['status' => ['required', 'in:draft,in_progress,done,archived']]; // use 'done' for complete
    }
}
