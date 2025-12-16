<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyHighlightsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'replace' => ['sometimes', 'boolean'],

            // -------------------------------
            // Rect highlights (existing)
            // -------------------------------
            'highlights' => ['sometimes', 'array', 'min:1'],
            'highlights.*.page' => ['required_with:highlights', 'integer', 'min:1'],
            'highlights.*.rects' => ['required_with:highlights', 'array', 'min:1'],
            'highlights.*.rects.*.x' => ['required_with:highlights.*.rects', 'numeric', 'between:0,1'],
            'highlights.*.rects.*.y' => ['required_with:highlights.*.rects', 'numeric', 'between:0,1'],
            'highlights.*.rects.*.w' => ['required_with:highlights.*.rects', 'numeric', 'between:0,1'],
            'highlights.*.rects.*.h' => ['required_with:highlights.*.rects', 'numeric', 'between:0,1'],

            // -------------------------------
            // Brush highlights (NEW)
            // -------------------------------
            'brushHighlights' => ['sometimes', 'array', 'min:1'],
            'brushHighlights.*.page' => ['required_with:brushHighlights', 'integer', 'min:1'],
            'brushHighlights.*.strokes' => ['required_with:brushHighlights', 'array', 'min:1'],
            'brushHighlights.*.strokes.*.points' => ['required', 'array', 'min:2'],
            'brushHighlights.*.strokes.*.points.*.x' => ['required', 'numeric', 'between:0,1'],
            'brushHighlights.*.strokes.*.points.*.y' => ['required', 'numeric', 'between:0,1'],
        ];
    }

    /**
     * Ensure at least one of rect or brush highlights is present
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $hasRects  = is_array($this->input('highlights')) && count($this->input('highlights')) > 0;
            $hasBrush  = is_array($this->input('brushHighlights')) && count($this->input('brushHighlights')) > 0;

            if (!$hasRects && !$hasBrush) {
                $validator->errors()->add(
                    'highlights',
                    'Either highlights or brushHighlights must be provided.'
                );
            }
        });
    }
}
