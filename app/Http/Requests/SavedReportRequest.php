<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavedReportRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        if (empty($this->all()) && $this->getContent()) {
            $raw = $this->getContent();
            $decoded = json_decode($raw, true);

            if (is_string($decoded)) {
                $decoded2 = json_decode($decoded, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded2)) $decoded = $decoded2;
            }
            if (is_array($decoded) && array_key_exists('payload', $decoded)) {
                $p = $decoded['payload'];
                if (is_string($p)) {
                    $p2 = json_decode($p, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($p2)) $decoded = $p2;
                } elseif (is_array($p)) {
                    $decoded = $p;
                }
            }
            if (is_array($decoded)) $this->merge($decoded);
        }

        foreach (['filters','selections'] as $k) {
            $v = $this->input($k);
            if (is_string($v)) {
                $arr = json_decode($v, true);
                if (json_last_error() === JSON_ERROR_NONE) $this->merge([$k => $arr]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'name'       => 'required|string|max:255',
            'template'   => 'required|string|in:synopsis,rol,final_thesis,presentation',
            'format'     => 'nullable|string|in:pdf,docx,xlsx,pptx',
            'filename'   => 'nullable|string|max:255',
            'filters'           => 'required|array',
            'filters.areas'     => 'nullable|array',
            'filters.years'     => 'nullable|array',
            'filters.venues'    => 'nullable|array',
            'filters.userIds'   => 'nullable|array',
            'selections'                 => 'required|array',
            'selections.include'         => 'required|array',
            'selections.includeOrder'    => 'required|array',
            'selections.chapters'        => 'nullable|array',
        ];
    }
}
