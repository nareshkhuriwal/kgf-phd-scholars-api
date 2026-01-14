<?php
// app/Http/Controllers/CitationRenderController.php
namespace App\Http\Controllers;

use App\Models\Review;
use App\Services\CitationFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CitationRenderController extends Controller
{
    /**
     * Get citations in specified format
     */
    public function index($paperId, Request $request)
    {
        Log::info('=== Citation Request Started ===', [
            'paper_id' => $paperId,
            'requested_style' => $request->query('style'),
            'query_params' => $request->all(),
            'url' => $request->fullUrl(),
        ]);

        $style = $request->query('style', 'mla'); // Default to MLA
        
        Log::info('Style determined', [
            'style' => $style,
            'default_used' => !$request->has('style')
        ]);

        // Validate style
        $availableStyles = array_keys(CitationFormatter::getAvailableStyles());
        
        if (!in_array(strtolower($style), $availableStyles)) {
            Log::warning('Invalid citation style requested', [
                'requested_style' => $style,
                'available_styles' => $availableStyles,
                'paper_id' => $paperId,
            ]);

            return response()->json([
                'error' => 'Invalid citation style',
                'available_styles' => CitationFormatter::getAvailableStyles()
            ], 400);
        }

        Log::info('Style validated successfully', ['style' => $style]);

        // Find review - handle both paper_id and id
        Log::info('Searching for review', [
            'paper_id' => $paperId,
            'search_criteria' => 'paper_id OR id'
        ]);

        $review = Review::where('paper_id', $paperId)
            ->with('citations')
            ->first();

        if (!$review) {
            Log::warning('Review not found', [
                'paper_id' => $paperId,
                'attempted_search' => ['paper_id', 'id']
            ]);

            return response()->json([
                'style' => $style,
                'count' => 0,
                'citations' => [],
                'message' => 'No review found for this paper'
            ], 404);
        }

        Log::info('Review found', [
            'review_id' => $review->id,
            'paper_id' => $review->paper_id,
            'citations_count' => $review->citations ? $review->citations->count() : 0,
            'has_citations_relation' => $review->relationLoaded('citations'),
        ]);

        if (!$review->citations || $review->citations->isEmpty()) {
            Log::info('No citations found for review', [
                'review_id' => $review->id,
                'paper_id' => $paperId,
                'style' => $style,
            ]);

            return response()->json([
                'style' => $style,
                'count' => 0,
                'citations' => [],
                'message' => 'No citations found for this review'
            ]);
        }

        Log::info('Formatting citations', [
            'citations_count' => $review->citations->count(),
            'style' => $style,
            'citation_keys' => $review->citations->pluck('citation_key')->toArray(),
        ]);

        $citations = $review->citations->map(function($c, $i) use ($style) {
            Log::debug('Formatting citation', [
                'index' => $i + 1,
                'citation_key' => $c->citation_key,
                'citation_id' => $c->id,
                'style' => $style,
                'has_authors' => !empty($c->authors),
                'has_title' => !empty($c->title),
                'has_year' => !empty($c->year),
            ]);

            try {
                $formattedText = CitationFormatter::format($c, $style, $i + 1);
                
                Log::debug('Citation formatted successfully', [
                    'citation_key' => $c->citation_key,
                    'formatted_length' => strlen($formattedText),
                ]);

                return [
                    'key' => $c->citation_key,
                    'text' => $formattedText,
                    'citation_id' => $c->id,
                    'authors' => $c->authors,
                    'title' => $c->title,
                    'year' => $c->year,
                ];
            } catch (\Exception $e) {
                Log::error('Error formatting citation', [
                    'citation_key' => $c->citation_key,
                    'citation_id' => $c->id,
                    'style' => $style,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return [
                    'key' => $c->citation_key,
                    'text' => 'Error formatting citation',
                    'citation_id' => $c->id,
                    'error' => $e->getMessage(),
                ];
            }
        })->values();

        Log::info('=== Citation Request Completed ===', [
            'paper_id' => $paperId,
            'style' => $style,
            'citations_count' => $citations->count(),
            'success' => true,
        ]);

        return response()->json([
            'style' => $style,
            'count' => $citations->count(),
            'citations' => $citations
        ]);
    }

    /**
     * Get available citation styles
     */
    public function styles()
    {
        Log::info('Fetching available citation styles');

        $styles = CitationFormatter::getAvailableStyles();

        Log::info('Citation styles retrieved', [
            'count' => count($styles),
            'styles' => array_keys($styles),
        ]);

        return response()->json([
            'styles' => $styles
        ]);
    }

    /**
     * Helper method to get citations by style
     */
    protected function getByStyle($paperId, $style)
    {
        Log::info('getByStyle called', [
            'paper_id' => $paperId,
            'style' => $style,
            'method' => 'specific_endpoint'
        ]);

        $review = Review::where('paper_id', $paperId)
            ->orWhere('id', $paperId)
            ->with('citations')
            ->first();

        if (!$review || !$review->citations) {
            Log::warning('No review or citations found in getByStyle', [
                'paper_id' => $paperId,
                'style' => $style,
            ]);

            return response()->json([
                'style' => $style,
                'count' => 0,
                'citations' => [],
                'message' => 'No citations found'
            ]);
        }

        Log::info('Formatting citations in getByStyle', [
            'paper_id' => $paperId,
            'style' => $style,
            'citations_count' => $review->citations->count(),
        ]);

        $citations = $review->citations->map(function($c, $i) use ($style) {
            return [
                'key' => $c->citation_key,
                'text' => CitationFormatter::format($c, $style, $i + 1),
                'citation_id' => $c->id,
            ];
        })->values();

        Log::info('getByStyle completed', [
            'paper_id' => $paperId,
            'style' => $style,
            'citations_returned' => $citations->count(),
        ]);

        return response()->json([
            'style' => $style,
            'count' => $citations->count(),
            'citations' => $citations
        ]);
    }

    // Specific format methods
    public function ieee($paperId) { 
        Log::info('IEEE endpoint called', ['paper_id' => $paperId]);
        return $this->getByStyle($paperId, 'ieee'); 
    }
    
    public function apa($paperId) { 
        Log::info('APA endpoint called', ['paper_id' => $paperId]);
        return $this->getByStyle($paperId, 'apa'); 
    }
    
    public function mla($paperId) { 
        Log::info('MLA endpoint called', ['paper_id' => $paperId]);
        return $this->getByStyle($paperId, 'mla'); 
    }
    
    public function chicago($paperId) { 
        Log::info('Chicago endpoint called', ['paper_id' => $paperId]);
        return $this->getByStyle($paperId, 'chicago'); 
    }
    
    public function harvard($paperId) { 
        Log::info('Harvard endpoint called', ['paper_id' => $paperId]);
        return $this->getByStyle($paperId, 'harvard'); 
    }
    
    public function vancouver($paperId) { 
        Log::info('Vancouver endpoint called', ['paper_id' => $paperId]);
        return $this->getByStyle($paperId, 'vancouver'); 
    }
    
    public function acm($paperId) { 
        Log::info('ACM endpoint called', ['paper_id' => $paperId]);
        return $this->getByStyle($paperId, 'acm'); 
    }
    
    public function springer($paperId) { 
        Log::info('Springer endpoint called', ['paper_id' => $paperId]);
        return $this->getByStyle($paperId, 'springer'); 
    }
}