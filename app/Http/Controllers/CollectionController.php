<?php
// app/Http/Controllers/CollectionController.php
namespace App\Http\Controllers;

use App\Http\Requests\CollectionRequest;
use App\Http\Requests\Collections\AddItemsRequest;
use App\Http\Resources\CollectionResource;
use App\Http\Resources\CollectionItemResource;
use App\Models\Collection;
use App\Models\CollectionItem;
use App\Models\Paper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Concerns\OwnerAuthorizes;

class CollectionController extends Controller
{
    use OwnerAuthorizes;

    /** GET /api/collections?search=&per_page=25 */
    public function index(Request $req)
    {
        $q = Collection::query()
            ->ownedBy($req->user()->id)
            ->withCount(['items as paper_count']);

        if ($s = trim((string) $req->get('search', ''))) {
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%");
            });
        }

        $per = min(max((int) $req->integer('per_page', 25), 1), 100);
        $p   = $q->latest('id')->paginate($per);

        return CollectionResource::collection($p);
    }

      public function show(Request $req, Collection $collection) {
        $this->authorizeOwner($collection);

        $collection->load([
            'items.paper' => function ($q) {
                $q->select('id','title','authors','year','doi','paper_code');
            },
            'items.paper.files'     // to expose first file url in the resource
        ])->loadCount(['items as paper_count']);

        return new CollectionResource($collection);
    }

    /** POST /api/collections */
    public function store(CollectionRequest $req)
    {
        $c = Collection::create([
            ...$req->validated(),
            'user_id' => $req->user()->id,
        ]);

        return (new CollectionResource($c->loadCount(['items as paper_count'])))
            ->response()->setStatusCode(201);
    }


    /** PUT /api/collections/{collection} */
    public function update(CollectionRequest $req, Collection $collection)
    {
        $this->authorizeOwner($collection);
        $collection->update($req->validated());
        return new CollectionResource($collection->fresh()->loadCount(['items as paper_count']));
    }

    /** DELETE /api/collections/{collection} */
    public function destroy(Collection $collection)
    {
        $this->authorizeOwner($collection);
        $collection->delete();
        return response()->json(['ok' => true]);
    }

    /** GET /api/collections/{collection}/papers  (paginated list of items) */
public function papers(Request $req, Collection $collection)
{
    $this->authorizeOwner($collection);

    $collection->load(['items.paper:id,title,authors,year,doi,paper_code','items.paper.files']);

    $rows = $collection->items->map(function ($it) {
        $p = $it->paper;
        $file = $p?->files?->first();
        return [
            'id'         => $p->id,
            'title'      => $p->title,
            'authors'    => $p->authors,
            'year'       => $p->year,
            'doi'        => $p->doi,
            'paper_code' => $p->paper_code,
            'pdf_url'    => $file?->url ?? $p?->pdf_url,
            'notes_html' => $it->notes_html,
            'position'   => $it->position,
        ];
    });

    return response()->json(['data' => $rows]);
}


    /**
     * POST /api/collections/{collection}/items
     * body: { paper_id }  OR  { paper_ids: [1,2,3] }  (+ optional notes_html, position)
     */
    public function addItem(AddItemsRequest $req, Collection $collection)
    {
        $this->authorizeOwner($collection);

        $paperIds = [];
        if ($req->filled('paper_id'))  $paperIds[] = (int) $req->input('paper_id');
        if ($req->filled('paper_ids')) $paperIds   = array_merge($paperIds, (array) $req->input('paper_ids', []));

        $paperIds = array_values(array_unique(array_filter($paperIds)));
        if (!$paperIds) {
            return response()->json(['message' => 'No paper IDs supplied'], 422);
        }

        DB::transaction(function () use ($req, $collection, $paperIds) {
            $maxPos = (int) $collection->items()->max('position');

            foreach ($paperIds as $pid) {
                CollectionItem::updateOrCreate(
                    ['collection_id' => $collection->id, 'paper_id' => $pid],
                    [
                        'notes_html' => $req->input('notes_html'),
                        'position'   => $req->filled('position') ? (int) $req->input('position') : ++$maxPos,
                        'added_by'   => $req->user()->id,
                    ]
                );
            }
        });

        // Return the latest paginated list
        $items = $collection->items()->with('paper:id,title,authors,year,doi,paper_code')->paginate(100);
        return CollectionItemResource::collection($items)->additional(['ok' => true]);
    }

    /** DELETE /api/collections/{collection}/items/{paper} */
    public function removeItem(Collection $collection, Paper $paper)
    {
        $this->authorizeOwner($collection);

        CollectionItem::where('collection_id', $collection->id)
            ->where('paper_id', $paper->id)
            ->delete();

        return response()->json(['ok' => true, 'paper_id' => $paper->id]);
    }

    /**
     * PUT /api/collections/{collection}/reorder
     * body: { order: [paperId1, paperId2, ...] }
     */
    public function reorder(Collection $collection, Request $req)
    {
        $this->authorizeOwner($collection);

        $order = $req->input('order', []);
        if (!is_array($order) || empty($order)) {
            return response()->json(['message' => 'Order array required'], 422);
        }

        DB::transaction(function () use ($collection, $order) {
            $pos = 1;
            foreach ($order as $pid) {
                CollectionItem::where('collection_id', $collection->id)
                    ->where('paper_id', (int) $pid)
                    ->update(['position' => $pos++]);
            }
        });

        return response()->json(['ok' => true]);
    }

}
