<?php
// app/Http/Requests/BulkDeleteSavedReportsRequest.php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteSavedReportsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (empty($this->all()) && $this->getContent()) {
            $raw = $this->getContent();
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $this->merge($decoded);
        }
    }

    public function rules(): array
    {
        return [
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ];
    }
}
