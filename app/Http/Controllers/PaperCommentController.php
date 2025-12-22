<?php

namespace App\Http\Controllers;

use App\Models\Paper;
use App\Models\PaperComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\PaperCommentResource;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Support\ResolvesApiScope;

class PaperCommentController extends Controller
{
    use OwnerAuthorizes, ResolvesApiScope;

    public function index(Request $req, Paper $paper)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('PaperComment index called', [
            'paper_id' => $paper->id,
            'user_id' => $req->user()->id
        ]);

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($req, $paper->created_by);

        Log::info('User authorized to view paper comments', [
            'paper_id' => $paper->id,
            'paper_owner' => $paper->created_by
        ]);

        $rows = $paper->comments()->with('user')->orderBy('created_at')->get();

        Log::info('Paper comments retrieved', [
            'paper_id' => $paper->id,
            'comment_count' => $rows->count()
        ]);

        return PaperCommentResource::collection($rows);
    }

    public function store(Request $req, Paper $paper)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('PaperComment store called', [
            'paper_id' => $paper->id,
            'user_id' => $req->user()->id
        ]);

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($req, $paper->created_by);

        $data = $req->validate([
            'body' => ['required','string'],
            'parent_id' => ['nullable','integer','exists:paper_comments,id'],
        ]);

        $c = PaperComment::create([
            'paper_id'  => $paper->id,
            'user_id'   => $req->user()->id,
            'parent_id' => $data['parent_id'] ?? null,
            'body'      => $data['body'],
        ]);
        $c->load('user');

        Log::info('Paper comment created', [
            'paper_id' => $paper->id,
            'comment_id' => $c->id,
            'is_reply' => !empty($data['parent_id'])
        ]);

        return (new PaperCommentResource($c))->response()->setStatusCode(201);
    }

    public function update(Request $req, Paper $paper, PaperComment $comment)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('PaperComment update called', [
            'paper_id' => $paper->id,
            'comment_id' => $comment->id,
            'user_id' => $req->user()->id
        ]);

        // Ensure comment belongs to this paper
        if ($comment->paper_id !== $paper->id) {
            Log::warning('Comment does not belong to paper', [
                'paper_id' => $paper->id,
                'comment_paper_id' => $comment->paper_id,
                'comment_id' => $comment->id
            ]);
            abort(404);
        }

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($req, $paper->created_by);

        $data = $req->validate([
            'body' => ['required','string']
        ]);

        $comment->update(['body' => $data['body']]);
        $comment->load('user');

        Log::info('Paper comment updated', [
            'paper_id' => $paper->id,
            'comment_id' => $comment->id
        ]);

        return new PaperCommentResource($comment);
    }

    public function destroy(Request $req, Paper $paper, PaperComment $comment)
    {
        $req->user() ?? abort(401, 'Unauthenticated');

        Log::info('PaperComment destroy called', [
            'paper_id' => $paper->id,
            'comment_id' => $comment->id,
            'user_id' => $req->user()->id
        ]);

        // Ensure comment belongs to this paper
        if ($comment->paper_id !== $paper->id) {
            Log::warning('Comment does not belong to paper', [
                'paper_id' => $paper->id,
                'comment_paper_id' => $comment->paper_id,
                'comment_id' => $comment->id
            ]);
            abort(404);
        }

        // Check if user has access to this paper's owner
        $this->authorizeUserAccess($req, $paper->created_by);

        $comment->delete();

        Log::info('Paper comment deleted', [
            'paper_id' => $paper->id,
            'comment_id' => $comment->id
        ]);

        return response()->json(['ok' => true]);
    }
}