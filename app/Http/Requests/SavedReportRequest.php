<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class SavedReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Log + coerce JSON before validation */
    protected function prepareForValidation(): void
    {
        Log::debug('[SavedReportRequest] prepareForValidation: start', [
            'contentType' => $this->header('Content-Type'),
            'accept'      => $this->header('Accept'),
            'hasAll'      => !empty($this->all()),
        ]);

        // If body parsed as empty but raw JSON exists, merge it
        if (empty($this->all()) && $this->getContent()) {
            $raw = $this->getContent();
            Log::debug('[SavedReportRequest] raw body', ['raw' => $raw]);

            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE && is_string($decoded)) {
                $decoded = json_decode($decoded, true);
            }

            // unwrap common {payload: ...}
            if (is_array($decoded) && array_key_exists('payload', $decoded)) {
                $p = $decoded['payload'];
                $decoded = is_string($p) ? json_decode($p, true) : (is_array($p) ? $p : $decoded);
            }
            if (is_array($decoded)) {
                $this->merge($decoded);
                Log::debug('[SavedReportRequest] merged decoded JSON', ['mergedKeys' => array_keys($decoded)]);
            }
        }

        // If filters/selections/headerFooter arrived as JSON strings, coerce to arrays
        foreach (['filters', 'selections', 'headerFooter'] as $k) {
            $v = $this->input($k);
            if (is_string($v)) {
                $arr = json_decode($v, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->merge([$k => $arr]);
                    Log::debug("[SavedReportRequest] coerced string to array for {$k}", ['keys' => array_keys((array)$arr)]);
                } else {
                    Log::warning("[SavedReportRequest] {$k} is string but not valid JSON", ['value' => $v]);
                }
            }
        }

        Log::debug('[SavedReportRequest] after prepareForValidation', [
            'keys'     => array_keys($this->all()),
            'name'     => $this->input('name'),
            'template' => $this->input('template'),
            'hasFilters' => is_array($this->input('filters')),
            'hasSelections' => is_array($this->input('selections')),
            'hasHeaderFooter' => is_array($this->input('headerFooter')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name'       => 'required|string|max:255',
            'template'   => 'required|string|in:synopsis,rol,final_thesis,presentation',
            'format'     => 'nullable|string|in:pdf,docx,xlsx,pptx',
            'filename'   => 'nullable|string|max:255',

            'filters'         => 'required|array',
            'filters.areas'   => 'nullable|array',
            'filters.years'   => 'nullable|array',
            'filters.venues'  => 'nullable|array',
            'filters.userId' => 'nullable|integer',

            'selections'               => 'required|array',
            'selections.include'       => 'required|array',
            'selections.includeOrder'  => 'required|array',
            'selections.chapters'      => 'nullable|array',

            // Header/Footer settings (optional, but if present must be valid)
            'headerFooter'              => 'nullable|array',
            'headerFooter.headerTitle'  => 'nullable|string|max:500',
            'headerFooter.headerRight'  => 'nullable|string|max:100',  // NEW FIELD
            'headerFooter.footerLeft'   => 'nullable|string|max:500',
            'headerFooter.footerCenter' => 'nullable|string|max:500',
        ];
    }

    /** Log validated payload for sanity */
    public function passedValidation(): void
    {
        Log::debug('[SavedReportRequest] passedValidation', [
            'validated' => $this->validated(),
        ]);
    }

    /** Log validation errors in detail and return JSON 422 */
    protected function failedValidation(Validator $validator)
    {
        Log::error('[SavedReportRequest] failedValidation', [
            'errors'  => $validator->errors()->toArray(),
            'payload' => $this->all(),
            'headers' => [
                'Content-Type' => $this->header('Content-Type'),
                'Accept'       => $this->header('Accept'),
            ],
        ]);

        throw new HttpResponseException(
            response()->json([
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
                'debug'   => [
                    'received_keys' => array_keys($this->all()),
                    'content_type'  => $this->header('Content-Type'),
                ],
            ], 422)
        );
    }
}
