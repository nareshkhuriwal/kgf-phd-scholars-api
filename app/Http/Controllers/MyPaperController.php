<?php
// app/Http/Controllers/MyPaperController.php

namespace App\Http\Controllers;

use App\Models\AuthoredPaper;
use App\Models\AuthoredPaperSection;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Support\ResolvesApiScope;

class MyPaperController extends Controller
{
    use ResolvesApiScope;

    /**
     * List accessible authored papers
     */
    public function index(Request $request): JsonResponse
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('MyPaper index called', [
            'user_id' => $request->user()->id,
            'role' => $request->user()->role
        ]);

        // Get accessible user IDs
        $userIds = $this->resolveApiUserIds($request);

        Log::info('Accessible user IDs for authored papers', [
            'user_ids' => $userIds,
            'count' => count($userIds)
        ]);

        $papers = AuthoredPaper::whereIn('user_id', $userIds)
            ->orderByDesc('updated_at')
            ->get();

        Log::info('Authored papers retrieved', [
            'count' => $papers->count()
        ]);

        return response()->json([
            'data' => $papers,
        ]);
    }

    /**
     * Create new paper with default sections
     */
    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id ?? abort(401, 'Unauthenticated');

        Log::info('Creating new authored paper', [
            'user_id' => $userId
        ]);

        $data = $request->validate([
            'title' => 'required|string|max:500',
        ]);

        $paper = AuthoredPaper::create([
            'user_id' => $userId,
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

        Log::info('Authored paper created with sections', [
            'paper_id' => $paper->id,
            'section_count' => count($sections)
        ]);

        return response()->json([
            'id' => $paper->id,
        ], 201);
    }

    /**
     * Fetch single paper with sections
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('MyPaper show called', [
            'paper_id' => $id,
            'user_id' => $request->user()->id
        ]);

        // Get accessible user IDs
        $userIds = $this->resolveApiUserIds($request);

        $paper = AuthoredPaper::with('sections')
            ->where('id', $id)
            ->whereIn('user_id', $userIds)
            ->firstOrFail();

        Log::info('Authored paper retrieved', [
            'paper_id' => $paper->id,
            'paper_owner' => $paper->user_id,
            'section_count' => $paper->sections->count()
        ]);

        return response()->json($paper);
    }

    /**
     * Update paper metadata OR section content
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('MyPaper update called', [
            'paper_id' => $id,
            'user_id' => $request->user()->id
        ]);

        // Get accessible user IDs
        $userIds = $this->resolveApiUserIds($request);

        $paper = AuthoredPaper::where('id', $id)
            ->whereIn('user_id', $userIds)
            ->firstOrFail();

        Log::info('Authored paper found for update', [
            'paper_id' => $paper->id,
            'paper_owner' => $paper->user_id
        ]);

        $data = $request->validate([
            'title' => 'nullable|string|max:500',
            'status' => 'nullable|in:draft,submitted,published',
            'sections' => 'nullable|array',
            'sections.*.id' => 'required|integer|exists:authored_paper_sections,id',
            'sections.*.body_html' => 'nullable|string',
        ]);

        $updated = [];

        if (isset($data['title'])) {
            $paper->title = $data['title'];
            $updated[] = 'title';
        }

        if (isset($data['status'])) {
            $paper->status = $data['status'];
            $updated[] = 'status';
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
            $updated[] = 'sections';
            Log::info('Authored paper sections updated', [
                'paper_id' => $paper->id,
                'section_count' => count($data['sections'])
            ]);
        }

        Log::info('Authored paper updated successfully', [
            'paper_id' => $paper->id,
            'updated_fields' => $updated
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Delete a paper and its sections
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('MyPaper destroy called', [
            'paper_id' => $id,
            'user_id' => $request->user()->id
        ]);

        // Get accessible user IDs
        $userIds = $this->resolveApiUserIds($request);

        $paper = AuthoredPaper::where('id', $id)
            ->whereIn('user_id', $userIds)
            ->firstOrFail();

        Log::info('Authored paper found for deletion', [
            'paper_id' => $paper->id,
            'paper_owner' => $paper->user_id
        ]);

        // Delete child sections first (safe even without FK cascade)
        $sectionCount = AuthoredPaperSection::where('authored_paper_id', $paper->id)->count();
        AuthoredPaperSection::where('authored_paper_id', $paper->id)->delete();

        // Delete the paper
        $paper->delete();

        Log::info('Authored paper deleted successfully', [
            'paper_id' => $id,
            'sections_deleted' => $sectionCount
        ]);

        return response()->json([
            'success' => true,
            'id' => $id,
        ]);
    }
}