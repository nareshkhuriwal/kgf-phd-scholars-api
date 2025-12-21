<?php

namespace App\Http\Controllers;

use App\Models\AuthoredPaper;
use App\Models\AuthoredPaperSection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AuthoredPaperSectionController extends Controller
{
        /**
     * ➕ Add new section (inline +)
     */
    public function addSection(Request $request, int $id): JsonResponse
    {
        $paper = AuthoredPaper::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            'section_title' => 'required|string|max:255',
        ]);

        $maxPosition = AuthoredPaperSection::where('authored_paper_id', $paper->id)
            ->max('position') ?? 0;

        $section = AuthoredPaperSection::create([
            'authored_paper_id' => $paper->id,
            'section_key' => strtolower(str_replace(' ', '_', $data['section_title'])),
            'section_title' => $data['section_title'],
            'body_html' => '',
            'position' => $maxPosition + 1,
        ]);

        return response()->json($section, 201);
    }

    /**
     * ✏️ Update section (rename / content)
     */
    public function updateSection(Request $request, int $sectionId): JsonResponse
    {
        $section = AuthoredPaperSection::with('paper')
            ->where('id', $sectionId)
            ->firstOrFail();

        if ($section->paper->user_id !== $request->user()->id) {
            abort(403);
        }

        $data = $request->validate([
            'section_title' => 'nullable|string|max:255',
            'body_html' => 'nullable|string',
        ]);

        $section->update($data);

        return response()->json($section);
    }

    /**
     * ❌ Delete section (inline ×)
     */
    public function deleteSection(Request $request, int $sectionId): JsonResponse
    {
        $section = AuthoredPaperSection::with('paper')
            ->where('id', $sectionId)
            ->firstOrFail();

        if ($section->paper->user_id !== $request->user()->id) {
            abort(403);
        }

        $section->delete();

        return response()->json(null, 204);
    }

    /**
     * ↕️ Reorder sections (drag & drop ready)
     */
    public function reorderSections(Request $request, int $id): JsonResponse
    {
        $paper = AuthoredPaper::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            'order' => 'required|array',
            'order.*' => 'integer|exists:authored_paper_sections,id',
        ]);

        foreach ($data['order'] as $index => $sectionId) {
            AuthoredPaperSection::where('id', $sectionId)
                ->where('authored_paper_id', $paper->id)
                ->update(['position' => $index]);
        }

        return response()->json(['success' => true]);
    }

}
