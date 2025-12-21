<?php
// app/Http/Controllers/MyPaperController.php

namespace App\Http\Controllers;

use App\Models\AuthoredPaper;
use App\Models\AuthoredPaperSection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MyPaperController extends Controller
{
    /**
     * List current user's papers
     */
    public function index(Request $request): JsonResponse
    {
        $papers = AuthoredPaper::where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $papers,
        ]);
    }

    /**
     * Create new paper with default sections
     */
    public function store(Request $request): JsonResponse
    {
            $data = $request->validate([
        'title' => 'required|string|max:500',
    ]);

        $paper = AuthoredPaper::create([
        'user_id' => $request->user()->id,
        'title'   => $data['title'],
        'status'  => 'draft',
    ]);

        $sections = [
            ['abstract', 'Abstract'],
            ['introduction', 'Introduction'],
            ['related_work', 'Related Work'],
            ['methodology', 'Methodology'],
            ['experiments', 'Experiments'],
            ['results', 'Results'],
            ['discussion', 'Discussion'],
            ['conclusion', 'Conclusion'],
            ['references', 'References'],
        ];

        foreach ($sections as $i => [$key, $title]) {
            AuthoredPaperSection::create([
                'authored_paper_id' => $paper->id,
                'section_key'       => $key,
                'section_title'     => $title,
                'position'          => $i,
            ]);
        }

        return response()->json([
            'id' => $paper->id,
        ], 201);
    }

    /**
     * Fetch single paper with sections
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $paper = AuthoredPaper::with('sections')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json($paper);
    }

    /**
     * Update paper metadata OR section content
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $paper = AuthoredPaper::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            'title' => 'nullable|string|max:500',
            'status' => 'nullable|in:draft,submitted,published',
            'sections' => 'nullable|array',
            'sections.*.id' => 'required|integer|exists:authored_paper_sections,id',
            'sections.*.body_html' => 'nullable|string',
        ]);

        if (isset($data['title'])) {
            $paper->title = $data['title'];
        }

        if (isset($data['status'])) {
            $paper->status = $data['status'];
        }

        $paper->save();

        if (!empty($data['sections'])) {
            foreach ($data['sections'] as $sec) {
                AuthoredPaperSection::where('id', $sec['id'])
                    ->where('authored_paper_id', $paper->id)
                    ->update([
                        'body_html' => $sec['body_html'] ?? '',
                    ]);
            }
        }

        return response()->json(['success' => true]);
    }
}
