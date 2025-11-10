<?php
// app/Http/Requests/Reports/GenerateRequest.php
namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;

class GenerateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'template'  => 'required|string|in:synopsis,rol,final_thesis,presentation',
            'format'    => 'required|string|in:pdf,docx,xlsx,pptx',
            'filename'  => 'nullable|string|max:255',
            'filters'   => 'required|array',
            'selections'=> 'required|array',
        ];
    }
}
