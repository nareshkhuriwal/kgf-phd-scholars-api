<?php

namespace App\Http\Controllers;

use App\Models\Paper;
use App\Models\PaperComment;
use Illuminate\Http\Request;
use App\Http\Resources\PaperCommentResource;
use App\Http\Controllers\Concerns\OwnerAuthorizes;

class PaperCommentController extends Controller
{
    use OwnerAuthorizes;

    public function index(Request $req, Paper $paper)
    {
        // Only the owner of the paper can list comments
        $this->authorizeOwner($paper, 'created_by');

        $rows = $paper->comments()->with('user')->orderBy('created_at')->get();
        return PaperCommentResource::collection($rows);
    }

    public function store(Request $req, Paper $paper)
    {
        // Only the owner of the paper can add comments (owner-scoped API)
        $this->authorizeOwner($paper, 'created_by');

        $data = $req->validate([
            'body' => ['required','string'],
            'parent_id' => ['nullable','integer','exists:paper_comments,id'],
        ]);

        $c = PaperComment::create([
            'paper_id'  => $paper->id,
            'user_id'   => $req->user()->id ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'body'      => $data['body'],
        ]);
        $c->load('user');

        return (new PaperCommentResource($c))->response()->setStatusCode(201);
    }

    public function update(Request $req, Paper $paper, PaperComment $comment)
    {
        // Ensure comment belongs to this paper
        if ($comment->paper_id !== $paper->id) abort(404);

        // Only the owner of the paper can edit comments
        $this->authorizeOwner($paper, 'created_by');

        $data = $req->validate([
            'body' => ['required','string']
        ]);

        $comment->update(['body' => $data['body']]);
        $comment->load('user');

        return new PaperCommentResource($comment);
    }

    public function destroy(Request $req, Paper $paper, PaperComment $comment)
    {
        // Ensure comment belongs to this paper
        if ($comment->paper_id !== $paper->id) abort(404);

        // Only the owner of the paper can delete comments
        $this->authorizeOwner($paper, 'created_by');

        $comment->delete();
        return response()->json(['ok' => true]);
    }
}
