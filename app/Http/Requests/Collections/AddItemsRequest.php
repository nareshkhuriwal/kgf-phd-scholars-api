<?php
// app/Http/Requests/Collections/AddItemsRequest.php
namespace App\Http\Requests\Collections;

use Illuminate\Foundation\Http\FormRequest;

class AddItemsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'paper_id'         => ['nullable','integer','exists:papers,id'],
            'paper_ids'        => ['nullable','array'],
            'paper_ids.*'      => ['integer','exists:papers,id'],
            'notes_html'       => ['nullable','string'],
            'position'         => ['nullable','integer','min:0'],
        ];
    }
}
