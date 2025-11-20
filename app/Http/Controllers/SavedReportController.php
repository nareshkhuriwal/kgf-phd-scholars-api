<?php
// app/Http/Controllers/SavedReportController.php
namespace App\Http\Controllers;

use App\Models\SavedReport;
use App\Http\Requests\SavedReportRequest;
use App\Http\Requests\BulkDeleteSavedReportsRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Http\Controllers\Concerns\SupervisesResearchers;


class SavedReportController extends Controller
{
    use OwnerAuthorizes, SupervisesResearchers;

    // LIST with search + pagination (scoped to owner)
    public function index(Request $req)
    {
        // $q = SavedReport::query()
        //     ->where('created_by', $req->user()->id);

        $visibleUserIds = $this->visibleUserIdsForCurrent($req);

        $q = SavedReport::query()
            ->whereIn('created_by', $visibleUserIds);
            
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
        return response()->json($q->orderBy($sort,$dir)->paginate($per));
    }

    // READ one (owner-guarded)
    public function show($id)
    {
        $row = SavedReport::findOrFail($id);
        $this->authorizeOwner($row, 'created_by');

        return response()->json($row);
    }

    public function store(SavedReportRequest $req)
    {
        Log::info('[SavedReportController@store] hit');
        $data = $req->validated();
        Log::debug('[SavedReportController@store] validated', $data);

        $data['created_by'] = optional($req->user())->id;
        $data['updated_by'] = optional($req->user())->id;

        $row = SavedReport::create($data);
        Log::info('[SavedReportController@store] created', ['id' => $row->id]);

        return response()->json($row, 201);
    }

    // UPDATE (owner-guarded)
    public function update(SavedReportRequest $req, $id)
    {
        Log::info('[SavedReportController@update] hit', ['id' => $id]);

        $row  = SavedReport::findOrFail($id);
        $this->authorizeOwner($row, 'created_by');

        $data = $req->validated();
        Log::debug('[SavedReportController@update] validated', $data);

        $data['updated_by'] = optional($req->user())->id;

        $row->update($data);
        Log::info('[SavedReportController@update] updated', ['id' => $row->id]);

        return response()->json($row->refresh());
    }

    // DELETE one (owner-guarded)
    public function destroy($id)
    {
        $row = SavedReport::findOrFail($id);
        $this->authorizeOwner($row, 'created_by');

        $row->delete();
        return response()->json(['ok' => true, 'deleted' => (int)$id]);
    }

    // BULK DELETE (owner-guarded per-id)
    public function bulkDestroy(BulkDeleteSavedReportsRequest $req)
    {
        $userId = auth()->id();
        $ids = $req->validated()['ids'];

        // Delete only the current user's rows; ignore others silently
        $ownedIds = SavedReport::whereIn('id', $ids)
            ->where('created_by', $userId)
            ->pluck('id')
            ->all();

        SavedReport::whereIn('id', $ownedIds)->delete();

        return response()->json(['ok'=>true,'deleted'=>$ownedIds]);
    }
}
