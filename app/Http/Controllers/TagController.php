<?php
// app/Http/Controllers/TagController.php
namespace App\Http\Controllers;

use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Http\Controllers\Concerns\SupervisesResearchers;

class TagController extends Controller
{
        use OwnerAuthorizes, SupervisesResearchers;

    public function index(Request $request): JsonResponse
    {
        $tags = Tag::where('created_by', $request->user()->id)
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $tags]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:50'
        ]);

        $tag = Tag::firstOrCreate(
            [
                'name' => $data['name'],
                'created_by' => $request->user()->id
            ]
        );

        return response()->json(['data' => $tag], 201);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        $this->authorizeOwner($tag, 'created_by');
        $tag->delete();

        return response()->json(['message' => 'Deleted']);
    }
}
