<?php
// app/Http/Controllers/TagController.php
namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Support\ResolvesApiScope;

class TagController extends Controller
{
    use OwnerAuthorizes, ResolvesApiScope;

    public function index(Request $request): JsonResponse
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Tag index called', [
            'user_id' => $request->user()->id,
            'role' => $request->user()->role
        ]);

        // Get accessible user IDs
        $userIds = $this->resolveApiUserIds($request);

        Log::info('Accessible user IDs for tags', [
            'user_ids' => $userIds,
            'count' => count($userIds)
        ]);

        $tags = Tag::whereIn('created_by', $userIds)
            ->orderBy('name')
            ->get();

        Log::info('Tags retrieved', [
            'count' => $tags->count()
        ]);

        return response()->json(['data' => $tags]);
    }

    public function store(Request $request): JsonResponse
    {
        $userId = $request->user()->id ?? abort(401, 'Unauthenticated');

        Log::info('Creating new tag', [
            'user_id' => $userId
        ]);

        $data = $request->validate([
            'name' => 'required|string|max:50'
        ]);

        $tag = Tag::firstOrCreate(
            [
                'name' => $data['name'],
                'created_by' => $userId
            ]
        );

        Log::info('Tag created or found', [
            'tag_id' => $tag->id,
            'tag_name' => $tag->name,
            'was_created' => $tag->wasRecentlyCreated
        ]);

        return response()->json(['data' => $tag], 201);
    }

    public function destroy(Request $request, Tag $tag): JsonResponse
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Tag destroy called', [
            'tag_id' => $tag->id,
            'tag_name' => $tag->name,
            'user_id' => $request->user()->id
        ]);

        // Check if user has access to this tag's owner
        $this->authorizeUserAccess($request, $tag->created_by);

        Log::info('User authorized to delete tag', [
            'tag_id' => $tag->id,
            'tag_owner' => $tag->created_by
        ]);

        $tag->delete();

        Log::info('Tag deleted successfully', [
            'tag_id' => $tag->id
        ]);

        return response()->json(['message' => 'Deleted']);
    }
}