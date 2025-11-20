<?php
// app/Http/Requests/Reviews/AddToQueueRequest.php
namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

class AddToQueueRequest extends FormRequest
{
    public function authorize(): bool { return true; } // policy can be added later
    public function rules(): array {
        return ['paperId' => ['required', 'integer', 'exists:papers,id']];
    }
}
