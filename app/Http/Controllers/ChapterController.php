<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChapterRequest;
use App\Http\Resources\ChapterOptionResource;
use App\Models\Chapter;
use App\Models\ChapterItem;
use App\Models\Paper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Support\ResolvesApiScope;

class ChapterController extends Controller
{
    use OwnerAuthorizes, ResolvesApiScope;

    /**
     * List chapters owned by accessible users.
     */
    public function index(Request $request): JsonResponse
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Chapter index called', [
            'user_id' => $request->user()->id,
            'role' => $request->user()->role
        ]);

        // Get accessible user IDs
        $userIds = $this->resolveApiUserIds($request);

        Log::info('Accessible user IDs for chapters', [
            'user_ids' => $userIds,
            'count' => count($userIds)
        ]);

        $query = Chapter::query()
            ->whereIn('user_id', $userIds)
            ->withCount('items')
            ->orderBy('order_index');

        $query = Chapter::query()
            ->whereIn('user_id', $userIds)
            ->withCount('items')
            ->with('creator:id,name,email,role') // ✅ ADD THIS
            ->orderBy('order_index');


        if ($collectionId = $request->integer('collection_id')) {
            $query->where('collection_id', $collectionId);
        }

        $perPage = $request->integer('per_page', 25);

        $result = $query->paginate($perPage);

        Log::info('Chapters retrieved', [
            'count' => $result->total()
        ]);

        return response()->json($result);
    }

    /**
     * Lightweight chapter list for dropdowns / selectors.
     */
    public function chapterOptions(Request $request)
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Chapter options called', [
            'user_id' => $request->user()->id
        ]);

        // Get accessible user IDs
        $userIds = $this->resolveApiUserIds($request);

        $query = Chapter::query()
            ->whereIn('user_id', $userIds);

        if ($search = trim((string) $request->get('search'))) {
            $query->where('title', 'like', "%{$search}%");
        }

        $perPage = $request->get('per_page', 100);

        if ($perPage === 'all') {
            return ChapterOptionResource::collection(
                $query->orderBy('title')->get()
            );
        }

        return ChapterOptionResource::collection(
            $query->orderBy('title')->paginate((int) $perPage)
        );
    }

    /**
     * Create a new chapter.
     */
    public function store(ChapterRequest $request): JsonResponse
    {
        $userId = $request->user()->id ?? abort(401, 'Unauthenticated');

        Log::info('Creating new chapter', [
            'user_id' => $userId
        ]);

        $data = $request->validated();
        $data['user_id']    = $userId;
        $data['created_by'] = $userId;
        $data['updated_by'] = $userId;

        // Decode base64 HTML if present
        if (isset($data['body_html'])) {
            $decoded = base64_decode($data['body_html'], true);
            if ($decoded === false) {
                Log::error('Invalid body_html encoding during chapter creation');
                abort(422, 'Invalid HTML encoding for body_html');
            }
            $data['body_html'] = $decoded;
        }

        $chapter = Chapter::create($data);

        Log::info('Chapter created successfully', [
            'chapter_id' => $chapter->id
        ]);

        return response()->json($chapter, 201);
    }

    /**
     * Show a single chapter with its items.
     */
    public function show(Chapter $chapter): JsonResponse
    {
        $request = request();
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Chapter show called', [
            'chapter_id' => $chapter->id,
            'user_id' => $request->user()->id
        ]);

        // Check if user has access to this chapter's owner
        $this->authorizeUserAccess($request, $chapter->user_id);

        Log::info('User authorized to view chapter', [
            'chapter_id' => $chapter->id,
            'chapter_owner' => $chapter->user_id
        ]);

        return response()->json(
            $chapter->load('items.paper:id,title,authors,year,doi,paper_code,created_at,updated_at')
        );
    }

    /**
     * Update chapter metadata or body.
     */
    public function update(ChapterRequest $request, Chapter $chapter): JsonResponse
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Chapter update called', [
            'chapter_id' => $chapter->id,
            'user_id' => $request->user()->id
        ]);

        // Check if user has access to this chapter's owner
        $this->authorizeUserAccess($request, $chapter->user_id);

        $data = $request->validated();
        $data['updated_by'] = $request->user()->id;

        /*
        |---------------------------------------------------------
        | Decode Base64 HTML fields (CRITICAL)
        |---------------------------------------------------------
        | These fields are edited via CKEditor and are now sent
        | Base64-encoded to bypass ModSecurity.
        */
        $htmlFields = [
            'body_html',
            'introduction_html',
            'summary_html',
            'conclusion_html',
        ];

        foreach ($htmlFields as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $decoded = base64_decode($data[$field], true);

                if ($decoded === false) {
                    Log::error('Invalid HTML encoding during chapter update', [
                        'chapter_id' => $chapter->id,
                        'field' => $field
                    ]);
                    abort(422, "Invalid HTML encoding for {$field}");
                }

                $data[$field] = $decoded;
            }
        }

        $chapter->update($data);

        Log::info('Chapter updated successfully', [
            'chapter_id' => $chapter->id
        ]);

        return response()->json($chapter->fresh());
    }

    /**
     * Delete a chapter and its items.
     */
    public function destroy(Chapter $chapter): JsonResponse
    {
        $request = request();
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Chapter destroy called', [
            'chapter_id' => $chapter->id,
            'user_id' => $request->user()->id
        ]);

        // Check if user has access to this chapter's owner
        $this->authorizeUserAccess($request, $chapter->user_id);

        DB::transaction(function () use ($chapter) {
            $itemCount = $chapter->items()->count();
            $chapter->items()->delete();
            $chapter->delete();

            Log::info('Chapter deleted with items', [
                'chapter_id' => $chapter->id,
                'items_deleted' => $itemCount
            ]);
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Add a paper item to a chapter.
     */
    public function addItem(Request $request, Chapter $chapter): JsonResponse
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Adding item to chapter', [
            'chapter_id' => $chapter->id,
            'user_id' => $request->user()->id
        ]);

        // Check if user has access to this chapter's owner
        $this->authorizeUserAccess($request, $chapter->user_id);

        $data = $request->validate([
            'paper_id'       => ['required', 'exists:papers,id'],
            'source_field'   => ['required', 'in:review_html,key_issue,solution_method_html,related_work_html,input_params_html,hw_sw_html,results_html,advantages_html,limitations_html,remarks_html'],
            'order_index'    => ['nullable', 'integer', 'min:0'],
            'citation_style' => ['nullable', 'string', 'max:32'],
        ]);

        // Check if user has access to the paper's owner
        $paper = Paper::select(
            'id',
            'created_by',
            'review_html',
            'key_issue',
            'solution_method_html',
            'related_work_html',
            'input_params_html',
            'hw_sw_html',
            'results_html',
            'advantages_html',
            'limitations_html',
            'remarks_html'
        )->findOrFail($data['paper_id']);

        // Verify access to paper
        $this->authorizeUserAccess($request, $paper->created_by);

        $field = $data['source_field'];

        $content = $field === 'key_issue'
            ? ($paper->key_issue ?? '')
            : ($paper->{$field} ?? '');
        $content = base64_decode($content);

        $item = ChapterItem::create([
            'chapter_id'    => $chapter->id,
            'paper_id'      => $paper->id,
            'source_field'  => $field,
            'content_html'  => $content,
            'citation_style'=> $data['citation_style'] ?? null,
            'order_index'   => $data['order_index'] ?? 0,
            'created_by'    => $request->user()->id,
            'updated_by'    => $request->user()->id,
        ]);

        Log::info('Chapter item added successfully', [
            'chapter_id' => $chapter->id,
            'item_id' => $item->id,
            'paper_id' => $paper->id
        ]);

        return response()->json(
            $item->load('paper:id,title,authors,year,doi,paper_code'),
            201
        );
    }

    /**
     * Remove an item from a chapter.
     */
    public function removeItem(Chapter $chapter, ChapterItem $item): JsonResponse
    {
        $request = request();
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Removing item from chapter', [
            'chapter_id' => $chapter->id,
            'item_id' => $item->id,
            'user_id' => $request->user()->id
        ]);

        // Check if user has access to this chapter's owner
        $this->authorizeUserAccess($request, $chapter->user_id);

        if ($item->chapter_id !== $chapter->id) {
            Log::warning('Item does not belong to chapter', [
                'chapter_id' => $chapter->id,
                'item_chapter_id' => $item->chapter_id,
                'item_id' => $item->id
            ]);
            abort(404);
        }

        $item->delete();

        Log::info('Chapter item removed successfully', [
            'chapter_id' => $chapter->id,
            'item_id' => $item->id
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Reorder chapters in bulk.
     */
    public function reorder(Request $request): JsonResponse
    {
        $userId = $request->user()->id ?? abort(401, 'Unauthenticated');

        Log::info('Chapter reorder called', [
            'user_id' => $userId
        ]);

        $data = $request->validate([
            'items'               => ['required', 'array', 'min:1'],
            'items.*.id'          => ['required', 'integer', 'exists:chapters,id'],
            'items.*.order_index' => ['required', 'integer', 'min:0'],
        ]);

        $ids = collect($data['items'])->pluck('id');

        // Get accessible user IDs
        $userIds = $this->resolveApiUserIds($request);

        // Fetch chapters that belong to accessible users
        $chapters = Chapter::whereIn('user_id', $userIds)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($chapters->count() !== $ids->count()) {
            Log::warning('Some chapters do not belong to accessible users', [
                'requested_ids' => $ids->toArray(),
                'found_count' => $chapters->count(),
                'requested_count' => $ids->count()
            ]);
            abort(403, 'One or more chapters do not belong to accessible users.');
        }

        DB::transaction(function () use ($data, $chapters, $userId) {
            foreach ($data['items'] as $row) {
                $chapters[$row['id']]->update([
                    'order_index' => $row['order_index'],
                    'updated_by'  => $userId,
                ]);
            }
        });

        Log::info('Chapters reordered successfully', [
            'chapter_count' => count($data['items'])
        ]);

        return response()->json([
            'ok'    => true,
            'items' => Chapter::whereIn('user_id', $userIds)
                ->orderBy('order_index')
                ->get(['id', 'title', 'order_index', 'updated_at', 'created_at']),
        ]);
    }
}