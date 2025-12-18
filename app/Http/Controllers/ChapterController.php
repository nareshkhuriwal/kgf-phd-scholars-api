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
use App\Http\Controllers\Concerns\OwnerAuthorizes;

class ChapterController extends Controller
{
    use OwnerAuthorizes;

    /**
     * List chapters owned by the current user.
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $query = Chapter::query()
            ->where('user_id', $userId)
            ->withCount('items')
            ->orderBy('order_index');

        if ($collectionId = $request->integer('collection_id')) {
            $query->where('collection_id', $collectionId);
        }

        $perPage = $request->integer('per_page', 25);

        return response()->json(
            $query->paginate($perPage)
        );
    }

    /**
     * Lightweight chapter list for dropdowns / selectors.
     */
    public function chapterOptions(Request $request)
    {
        $query = Chapter::query()
            ->where('user_id', $request->user()->id);

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
        $userId = $request->user()->id;

        $data = $request->validated();
        $data['user_id']    = $userId;
        $data['created_by'] = $userId;
        $data['updated_by'] = $userId;

        $chapter = Chapter::create($data);

        return response()->json($chapter, 201);
    }

    /**
     * Show a single chapter with its items.
     */
    public function show(Chapter $chapter): JsonResponse
    {
        $this->authorizeOwner($chapter);

        return response()->json(
            $chapter->load('items.paper:id,title,authors,year,doi,paper_code,created_at,updated_at')
        );
    }

    /**
     * Update chapter metadata or body.
     */
    public function update(ChapterRequest $request, Chapter $chapter): JsonResponse
    {
        // $this->authorizeOwner($chapter);

        $data = $request->validated();
        $data['updated_by'] = $request->user()->id;

        $chapter->update($data);

        return response()->json($chapter->fresh());
    }

    /**
     * Delete a chapter and its items.
     */
    public function destroy(Chapter $chapter): JsonResponse
    {
        $this->authorizeOwner($chapter);

        DB::transaction(function () use ($chapter) {
            $chapter->items()->delete();
            $chapter->delete();
        });

        return response()->json(['ok' => true]);
    }

    /**
     * Add a paper item to a chapter.
     */
    public function addItem(Request $request, Chapter $chapter): JsonResponse
    {
        $this->authorizeOwner($chapter);

        $data = $request->validate([
            'paper_id'       => ['required', 'exists:papers,id'],
            'source_field'   => ['required', 'in:review_html,key_issue,solution_method_html,related_work_html,input_params_html,hw_sw_html,results_html,advantages_html,limitations_html,remarks_html'],
            'order_index'    => ['nullable', 'integer', 'min:0'],
            'citation_style' => ['nullable', 'string', 'max:32'],
        ]);

        $paper = Paper::select(
            'id',
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

        $field = $data['source_field'];

        $content = $field === 'key_issue'
            ? ($paper->key_issue ?? '')
            : ($paper->{$field} ?? '');

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
        $this->authorizeOwner($chapter);

        if ($item->chapter_id !== $chapter->id) {
            abort(404);
        }

        $item->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Reorder chapters in bulk.
     */
    public function reorder(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'items'               => ['required', 'array', 'min:1'],
            'items.*.id'          => ['required', 'integer', 'exists:chapters,id'],
            'items.*.order_index' => ['required', 'integer', 'min:0'],
        ]);

        $ids = collect($data['items'])->pluck('id');

        $chapters = Chapter::where('user_id', $userId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($chapters->count() !== $ids->count()) {
            abort(403, 'One or more chapters do not belong to the user.');
        }

        DB::transaction(function () use ($data, $chapters, $userId) {
            foreach ($data['items'] as $row) {
                $chapters[$row['id']]->update([
                    'order_index' => $row['order_index'],
                    'updated_by'  => $userId,
                ]);
            }
        });

        return response()->json([
            'ok'    => true,
            'items' => Chapter::where('user_id', $userId)
                ->orderBy('order_index')
                ->get(['id', 'title', 'order_index', 'updated_at', 'created_at']),
        ]);
    }
}
