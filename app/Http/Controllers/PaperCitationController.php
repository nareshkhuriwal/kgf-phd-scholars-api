<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Citation;
use Illuminate\Http\Request;

class PaperCitationController extends Controller
{
    public function search($paperId, Request $req)
    {
        $q = trim((string) $req->query('q', ''));

        // Find the review for the paper (same assumption as your render controller)
        $review = Review::where('paper_id', $paperId)->first();

        // If no review yet, just return citations without order
        if (!$review) {
            return Citation::with('type')
                ->when($q !== '', function ($query) use ($q) {
                    $query->where(function ($w) use ($q) {
                        $w->where('title', 'like', "%{$q}%")
                          ->orWhere('authors', 'like', "%{$q}%")
                          ->orWhere('doi', 'like', "%{$q}%");
                    });
                })
                ->orderBy('year', 'desc')
                ->limit(50)
                ->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'title' => $c->title,
                    'authors' => $c->authors,
                    'year' => $c->year,
                    'doi' => $c->doi,
                    'type' => optional($c->type)->code,
                    'order' => null, // not used in this paper yet
                ]);
        }

        // Return citations + pivot order (if already used in this review)
        $rows = Citation::query()
            ->with('type')
            ->leftJoin('review_citations as rc', function ($join) use ($review) {
                $join->on('rc.citation_id', '=', 'citations.id')
                     ->where('rc.review_id', '=', $review->id);
            })
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('citations.title', 'like', "%{$q}%")
                      ->orWhere('citations.authors', 'like', "%{$q}%")
                      ->orWhere('citations.doi', 'like', "%{$q}%");
                });
            })
            ->orderByRaw('rc.first_used_order is null')     // used citations first
            ->orderBy('rc.first_used_order', 'asc')        // 1,2,3...
            ->orderBy('citations.year', 'desc')
            ->limit(50)
            ->get([
                'citations.*',
                'rc.first_used_order as order',
            ]);

        return $rows->map(fn ($c) => [
            'id' => $c->id,
            'title' => $c->title,
            'authors' => $c->authors,
            'year' => $c->year,
            'doi' => $c->doi,
            'type' => optional($c->type)->code,
            'order' => $c->order ? (int) $c->order : null,
        ])->values();
    }
}