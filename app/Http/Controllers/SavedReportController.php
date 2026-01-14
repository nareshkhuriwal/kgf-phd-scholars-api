<?php
// app/Http/Controllers/SavedReportController.php
namespace App\Http\Controllers;

use App\Models\SavedReport;
use App\Http\Requests\SavedReportRequest;
use App\Http\Requests\BulkDeleteSavedReportsRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Support\ResolvesApiScope;

class SavedReportController extends Controller
{
    use OwnerAuthorizes, ResolvesApiScope;

    // LIST with search + pagination (scoped to accessible users)
    public function index(Request $req)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('SavedReport index called', [
            'user_id' => $req->user()->id,
            'role' => $req->user()->role
        ]);

        // Get accessible user IDs
        $userIds = $this->resolveApiUserIds($req);

        Log::info('Accessible user IDs for saved reports', [
            'user_ids' => $userIds,
            'count' => count($userIds)
        ]);


        $q = SavedReport::query()
            ->whereIn('created_by', $userIds)
            ->with('creator:id,name,email,role'); // ✅ ADD THIS

            
        if ($s = $req->get('search')) {
            $q->where(function ($w) use ($s) {
                $w->where('name','like',"%{$s}%")
                  ->orWhere('template','like',"%{$s}%")
                  ->orWhere('format','like',"%{$s}%")
                  ->orWhere('filename','like',"%{$s}%");
            });
        }

        $sort = $req->get('sort', 'updated_at');
        $dir  = strtolower($req->get('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowed = ['id','name','template','format','filename','updated_at','created_at'];
        if (!in_array($sort, $allowed, true)) $sort = 'updated_at';

        $per = (int) $req->get('per_page', 25);
        $result = $q->orderBy($sort,$dir)->paginate($per);

        Log::info('Saved reports retrieved', [
            'total' => $result->total(),
            'per_page' => $per
        ]);

        return response()->json($result);
    }

    // READ one (owner-guarded)
    public function show(Request $req, $id)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('SavedReport show called', [
            'report_id' => $id,
            'user_id' => $req->user()->id
        ]);

        $row = SavedReport::findOrFail($id);

        // Check if user has access to this report's owner
        $this->authorizeUserAccess($req, $row->created_by);

        Log::info('User authorized to view saved report', [
            'report_id' => $row->id,
            'report_owner' => $row->created_by
        ]);

        return response()->json($row);
    }

    public function store(SavedReportRequest $req)
    {
        $userId = $req->user()->id ?? abort(401, 'Unauthenticated');

        Log::info('SavedReport store called', [
            'user_id' => $userId
        ]);

        $data = $req->validated();
        Log::debug('SavedReport store validated', $data);

        $data['created_by'] = $userId;
        $data['updated_by'] = $userId;

        $row = SavedReport::create($data);

        Log::info('Saved report created successfully', [
            'report_id' => $row->id,
            'name' => $row->name
        ]);

        return response()->json($row, 201);
    }

    // UPDATE (owner-guarded)
    public function update(SavedReportRequest $req, $id)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('SavedReport update called', [
            'report_id' => $id,
            'user_id' => $req->user()->id
        ]);

        $row  = SavedReport::findOrFail($id);

        // Check if user has access to this report's owner
        $this->authorizeUserAccess($req, $row->created_by);

        $data = $req->validated();
        Log::debug('SavedReport update validated', $data);

        $data['updated_by'] = $req->user()->id;

        $row->update($data);

        Log::info('Saved report updated successfully', [
            'report_id' => $row->id
        ]);

        return response()->json($row->refresh());
    }

    // DELETE one (owner-guarded)
    public function destroy(Request $req, $id)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('SavedReport destroy called', [
            'report_id' => $id,
            'user_id' => $req->user()->id
        ]);

        $row = SavedReport::findOrFail($id);

        // Check if user has access to this report's owner
        $this->authorizeUserAccess($req, $row->created_by);

        Log::info('User authorized to delete saved report', [
            'report_id' => $row->id,
            'report_owner' => $row->created_by
        ]);

        $row->delete();

        Log::info('Saved report deleted successfully', [
            'report_id' => $id
        ]);

        return response()->json(['ok' => true, 'deleted' => (int)$id]);
    }

    // BULK DELETE (accessible user-guarded per-id)
    public function bulkDestroy(BulkDeleteSavedReportsRequest $req)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        $ids = $req->validated()['ids'];

        Log::info('SavedReport bulk destroy called', [
            'user_id' => $req->user()->id,
            'requested_ids' => $ids,
            'count' => count($ids)
        ]);

        // Get accessible user IDs
        $userIds = $this->resolveApiUserIds($req);

        // Delete only accessible user's rows; ignore others silently
        $ownedIds = SavedReport::whereIn('id', $ids)
            ->whereIn('created_by', $userIds)
            ->pluck('id')
            ->all();

        Log::info('Saved reports filtered by accessible users', [
            'requested_count' => count($ids),
            'accessible_count' => count($ownedIds),
            'accessible_ids' => $ownedIds
        ]);

        if (!empty($ownedIds)) {
            SavedReport::whereIn('id', $ownedIds)->delete();

            Log::info('Saved reports bulk deleted successfully', [
                'deleted_count' => count($ownedIds)
            ]);
        } else {
            Log::warning('No accessible saved reports found for bulk deletion');
        }

        return response()->json(['ok'=>true,'deleted'=>$ownedIds]);
    }
}