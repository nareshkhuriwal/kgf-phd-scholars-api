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

class PdfHighlightController extends Controller
{
    use OwnerAuthorizes;
    use ResolvesPublicUploads;

    // Configuration constants
    private const MAX_RECTS = 2000;
    private const DEFAULT_ALPHA = 0.25;
    private const DEFAULT_COLOR = '#FFEB3B';
    private const OVERLAP_TOLERANCE = 0.005; // 0.5% of page
    private const MIN_BRUSH_DISTANCE = 0.005; // 0.5% of page
    private const MIN_RECT_SIZE = 0.0001;

    /* ---------------------------------------------------------
     * HELPER METHODS
     * --------------------------------------------------------- */

    /**
     * Clamp value between 0 and 1
     */
    private function clamp01($v): ?float
    {
        if (!is_numeric($v)) {
            return null;
        }
        return max(0.0, min(1.0, (float) $v));
    }

    /**
     * Clamp value between min and max
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
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        
        if (strlen($hex) !== 6) {
            return [255, 235, 59]; // fallback yellow
        }
        
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Convert brush stroke points to bounding rectangle
     * Filters out closely-spaced points to reduce overlap issues
     */
    private function brushStrokeToRect(array $stroke): ?array
    {
        $points = $stroke['points'] ?? [];
        if (empty($points)) {
            return null;
        }

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
                // Calculate Euclidean distance
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

        if (count($filtered) < 2) {
            return null;
        }

        // Calculate bounding box
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

        return [
            'x' => $minX,
            'y' => $minY,
            'w' => max(self::MIN_RECT_SIZE, $maxX - $minX),
            'h' => max(self::MIN_RECT_SIZE, $maxY - $minY),
        ];
    }

    /* ---------------------------------------------------------
     * OVERLAP DETECTION & MERGING
     * --------------------------------------------------------- */

    /**
     * Check if two rectangles overlap (with tolerance)
     */
    private function rectsOverlap(array $r1, array $r2): bool
    {
        $tolerance = self::OVERLAP_TOLERANCE;

        return !(
            $r1['x'] + $r1['w'] + $tolerance < $r2['x'] || 
            $r2['x'] + $r2['w'] + $tolerance < $r1['x'] ||
            $r1['y'] + $r1['h'] + $tolerance < $r2['y'] || 
            $r2['y'] + $r2['h'] + $tolerance < $r1['y']
        );
    }

    /**
     * Merge two overlapping rectangles into one
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
            'style' => $r1['style'] ?? $r2['style'],
        ];
    }

    /**
     * Merge all overlapping rectangles in an array
     * Uses iterative approach until no more overlaps exist
     */
    private function mergeOverlappingRects(array $rects): array
    {
        if (empty($rects)) {
            return [];
        }

        $merged = $rects;
        $didMerge = true;
        $iterations = 0;
        $maxIterations = 10; // Safety limit

        // Keep merging until no more overlaps found
        while ($didMerge && $iterations < $maxIterations) {
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

        return $merged;
    }

    /* ---------------------------------------------------------
     * MAIN APPLY HIGHLIGHTS METHOD
     * --------------------------------------------------------- */

    /**
     * Apply highlights to a PDF
     * 
     * @param ApplyHighlightsRequest $request
     * @param Paper $paper
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
        ];

        try {
            // -----------------------------------------------------
            // 1. RESOLVE SOURCE PDF
            // -----------------------------------------------------
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

            // -----------------------------------------------------
            // 2. NORMALIZE & COLLECT HIGHLIGHTS
            // -----------------------------------------------------
            $cleanByPage = [];
            $rectCount = 0;
            $skippedRects = 0;

            // Process regular highlights
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

                    if ($x === null || $y === null || $w === null || $hgt === null) {
                        $skippedRects++;
                        continue;
                    }

                    if ($w <= 0 || $hgt <= 0) {
                        $skippedRects++;
                        continue;
                    }

                    $cleanByPage[$page][] = [
                        'x'     => $x,
                        'y'     => $y,
                        'w'     => $w,
                        'h'     => $hgt,
                        'style' => is_array($r['style'] ?? null) ? $r['style'] : null,
                    ];

                    $rectCount++;
                }
            }

            // Process brush highlights
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

                    $rect = $this->brushStrokeToRect($stroke);
                    if (!$rect) {
                        $skippedRects++;
                        continue;
                    }

                    $cleanByPage[$page][] = [
                        'x'     => $rect['x'],
                        'y'     => $rect['y'],
                        'w'     => $rect['w'],
                        'h'     => $rect['h'],
                        'style' => is_array($stroke['style'] ?? null) ? $stroke['style'] : null,
                    ];

                    $rectCount++;
                    $brushStrokesProcessed++;
                }
            }

            if (empty($cleanByPage)) {
                throw new \InvalidArgumentException('No valid highlights found');
            }

            // Log skipped rectangles if any
            if ($skippedRects > 0) {
                Log::warning('PDF Highlight: Skipped rectangles', [
                    'paper_id' => $paper->id,
                    'skipped_count' => $skippedRects,
                    'reason' => 'Invalid data or MAX_RECTS limit reached',
                ]);
            }

            // -----------------------------------------------------
            // 3. MERGE OVERLAPPING HIGHLIGHTS (KEY IMPROVEMENT)
            // -----------------------------------------------------
            $totalRectsBeforeMerge = array_sum(array_map('count', $cleanByPage));
            
            foreach ($cleanByPage as $pageNo => $rects) {
                $cleanByPage[$pageNo] = $this->mergeOverlappingRects($rects);
            }

            $totalRectsAfterMerge = array_sum(array_map('count', $cleanByPage));
            $mergeReduction = $totalRectsBeforeMerge > 0 
                ? round((1 - $totalRectsAfterMerge / $totalRectsBeforeMerge) * 100, 1) 
                : 0;

            // -----------------------------------------------------
            // 4. PREPARE OUTPUT PATH
            // -----------------------------------------------------
            $dir = trim(dirname($srcPath), '/');
            $base = pathinfo($srcPath, PATHINFO_FILENAME);
            $timestamp = now()->format('Ymd_His');
            
            $outRel = ($replace ? $dir : ($dir . '/highlighted'))
                . "/{$base}_hl_{$timestamp}.pdf";

            if (!$replace) {
                Storage::disk($srcDisk)->makeDirectory(dirname($outRel));
            }

            $absOut = Storage::disk($srcDisk)->path($outRel);

            // -----------------------------------------------------
            // 5. RENDER PDF WITH HIGHLIGHTS
            // -----------------------------------------------------
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

                    $pdf->Rect(
                        $rect['x'] * $size['width'],
                        $rect['y'] * $size['height'],
                        $rect['w'] * $size['width'],
                        $rect['h'] * $size['height'],
                        'F' // Fill
                    );
                }

                // Reset alpha for next page
                $pdf->SetAlpha(1.0);
            }

            $pdf->SetTitle('Highlighted Document');
            $pdf->SetCreator('PDF Highlight System');
            $pdf->Output($absOut, 'F');

            // -----------------------------------------------------
            // 6. HANDLE REPLACEMENT IF NEEDED
            // -----------------------------------------------------
            if ($replace) {
                Storage::disk($srcDisk)->delete($srcPath);
                Storage::disk($srcDisk)->move($outRel, $srcPath);
                $outRel = $srcPath;
            }

            $url = Storage::disk($srcDisk)->url($outRel);
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // -----------------------------------------------------
            // 7. LOG SUCCESS METRICS
            // -----------------------------------------------------
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

            // -----------------------------------------------------
            // 8. AUDIT LOG — SUCCESS
            // -----------------------------------------------------
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

            // -----------------------------------------------------
            // LOG FAILURE
            // -----------------------------------------------------
            Log::error('PDF Highlight: Failed', [
                'paper_id' => $paper->id,
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
                'trace' => $e->getTraceAsString(),
            ]);

            // -----------------------------------------------------
            // AUDIT LOG — FAILURE
            // -----------------------------------------------------
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
