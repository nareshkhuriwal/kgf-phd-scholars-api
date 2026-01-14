<?php

namespace App\Http\Controllers;

use App\Models\AuthoredPaper;
use App\Models\AuthoredPaperComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Support\ResolvesApiScope;

class AuthoredPaperCommentController extends Controller
{
    use OwnerAuthorizes, ResolvesApiScope;

    public function index(Request $req, AuthoredPaper $paper)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('AuthoredPaperComment index called', [
            'paper_id' => $paper->id,
            'user_id' => $req->user()->id
        ]);

        // Author-based access (IMPORTANT DIFFERENCE)
        $this->authorizeUserAccess($req, $paper->user_id);

        Log::info('User authorized to view authored paper comments', [
            'paper_id' => $paper->id,
            'paper_owner' => $paper->user_id
        ]);

        $rows = $paper->comments()
            ->with('user')
            ->orderByRaw('parent_id IS NULL DESC') // top-level comments first
            ->orderByRaw('parent_id IS NOT NULL ASC') // replies after parents
            ->orderByRaw('
        CASE 
            WHEN parent_id IS NULL THEN created_at 
            ELSE NULL 
        END DESC
    ')
            ->orderByRaw('
        CASE 
            WHEN parent_id IS NOT NULL THEN created_at 
            ELSE NULL 
        END ASC
    ')
            ->get();


        return $rows;
    }

    public function store(Request $req, AuthoredPaper $paper)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('AuthoredPaperComment store called', [
            'paper_id' => $paper->id,
            'user_id' => $req->user()->id
        ]);

        $this->authorizeUserAccess($req, $paper->user_id);

        $data = $req->validate([
            'body' => ['required', 'string'],
            'parent_id' => ['nullable', 'integer', 'exists:authored_paper_comments,id'],
        ]);

        $c = AuthoredPaperComment::create([
            'authored_paper_id' => $paper->id,
            'user_id' => $req->user()->id,
            'parent_id' => $data['parent_id'] ?? null,
            'body' => $data['body'],
        ]);

        $c->load('user');

        Log::info('Authored paper comment created', [
            'paper_id' => $paper->id,
            'comment_id' => $c->id,
            'is_reply' => !empty($data['parent_id'])
        ]);

        return response()->json($c, 201);
    }

    public function update(Request $req, AuthoredPaper $paper, AuthoredPaperComment $comment)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        if ($comment->authored_paper_id !== $paper->id) {
            abort(404);
        }

        $this->authorizeUserAccess($req, $paper->user_id);

        $data = $req->validate([
            'body' => ['required', 'string'],
        ]);

        $comment->update(['body' => $data['body']]);
        $comment->load('user');

        return $comment;
    }

    public function destroy(Request $req, AuthoredPaper $paper, AuthoredPaperComment $comment)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        if ($comment->authored_paper_id !== $paper->id) {
            abort(404);
        }

        $this->authorizeUserAccess($req, $paper->user_id);

        $comment->delete();

        return response()->json(['ok' => true]);
    }
}
