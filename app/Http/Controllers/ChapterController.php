<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChapterRequest;
use App\Models\Chapter;
use App\Models\ChapterItem;
use App\Models\Paper;
use Illuminate\Http\Request;
use App\Http\Resources\ChapterOptionResource;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use Illuminate\Support\Facades\DB;

class ChapterController extends Controller
{
    use OwnerAuthorizes;

    public function index(Request $req) {
        $q = Chapter::query()->where('user_id',$req->user()->id)->withCount('items');
        if ($cid = $req->get('collection_id')) $q->where('collection_id',$cid);
        return $q->orderBy('order_index')->paginate($req->integer('per_page',25));
    }

    public function chapterOptions(Request $req)
    {
        $q = Chapter::query();

        if ($s = $req->get('search')) {
            $q->where('title', 'like', "%{$s}%");
        }

        $per = $req->get('per_page', 100);
        if ($per === 'all') {
            return ChapterOptionResource::collection($q->orderBy('title')->get());
        }

        $chapters = $q->orderBy('title')->paginate((int)$per);
        return ChapterOptionResource::collection($chapters);
    }


    public function store(ChapterRequest $req) {
        $data = $req->validated();
        $data['user_id'] = $req->user()->id;
        $ch = Chapter::create($data);
        return response()->json($ch, 201);
    }

    public function show(Chapter $chapter) {
        $this->authorizeOwner($chapter);
        return $chapter->load('items.paper:id,title,authors,year,doi,paper_code');
    }

    public function update(ChapterRequest $req, Chapter $chapter) {
        $this->authorizeOwner($chapter);
        $chapter->update($req->validated());
        return $chapter->fresh();
    }

    public function destroy(Chapter $chapter) {
        $this->authorizeOwner($chapter);
        $chapter->delete();
        return ['ok'=>true];
    }

    public function addItem(Request $req, Chapter $chapter) {
        $this->authorizeOwner($chapter);
        $data = $req->validate([
            'paper_id' => ['required','exists:papers,id'],
            'source_field' => ['required','in:review_html,key_issue,solution_method_html,related_work_html,input_params_html,hw_sw_html,results_html,advantages_html,limitations_html,remarks_html'],
            'order_index' => ['nullable','integer'],
            'citation_style' => ['nullable','string','max:32'],
        ]);

        $paper = Paper::findOrFail($data['paper_id']);
        $field = $data['source_field'];
        $content = $field === 'key_issue' ? ($paper->key_issue ?? '') : ($paper->$field ?? '');

        $item = ChapterItem::create([
            'chapter_id' => $chapter->id,
            'paper_id' => $paper->id,
            'source_field' => $field,
            'content_html' => $content ?? '',
            'citation_style' => $data['citation_style'] ?? null,
            'order_index' => $data['order_index'] ?? 0,
        ]);

        return response()->json($item->load('paper:id,title,authors,year,doi,paper_code'), 201);
    }

    public function removeItem(Chapter $chapter, ChapterItem $item) {
        $this->authorizeOwner($chapter);
        if ($item->chapter_id !== $chapter->id) abort(404);
        $item->delete();
        return ['ok'=>true];
    }


    public function reorder(Request $request)
    {
        $userId = $request->user()->id;

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:chapters,id'],
            'items.*.order_index' => ['required', 'integer', 'min:0'],
        ]);

        // Fetch only chapters owned by the user
        $chapters = Chapter::where('user_id', $userId)
            ->whereIn('id', collect($data['items'])->pluck('id'))
            ->get()
            ->keyBy('id');

        if ($chapters->count() !== count($data['items'])) {
            abort(403, 'One or more chapters do not belong to the user.');
        }
        DB::transaction(function () use ($data, $chapters) {
            foreach ($data['items'] as $row) {
                $chapters[$row['id']]->update([
                    'order_index' => $row['order_index'],
                ]);
            }
        });

        return response()->json([
            'ok' => true,
            'message' => 'Chapter order updated successfully',
            'items' => Chapter::where('user_id', $userId)
                ->orderBy('order_index')
                ->get(['id', 'title', 'order_index', 'updated_at']),
        ]);

    }

}
