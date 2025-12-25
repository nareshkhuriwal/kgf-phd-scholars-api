<?php
// app/Http/Controllers/CitationRenderController.php
namespace App\Http\Controllers;

use App\Models\Review;
use App\Services\CitationFormatter;

class CitationRenderController extends Controller
{
    public function ieee($paperId)
    {
        $review = Review::where('paper_id', $paperId)
            ->with('citations')
            ->firstOrFail();

        return $review->citations->values()->map(fn($c, $i) => [
            'key' => $c->citation_key,
            'text' => CitationFormatter::ieee($c, $i + 1)
        ]);
    }
}
