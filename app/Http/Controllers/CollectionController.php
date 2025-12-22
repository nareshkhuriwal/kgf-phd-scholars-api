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
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Support\ResolvesApiScope;

class CollectionController extends Controller
{
    use OwnerAuthorizes, ResolvesApiScope;

    /** GET /api/collections?search=&per_page=25 */
    public function index(Request $req)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Collection index called', [
            'user_id' => $req->user()->id,
            'role' => $req->user()->role
        ]);

        // Get accessible user IDs
        $userIds = $this->resolveApiUserIds($req);

        Log::info('Accessible user IDs for collections', [
            'user_ids' => $userIds,
            'count' => count($userIds)
        ]);

        $q = Collection::query()
            ->whereIn('user_id', $userIds)
            ->withCount(['items as paper_count']);

        if ($s = trim((string) $req->get('search', ''))) {
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%{$s}%")
                  ->orWhere('description', 'like', "%{$s}%");
            });
        }

        $per = min(max((int) $req->integer('per_page', 25), 1), 100);
        $p   = $q->latest('id')->paginate($per);

        Log::info('Collections retrieved', [
            'count' => $p->total()
        ]);

        return CollectionResource::collection($p);
    }

    public function show(Request $req, Collection $collection)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Collection show called', [
            'collection_id' => $collection->id,
            'user_id' => $req->user()->id
        ]);

        // Check if user has access to this collection's owner
        $this->authorizeUserAccess($req, $collection->user_id);

        Log::info('User authorized to view collection', [
            'collection_id' => $collection->id,
            'collection_owner' => $collection->user_id
        ]);

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
        $userId = $req->user()->id ?? abort(401, 'Unauthenticated');

        Log::info('Creating new collection', [
            'user_id' => $userId
        ]);

        $c = Collection::create([
            ...$req->validated(),
            'user_id' => $userId,
        ]);

        Log::info('Collection created successfully', [
            'collection_id' => $c->id
        ]);

        return (new CollectionResource($c->loadCount(['items as paper_count'])))
            ->response()->setStatusCode(201);
    }

    /** PUT /api/collections/{collection} */
    public function update(CollectionRequest $req, Collection $collection)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Collection update called', [
            'collection_id' => $collection->id,
            'user_id' => $req->user()->id
        ]);

        // Check if user has access to this collection's owner
        $this->authorizeUserAccess($req, $collection->user_id);

        $collection->update($req->validated());

        Log::info('Collection updated successfully', [
            'collection_id' => $collection->id
        ]);

        return new CollectionResource($collection->fresh()->loadCount(['items as paper_count']));
    }

    /** DELETE /api/collections/{collection} */
    public function destroy(Request $req, Collection $collection)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Collection destroy called', [
            'collection_id' => $collection->id,
            'user_id' => $req->user()->id
        ]);

        // Check if user has access to this collection's owner
        $this->authorizeUserAccess($req, $collection->user_id);

        $itemCount = $collection->items()->count();
        $collection->delete();

        Log::info('Collection deleted successfully', [
            'collection_id' => $collection->id,
            'items_deleted' => $itemCount
        ]);

        return response()->json(['ok' => true]);
    }

    /** GET /api/collections/{collection}/papers  (paginated list of items) */
    public function papers(Request $req, Collection $collection)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Collection papers called', [
            'collection_id' => $collection->id,
            'user_id' => $req->user()->id
        ]);

        // Check if user has access to this collection's owner
        $this->authorizeUserAccess($req, $collection->user_id);

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

        Log::info('Collection papers retrieved', [
            'collection_id' => $collection->id,
            'paper_count' => $rows->count()
        ]);

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /api/collections/{collection}/items
     * body: { paper_id }  OR  { paper_ids: [1,2,3] }  (+ optional notes_html, position)
     */
    public function addItem(AddItemsRequest $req, Collection $collection)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Adding items to collection', [
            'collection_id' => $collection->id,
            'user_id' => $req->user()->id
        ]);

        // Check if user has access to this collection's owner
        $this->authorizeUserAccess($req, $collection->user_id);

        $paperIds = [];
        if ($req->filled('paper_id'))  $paperIds[] = (int) $req->input('paper_id');
        if ($req->filled('paper_ids')) $paperIds   = array_merge($paperIds, (array) $req->input('paper_ids', []));

        $paperIds = array_values(array_unique(array_filter($paperIds)));
        if (!$paperIds) {
            Log::warning('No paper IDs supplied for collection item addition');
            return response()->json(['message' => 'No paper IDs supplied'], 422);
        }

        // Verify user has access to all papers being added
        $papers = Paper::whereIn('id', $paperIds)->get(['id', 'created_by']);
        foreach ($papers as $paper) {
            if (!$this->canAccessUser($req, $paper->created_by)) {
                Log::warning('User attempted to add inaccessible paper to collection', [
                    'user_id' => $req->user()->id,
                    'paper_id' => $paper->id,
                    'paper_owner' => $paper->created_by
                ]);
                abort(403, "You don't have access to add paper ID {$paper->id}");
            }
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

        Log::info('Items added to collection successfully', [
            'collection_id' => $collection->id,
            'paper_count' => count($paperIds)
        ]);

        // Return the latest paginated list
        $items = $collection->items()->with('paper:id,title,authors,year,doi,paper_code')->paginate(100);
        return CollectionItemResource::collection($items)->additional(['ok' => true]);
    }

    /** DELETE /api/collections/{collection}/items/{paper} */
    public function removeItem(Request $req, Collection $collection, Paper $paper)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Removing item from collection', [
            'collection_id' => $collection->id,
            'paper_id' => $paper->id,
            'user_id' => $req->user()->id
        ]);

        // Check if user has access to this collection's owner
        $this->authorizeUserAccess($req, $collection->user_id);

        CollectionItem::where('collection_id', $collection->id)
            ->where('paper_id', $paper->id)
            ->delete();

        Log::info('Item removed from collection successfully', [
            'collection_id' => $collection->id,
            'paper_id' => $paper->id
        ]);

        return response()->json(['ok' => true, 'paper_id' => $paper->id]);
    }

    /**
     * PUT /api/collections/{collection}/reorder
     * body: { order: [paperId1, paperId2, ...] }
     */
    public function reorder(Request $req, Collection $collection)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('Collection reorder called', [
            'collection_id' => $collection->id,
            'user_id' => $req->user()->id
        ]);

        // Check if user has access to this collection's owner
        $this->authorizeUserAccess($req, $collection->user_id);

        $order = $req->input('order', []);
        if (!is_array($order) || empty($order)) {
            Log::warning('Invalid order array provided');
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

        Log::info('Collection reordered successfully', [
            'collection_id' => $collection->id,
            'item_count' => count($order)
        ]);

        return response()->json(['ok' => true]);
    }
}