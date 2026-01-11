<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyHighlightsRequest;
use App\Models\Paper;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use setasign\Fpdi\Tcpdf\Fpdi;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use App\Http\Controllers\Concerns\ResolvesPublicUploads;
use App\Services\AuditLogger;

/**
 * PDF Highlight Controller
 * 
 * Applies highlights to PDF documents with:
 * - Overlap detection and rectangle merging (prevents dark/compounded highlights)
 * - Style-based grouping (only merges highlights with same color/alpha)
 * - Brush stroke optimization (filters closely-spaced points)
 * - Comprehensive logging and audit trail
 * 
 * @version 2.1.0
 */
class PdfHighlightController extends Controller
{
    use OwnerAuthorizes;
    use ResolvesPublicUploads;

    /* =========================================================
     * CONFIGURATION CONSTANTS
     * ========================================================= */

    /** @var int Maximum number of rectangles to process */
    private const MAX_RECTS = 2000;

    /** @var float Default highlight opacity (0.0 - 1.0) */
    private const DEFAULT_ALPHA = 0.25;

    /** @var string Default highlight color (hex) */
    private const DEFAULT_COLOR = '#FFEB3B';

    /** @var float Overlap tolerance for merging (0.5% of page dimension) */
    private const OVERLAP_TOLERANCE = 0.005;

    /** @var float Minimum distance between brush stroke points (0.5% of page) */
    private const MIN_BRUSH_DISTANCE = 0.005;

    /** @var float Minimum rectangle dimension to prevent zero-size rects */
    private const MIN_RECT_SIZE = 0.0001;

    /** @var int Maximum merge iterations (safety limit) */
    private const MAX_MERGE_ITERATIONS = 50;

    /* =========================================================
     * HELPER METHODS
     * ========================================================= */

    /**
     * Clamp value between 0 and 1, returns null for invalid input
     *
     * @param mixed $v Value to clamp
     * @return float|null Clamped value or null if invalid
     */
    private function clamp01($v): ?float
    {
        if (!is_numeric($v)) {
            return null;
        }
        
        $val = (float) $v;
        
        if (!is_finite($val)) {
            return null;
        }
        
        return max(0.0, min(1.0, $val));
    }

    /**
     * Clamp value between min and max
     *
     * @param mixed $v Value to clamp
     * @param float $min Minimum value
     * @param float $max Maximum value
     * @return float Clamped value
     */
    private function clamp($v, float $min, float $max): float
    {
        if (!is_numeric($v)) {
            return $min;
        }
        return max($min, min($max, (float) $v));
    }

    /**
     * Convert hex color to RGB array
     *
     * @param string $hex Hex color string (with or without #)
     * @return array [r, g, b] values (0-255)
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        // Handle 3-character shorthand (e.g., #FFF)
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if (strlen($hex) !== 6) {
            return [255, 235, 59]; // Fallback: yellow
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Generate a style key for grouping rectangles
     * Rectangles with same style key can be merged together
     *
     * @param array|null $style Style array with color and alpha
     * @return string Unique key for this style combination
     */
    private function getStyleKey(?array $style): string
    {
        $color = strtolower($style['color'] ?? self::DEFAULT_COLOR);
        $alpha = round($style['alpha'] ?? self::DEFAULT_ALPHA, 2);
        
        return $color . '_' . $alpha;
    }

    /**
     * Normalize style array with defaults
     *
     * @param array|null $style Raw style input
     * @return array Normalized style with color and alpha
     */
    private function normalizeStyle(?array $style): array
    {
        return [
            'color' => $style['color'] ?? self::DEFAULT_COLOR,
            'alpha' => $this->clamp($style['alpha'] ?? self::DEFAULT_ALPHA, 0.0, 1.0),
        ];
    }

    /* =========================================================
     * BRUSH STROKE PROCESSING
     * ========================================================= */

    /**
     * Convert brush stroke points to bounding rectangle
     * 
     * IMPORTANT: Includes brush size/thickness in the rectangle dimensions.
     * Without this, horizontal strokes become nearly invisible thin lines.
     *
     * @param array $stroke Brush stroke data with points array and size
     * @return array|null Bounding rectangle or null if invalid
     */
    private function brushStrokeToRect(array $stroke): ?array
    {
        $points = $stroke['points'] ?? [];
        
        if (empty($points)) {
            return null;
        }

        // Get brush size (thickness) - DEFAULT to 2% of page if not provided
        // Clamp between 0.5% and 10% of page dimension for safety
        $brushSize = $this->clamp($stroke['size'] ?? 0.02, 0.005, 0.1);

        // Filter out closely-spaced points to prevent micro-overlaps
        $filtered = [];
        $lastPoint = null;

        foreach ($points as $p) {
            if (!isset($p['x'], $p['y'])) {
                continue;
            }

            $x = $this->clamp01($p['x']);
            $y = $this->clamp01($p['y']);

            if ($x === null || $y === null) {
                continue;
            }

            if ($lastPoint === null) {
                $filtered[] = ['x' => $x, 'y' => $y];
                $lastPoint = ['x' => $x, 'y' => $y];
            } else {
                // Calculate Euclidean distance from last point
                $dist = sqrt(
                    pow($x - $lastPoint['x'], 2) +
                    pow($y - $lastPoint['y'], 2)
                );

                // Only add point if it's far enough from the last one
                if ($dist >= self::MIN_BRUSH_DISTANCE) {
                    $filtered[] = ['x' => $x, 'y' => $y];
                    $lastPoint = ['x' => $x, 'y' => $y];
                }
            }
        }

        // Need at least 1 point for a valid stroke (single click should work)
        if (empty($filtered)) {
            return null;
        }

        // Calculate bounding box from filtered points
        $minX = INF;
        $minY = INF;
        $maxX = -INF;
        $maxY = -INF;

        foreach ($filtered as $p) {
            $minX = min($minX, $p['x']);
            $minY = min($minY, $p['y']);
            $maxX = max($maxX, $p['x']);
            $maxY = max($maxY, $p['y']);
        }

        if (!is_finite($minX) || !is_finite($minY)) {
            return null;
        }

        // CRITICAL: Expand bounding box by brush size (half on each side)
        // This ensures the rectangle matches the visual brush stroke thickness
        $halfBrush = $brushSize / 2;
        
        $minX = max(0.0, $minX - $halfBrush);
        $minY = max(0.0, $minY - $halfBrush);
        $maxX = min(1.0, $maxX + $halfBrush);
        $maxY = min(1.0, $maxY + $halfBrush);

        // Ensure minimum dimensions (at least brush size)
        $width = $maxX - $minX;
        $height = $maxY - $minY;
        
        // If stroke is mostly horizontal, ensure minimum height equals brush size
        if ($height < $brushSize) {
            $centerY = ($minY + $maxY) / 2;
            $minY = max(0.0, $centerY - $halfBrush);
            $maxY = min(1.0, $centerY + $halfBrush);
            $height = $maxY - $minY;
        }
        
        // If stroke is mostly vertical, ensure minimum width equals brush size
        if ($width < $brushSize) {
            $centerX = ($minX + $maxX) / 2;
            $minX = max(0.0, $centerX - $halfBrush);
            $maxX = min(1.0, $centerX + $halfBrush);
            $width = $maxX - $minX;
        }

        return [
            'x' => $minX,
            'y' => $minY,
            'w' => max(self::MIN_RECT_SIZE, $width),
            'h' => max(self::MIN_RECT_SIZE, $height),
        ];
    }

    /* =========================================================
     * OVERLAP DETECTION & MERGING
     * ========================================================= */

    /**
     * Check if two rectangles overlap (with tolerance)
     *
     * @param array $r1 First rectangle with x, y, w, h
     * @param array $r2 Second rectangle with x, y, w, h
     * @return bool True if rectangles overlap
     */
    private function rectsOverlap(array $r1, array $r2): bool
    {
        $tolerance = self::OVERLAP_TOLERANCE;

        // Check if rectangles are completely separated
        $separated = (
            $r1['x'] + $r1['w'] + $tolerance < $r2['x'] ||  // r1 left of r2
            $r2['x'] + $r2['w'] + $tolerance < $r1['x'] ||  // r2 left of r1
            $r1['y'] + $r1['h'] + $tolerance < $r2['y'] ||  // r1 above r2
            $r2['y'] + $r2['h'] + $tolerance < $r1['y']     // r2 above r1
        );

        return !$separated;
    }

    /**
     * Merge two overlapping rectangles into their bounding box
     *
     * @param array $r1 First rectangle
     * @param array $r2 Second rectangle
     * @return array Merged rectangle (bounding box of both)
     */
    private function mergeRects(array $r1, array $r2): array
    {
        $minX = min($r1['x'], $r2['x']);
        $minY = min($r1['y'], $r2['y']);
        $maxX = max($r1['x'] + $r1['w'], $r2['x'] + $r2['w']);
        $maxY = max($r1['y'] + $r1['h'], $r2['y'] + $r2['h']);

        return [
            'x' => $minX,
            'y' => $minY,
            'w' => $maxX - $minX,
            'h' => $maxY - $minY,
            'style' => $r1['style'] ?? $r2['style'] ?? [],
        ];
    }

    /**
     * Merge all overlapping rectangles in an array
     * Uses iterative approach until no more overlaps exist
     *
     * @param array $rects Array of rectangles to merge
     * @return array Merged rectangles (no overlaps)
     */
    private function mergeOverlappingRects(array $rects): array
    {
        if (empty($rects)) {
            return [];
        }

        $merged = $rects;
        $didMerge = true;
        $iterations = 0;

        // Keep merging until no more overlaps found
        while ($didMerge && $iterations < self::MAX_MERGE_ITERATIONS) {
            $didMerge = false;
            $newMerged = [];
            $used = array_fill(0, count($merged), false);

            for ($i = 0; $i < count($merged); $i++) {
                if ($used[$i]) {
                    continue;
                }

                $current = $merged[$i];
                $used[$i] = true;

                // Try to merge with all remaining rectangles
                for ($j = $i + 1; $j < count($merged); $j++) {
                    if ($used[$j]) {
                        continue;
                    }

                    if ($this->rectsOverlap($current, $merged[$j])) {
                        $current = $this->mergeRects($current, $merged[$j]);
                        $used[$j] = true;
                        $didMerge = true;
                    }
                }

                $newMerged[] = $current;
            }

            $merged = $newMerged;
            $iterations++;
        }

        // Log warning if we hit the iteration limit
        if ($iterations >= self::MAX_MERGE_ITERATIONS) {
            Log::warning('PDF Highlight: Max merge iterations reached', [
                'iterations' => $iterations,
                'remaining_rects' => count($merged),
            ]);
        }

        return $merged;
    }

    /**
     * Merge overlapping rectangles grouped by style
     * 
     * KEY FIX: Only merge rectangles that have the same color and alpha.
     * This prevents different colored highlights from being incorrectly merged.
     *
     * @param array $rects Array of rectangles with style information
     * @return array Merged rectangles (only same-style overlaps merged)
     */
    private function mergeOverlappingRectsByStyle(array $rects): array
    {
        if (empty($rects)) {
            return [];
        }

        // Group rectangles by their style (color + alpha)
        $groups = [];
        
        foreach ($rects as $rect) {
            $styleKey = $this->getStyleKey($rect['style'] ?? null);
            
            if (!isset($groups[$styleKey])) {
                $groups[$styleKey] = [];
            }
            
            $groups[$styleKey][] = $rect;
        }

        // Merge overlapping rectangles within each style group
        $result = [];
        
        foreach ($groups as $styleKey => $groupRects) {
            $mergedGroup = $this->mergeOverlappingRects($groupRects);
            
            foreach ($mergedGroup as $rect) {
                $result[] = $rect;
            }
        }

        return $result;
    }

    /* =========================================================
     * MAIN APPLY HIGHLIGHTS METHOD
     * ========================================================= */

    /**
     * Apply highlights to a PDF document
     *
     * @param ApplyHighlightsRequest $request Validated request with highlights data
     * @param Paper $paper Paper model instance
     * @return \Illuminate\Http\JsonResponse
     */
    public function apply(ApplyHighlightsRequest $request, Paper $paper)
    {
        $startTime = microtime(true);

        $this->authorizeOwner($paper, 'created_by');

        // Prepare audit payload early (raw user intent)
        $auditPayload = [
            'source_url'       => $request->input('sourceUrl') ?? $request->input('source_url'),
            'replace'          => $request->boolean('replace', false),
            'highlights_count' => count($request->input('highlights', [])),
            'brush_count'      => count($request->input('brushHighlights', [])),
            'payload'         => $request->all(),
        ];

        try {
            /* ---------------------------------------------------------
             * 1. RESOLVE SOURCE PDF
             * --------------------------------------------------------- */
            $sourceUrl = (string) ($request->input('sourceUrl') ?? $request->input('source_url') ?? '');

            if ($sourceUrl) {
                [$srcDisk, $srcPath] = $this->resolveFromUrl($sourceUrl);
            } else {
                [$srcDisk, $srcPath] = $this->resolvePdfDiskAndPath($paper);
            }

            if (!$srcDisk || !$srcPath || !Storage::disk($srcDisk)->exists($srcPath)) {
                throw new \RuntimeException('PDF not found');
            }

            $absIn = Storage::disk($srcDisk)->path($srcPath);
            $replace = $request->boolean('replace', false);

            /* ---------------------------------------------------------
             * 2. NORMALIZE & COLLECT HIGHLIGHTS
             * --------------------------------------------------------- */
            $cleanByPage = [];
            $rectCount = 0;
            $skippedRects = 0;

            // Process regular highlights (selection-based)
            foreach ((array) $request->input('highlights', []) as $h) {
                $page = (int) ($h['page'] ?? 0);
                
                if ($page <= 0) {
                    continue;
                }

                foreach (($h['rects'] ?? []) as $r) {
                    if ($rectCount >= self::MAX_RECTS) {
                        $skippedRects++;
                        continue;
                    }

                    $x = $this->clamp01($r['x'] ?? null);
                    $y = $this->clamp01($r['y'] ?? null);
                    $w = $this->clamp01($r['w'] ?? null);
                    $hgt = $this->clamp01($r['h'] ?? null);

                    // Validate all coordinates are present
                    if ($x === null || $y === null || $w === null || $hgt === null) {
                        $skippedRects++;
                        continue;
                    }

                    // Skip zero or negative dimensions
                    if ($w <= self::MIN_RECT_SIZE || $hgt <= self::MIN_RECT_SIZE) {
                        $skippedRects++;
                        continue;
                    }

                    // Normalize style with defaults
                    $style = $this->normalizeStyle(
                        is_array($r['style'] ?? null) ? $r['style'] : null
                    );

                    $cleanByPage[$page][] = [
                        'x'     => $x,
                        'y'     => $y,
                        'w'     => $w,
                        'h'     => $hgt,
                        'style' => $style,
                    ];

                    $rectCount++;
                }
            }

            // Process brush highlights (freehand drawing)
            $brushStrokesProcessed = 0;
            
            foreach ((array) $request->input('brushHighlights', []) as $b) {
                $page = (int) ($b['page'] ?? 0);
                
                if ($page <= 0) {
                    continue;
                }

                foreach (($b['strokes'] ?? []) as $stroke) {
                    if ($rectCount >= self::MAX_RECTS) {
                        $skippedRects++;
                        continue;
                    }

                    // Convert brush stroke to bounding rectangle
                    $rect = $this->brushStrokeToRect($stroke);
                    
                    if (!$rect) {
                        $skippedRects++;
                        continue;
                    }

                    // Normalize style with defaults
                    $style = $this->normalizeStyle(
                        is_array($stroke['style'] ?? null) ? $stroke['style'] : null
                    );

                    $cleanByPage[$page][] = [
                        'x'     => $rect['x'],
                        'y'     => $rect['y'],
                        'w'     => $rect['w'],
                        'h'     => $rect['h'],
                        'style' => $style,
                    ];

                    $rectCount++;
                    $brushStrokesProcessed++;
                }
            }

            // Validate we have at least some highlights
            if (empty($cleanByPage)) {
                throw new \InvalidArgumentException('No valid highlights found');
            }

            // Log skipped rectangles if significant
            if ($skippedRects > 0) {
                Log::warning('PDF Highlight: Skipped rectangles', [
                    'paper_id' => $paper->id,
                    'skipped_count' => $skippedRects,
                    'reason' => 'Invalid data or MAX_RECTS limit reached',
                ]);
            }

            /* ---------------------------------------------------------
             * 3. MERGE OVERLAPPING HIGHLIGHTS BY STYLE (KEY FIX)
             * --------------------------------------------------------- */
            $totalRectsBeforeMerge = array_sum(array_map('count', $cleanByPage));

            foreach ($cleanByPage as $pageNo => $rects) {
                // Merge only same-style overlapping rectangles
                $cleanByPage[$pageNo] = $this->mergeOverlappingRectsByStyle($rects);
            }

            $totalRectsAfterMerge = array_sum(array_map('count', $cleanByPage));
            $mergeReduction = $totalRectsBeforeMerge > 0
                ? round((1 - $totalRectsAfterMerge / $totalRectsBeforeMerge) * 100, 1)
                : 0;

            /* ---------------------------------------------------------
             * 4. PREPARE OUTPUT PATH
             * --------------------------------------------------------- */
            $dir = trim(dirname($srcPath), '/');
            $base = pathinfo($srcPath, PATHINFO_FILENAME);
            $timestamp = now()->format('Ymd_His');

            $outRel = ($replace ? $dir : ($dir . '/highlighted'))
                . "/{$base}_hl_{$timestamp}.pdf";

            if (!$replace) {
                Storage::disk($srcDisk)->makeDirectory(dirname($outRel));
            }

            $absOut = Storage::disk($srcDisk)->path($outRel);

            /* ---------------------------------------------------------
             * 5. RENDER PDF WITH HIGHLIGHTS
             * --------------------------------------------------------- */
            $pdf = new Fpdi();
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            $pageCount = $pdf->setSourceFile($absIn);

            foreach (range(1, $pageCount) as $pageNo) {
                $tplIdx = $pdf->importPage($pageNo);
                $size = $pdf->getTemplateSize($tplIdx);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tplIdx);

                // Apply highlights for this page
                $pageRects = $cleanByPage[$pageNo] ?? [];

                foreach ($pageRects as $rect) {
                    $style = $rect['style'] ?? [];
                    $color = $style['color'] ?? self::DEFAULT_COLOR;
                    $alpha = $this->clamp($style['alpha'] ?? self::DEFAULT_ALPHA, 0.0, 1.0);

                    [$r, $g, $b] = $this->hexToRgb($color);

                    $pdf->SetFillColor($r, $g, $b);
                    $pdf->SetAlpha($alpha);

                    // Convert normalized coordinates (0-1) to PDF points
                    $pdf->Rect(
                        $rect['x'] * $size['width'],
                        $rect['y'] * $size['height'],
                        $rect['w'] * $size['width'],
                        $rect['h'] * $size['height'],
                        'F' // Fill only
                    );
                }

                // Reset alpha for next page
                $pdf->SetAlpha(1.0);
            }

            // Set PDF metadata
            $pdf->SetTitle('Highlighted Document');
            $pdf->SetCreator('PDF Highlight System');
            $pdf->Output($absOut, 'F');

            /* ---------------------------------------------------------
             * 6. HANDLE REPLACEMENT IF REQUESTED
             * --------------------------------------------------------- */
            if ($replace) {
                Storage::disk($srcDisk)->delete($srcPath);
                Storage::disk($srcDisk)->move($outRel, $srcPath);
                $outRel = $srcPath;
            }

            $url = Storage::disk($srcDisk)->url($outRel);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            /* ---------------------------------------------------------
             * 7. LOG SUCCESS METRICS
             * --------------------------------------------------------- */
            Log::info('PDF Highlight: Success', [
                'paper_id' => $paper->id,
                'pages' => $pageCount,
                'original_rects' => $totalRectsBeforeMerge,
                'merged_rects' => $totalRectsAfterMerge,
                'reduction_percent' => $mergeReduction,
                'brush_strokes' => $brushStrokesProcessed,
                'skipped_rects' => $skippedRects,
                'duration_ms' => $duration,
                'replaced' => $replace,
            ]);

            /* ---------------------------------------------------------
             * 8. AUDIT LOG — SUCCESS
             * --------------------------------------------------------- */
            AuditLogger::log(
                request: $request,
                action: 'pdf.highlight.apply',
                entityType: Paper::class,
                entityId: $paper->id,
                payload: array_merge($auditPayload, [
                    'rects_processed' => $totalRectsAfterMerge,
                    'duration_ms' => $duration,
                ]),
                success: true
            );

            /* ---------------------------------------------------------
             * 9. RETURN SUCCESS RESPONSE
             * --------------------------------------------------------- */
            return response()->json([
                'success'  => true,
                'message'  => 'Highlights applied successfully',
                'file_url' => $url,
                'raw_url'  => $url,
                'replaced' => $replace,
                'stats'    => [
                    'pages' => $pageCount,
                    'highlights_applied' => $totalRectsAfterMerge,
                    'overlaps_merged' => $totalRectsBeforeMerge - $totalRectsAfterMerge,
                    'processing_time_ms' => $duration,
                ],
            ]);

        } catch (\Throwable $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            /* ---------------------------------------------------------
             * LOG FAILURE
             * --------------------------------------------------------- */
            Log::error('PDF Highlight: Failed', [
                'paper_id' => $paper->id,
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
                'trace' => $e->getTraceAsString(),
            ]);

            /* ---------------------------------------------------------
             * AUDIT LOG — FAILURE
             * --------------------------------------------------------- */
            AuditLogger::log(
                request: $request,
                action: 'pdf.highlight.apply',
                entityType: Paper::class,
                entityId: $paper->id,
                payload: $auditPayload,
                success: false,
                errorMessage: $e->getMessage()
            );

            // Re-throw to preserve existing error handling
            throw $e;
        }
    }
}
