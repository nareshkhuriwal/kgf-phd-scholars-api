<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyHighlightsRequest;
use App\Models\Paper;
use App\Models\PaperFile;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Tcpdf\Fpdi;
use App\Http\Controllers\Concerns\ResolvesPublicUploads;
use App\Support\ResolvesApiScope;
use App\Services\AuditLogger;

class PdfHighlightController extends Controller
{
    use ResolvesPublicUploads;
    use ResolvesApiScope;

    /* ---------------------------------------------------------
     * HELPERS (existing)
     * --------------------------------------------------------- */

    private function clamp01($v): ?float
    {
        if (!is_numeric($v)) return null;
        return max(0.0, min(1.0, (float) $v));
    }

    private function clamp($v, $min, $max)
    {
        if (!is_numeric($v)) return $min;
        return max($min, min($max, (float) $v));
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) !== 6) return [255, 235, 59]; // fallback yellow
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private function brushStrokeToRect(array $stroke): ?array
    {
        $minX = INF;
        $minY = INF;
        $maxX = -INF;
        $maxY = -INF;

        foreach (($stroke['points'] ?? []) as $p) {
            if (!isset($p['x'], $p['y'])) continue;
            $x = $this->clamp01($p['x']);
            $y = $this->clamp01($p['y']);
            if ($x === null || $y === null) continue;

            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }

        if (!is_finite($minX) || !is_finite($minY)) return null;

        return [
            'x' => $minX,
            'y' => $minY,
            'w' => max(0.0001, $maxX - $minX),
            'h' => max(0.0001, $maxY - $minY),
        ];
    }

    /* ---------------------------------------------------------
     * NEW HELPERS (dedupe + style normalization)
     * --------------------------------------------------------- */

    /**
     * Normalize style fields to stable values so dedupe works reliably.
     * Keeps existing behavior defaults: yellow + alpha 0.25.
     */
    private function normalizeStyle($style): array
    {
        $style = is_array($style) ? $style : [];

        $color = (string)($style['color'] ?? '#FFEB3B');
        $color = strtoupper($color);
        if ($color !== '' && $color[0] !== '#') {
            $color = '#' . $color;
        }

        // Validate #RRGGBB; fallback to default yellow if invalid
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            $color = '#FFEB3B';
        }

        $alpha = $this->clamp($style['alpha'] ?? 0.25, 0.0, 1.0);

        return ['color' => $color, 'alpha' => $alpha];
    }

    /**
     * Quantize normalized floats (0..1) so "almost equal" rects dedupe.
     * Default q=10000 => 1e-4 precision in normalized coordinates.
     */
    private function q(?float $v, int $q = 10000): ?int
    {
        if ($v === null) return null;
        return (int) round($v * $q);
    }

    /**
     * Create stable dedupe key for a highlight rectangle.
     * Includes style so different colors/alpha do not collide.
     */
    private function rectKey(int $page, array $rect, array $style): string
    {
        return implode(':', [
            $page,
            $this->q($rect['x']),
            $this->q($rect['y']),
            $this->q($rect['w']),
            $this->q($rect['h']),
            $style['color'],
            $this->q((float)$style['alpha'], 1000), // alpha quantization
        ]);
    }

    /**
     * Ensure only one highlight is applied at the same position.
     * If duplicates exist, keep a single rect; to avoid dark rendering,
     * keep the LOWEST alpha among duplicates.
     */
    private function dedupeRectsByPage(array $cleanByPage): array
    {
        $out = [];

        foreach ($cleanByPage as $page => $rects) {
            $seen = [];

            foreach ($rects as $r) {
                // Ensure fields exist
                if (!isset($r['x'], $r['y'], $r['w'], $r['h'])) continue;

                $style = $this->normalizeStyle($r['style'] ?? null);

                $rect = [
                    'x' => (float)$r['x'],
                    'y' => (float)$r['y'],
                    'w' => (float)$r['w'],
                    'h' => (float)$r['h'],
                ];

                $key = $this->rectKey((int)$page, $rect, $style);

                if (!isset($seen[$key])) {
                    $seen[$key] = [
                        'x' => $rect['x'],
                        'y' => $rect['y'],
                        'w' => $rect['w'],
                        'h' => $rect['h'],
                        'style' => $style,
                    ];
                } else {
                    // If same key repeats, keep lightest alpha to prevent darkening
                    $existingAlpha = (float)($seen[$key]['style']['alpha'] ?? 1.0);
                    if ($style['alpha'] < $existingAlpha) {
                        $seen[$key]['style']['alpha'] = $style['alpha'];
                    }
                }
            }

            $out[(int)$page] = array_values($seen);
        }

        return $out;
    }


    /**
     * Merge overlapping/nearby rects per page/style so alpha doesn't stack.
     * This is stronger than "dedupe": it collapses intersecting boxes into one.
     */
    private function mergeOverlappingRectsByPage(array $cleanByPage, float $eps = 0.0015): array
    {
        $out = [];

        foreach ($cleanByPage as $page => $rects) {
            // group by normalized style (color+alpha)
            $groups = [];
            foreach ($rects as $r) {
                if (!isset($r['x'], $r['y'], $r['w'], $r['h'])) continue;
                $style = $this->normalizeStyle($r['style'] ?? null);
                $key = $style['color'] . '|' . ((string)round($style['alpha'], 3));
                $groups[$key][] = [
                    'x' => (float)$r['x'],
                    'y' => (float)$r['y'],
                    'w' => (float)$r['w'],
                    'h' => (float)$r['h'],
                    'style' => $style,
                ];
            }

            $mergedAll = [];

            foreach ($groups as $g) {
                // sort for more stable merging
                usort($g, function ($a, $b) {
                    if ($a['y'] == $b['y']) return $a['x'] <=> $b['x'];
                    return $a['y'] <=> $b['y'];
                });

                $merged = [];
                foreach ($g as $r) {
                    $didMerge = false;

                    for ($i = 0; $i < count($merged); $i++) {
                        if ($this->rectsOverlapOrTouch($merged[$i], $r, $eps)) {
                            $merged[$i] = $this->rectUnion($merged[$i], $r);
                            $didMerge = true;
                            break;
                        }
                    }

                    if (!$didMerge) {
                        $merged[] = $r;
                    }
                }

                // second pass to catch chain overlaps (A overlaps B overlaps C)
                $changed = true;
                while ($changed) {
                    $changed = false;
                    $tmp = [];
                    foreach ($merged as $r) {
                        $mergedInto = false;
                        for ($i = 0; $i < count($tmp); $i++) {
                            if ($this->rectsOverlapOrTouch($tmp[$i], $r, $eps)) {
                                $tmp[$i] = $this->rectUnion($tmp[$i], $r);
                                $mergedInto = true;
                                $changed = true;
                                break;
                            }
                        }
                        if (!$mergedInto) $tmp[] = $r;
                    }
                    $merged = $tmp;
                }

                $mergedAll = array_merge($mergedAll, $merged);
            }

            $out[(int)$page] = $mergedAll;
        }

        return $out;
    }

    private function rectsOverlapOrTouch(array $a, array $b, float $eps): bool
    {
        $ax1 = $a['x'];
        $ay1 = $a['y'];
        $ax2 = $a['x'] + $a['w'];
        $ay2 = $a['y'] + $a['h'];

        $bx1 = $b['x'];
        $by1 = $b['y'];
        $bx2 = $b['x'] + $b['w'];
        $by2 = $b['y'] + $b['h'];

        // overlap/touch with epsilon margin
        return !(
            $ax2 < $bx1 - $eps ||
            $bx2 < $ax1 - $eps ||
            $ay2 < $by1 - $eps ||
            $by2 < $ay1 - $eps
        );
    }

    private function rectUnion(array $a, array $b): array
    {
        $x1 = min($a['x'], $b['x']);
        $y1 = min($a['y'], $b['y']);
        $x2 = max($a['x'] + $a['w'], $b['x'] + $b['w']);
        $y2 = max($a['y'] + $a['h'], $b['y'] + $b['h']);

        return [
            'x' => $x1,
            'y' => $y1,
            'w' => max(0.0001, $x2 - $x1),
            'h' => max(0.0001, $y2 - $y1),
            'style' => $a['style'], // same style group
        ];
    }

    /**
     * FPDI requires a local filesystem path. Azure / S3 blobs are copied to a temp .pdf file.
     */
    private function materializePdfForProcessing(string $disk, string $path): string
    {
        $bytes = Storage::disk($disk)->get($path);
        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException('Could not read PDF from storage');
        }

        $base = tempnam(sys_get_temp_dir(), 'pdfhl_');
        if ($base === false) {
            throw new \RuntimeException('Could not allocate temp file');
        }

        @unlink($base);
        $tmpPdf = $base . '.pdf';
        if (file_put_contents($tmpPdf, $bytes) === false) {
            throw new \RuntimeException('Could not write temp PDF');
        }

        return $tmpPdf;
    }


    /* ---------------------------------------------------------
     * APPLY HIGHLIGHTS (main)
     * --------------------------------------------------------- */

    public function apply(ApplyHighlightsRequest $request, Paper $paper)
    {
        $request->user() ?? abort(401, 'Unauthenticated');
        $this->authorizeUserAccess($request, (int) $paper->created_by);

        // Prepare audit payload early (raw user intent)
        $auditPayload = [
            'source_url'       => $request->input('sourceUrl') ?? $request->input('source_url'),
            'replace_requested' => $request->boolean('replace', false),
            'replace'          => true,
            'highlights'       => $request->input('highlights', []),
            'brush_highlights' => $request->input('brushHighlights', []),
        ];

        try {
            // -----------------------------------------------------
            // Resolve source PDF
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

            // Hard-lock behavior: highlights always overwrite review working copy.
            // Non-replace mode (creating highlighted/... files) is disabled.
            $replace = true;

            // Never burn highlights into the library (canonical) file — only into per-review copies.
            $targetRow = PaperFile::query()
                ->where('paper_id', $paper->id)
                ->where('path', $srcPath)
                ->first();

            if ($replace) {
                if (!$targetRow || !($targetRow->is_review_copy ?? false)) {
                    throw new HttpResponseException(
                        response()->json([
                            'message' => 'In-place highlight save is only allowed on your review working copy, not the library PDF. Open this paper from Reviews and wait for the review copy to load, then save again.',
                        ], 422)
                    );
                }
            }

            // -----------------------------------------------------
            // NORMALIZE HIGHLIGHTS (same behavior as before)
            // -----------------------------------------------------
            $cleanByPage = [];
            $MAX_RECTS = 2000;
            $rectCount = 0;

            foreach ((array) $request->input('highlights', []) as $h) {
                $page = (int) ($h['page'] ?? 0);
                if ($page <= 0) continue;

                foreach (($h['rects'] ?? []) as $r) {
                    $x = $this->clamp01($r['x'] ?? null);
                    $y = $this->clamp01($r['y'] ?? null);
                    $w = $this->clamp01($r['w'] ?? null);
                    $hgt = $this->clamp01($r['h'] ?? null);

                    if ($x === null || $y === null || $w === null || $hgt === null) continue;
                    if ($w <= 0 || $hgt <= 0) continue;

                    $cleanByPage[$page][] = [
                        'x'     => $x,
                        'y'     => $y,
                        'w'     => $w,
                        'h'     => $hgt,
                        'style' => is_array($r['style'] ?? null) ? $r['style'] : null,
                    ];

                    if (++$rectCount > $MAX_RECTS) break 2;
                }
            }

            foreach ((array) $request->input('brushHighlights', []) as $b) {
                $page = (int) ($b['page'] ?? 0);
                if ($page <= 0) continue;

                foreach (($b['strokes'] ?? []) as $stroke) {
                    $rect = $this->brushStrokeToRect($stroke);
                    if (!$rect) continue;

                    $cleanByPage[$page][] = [
                        'x'     => $rect['x'],
                        'y'     => $rect['y'],
                        'w'     => $rect['w'],
                        'h'     => $rect['h'],
                        'style' => is_array($stroke['style'] ?? null) ? $stroke['style'] : null,
                    ];
                }
            }

            if (!$cleanByPage) {
                throw new \InvalidArgumentException('No valid highlights');
            }

            // ✅ NEW: DEDUPE so the same position is highlighted only once
            $cleanByPage = $this->dedupeRectsByPage($cleanByPage);          // exact dupes
            $cleanByPage = $this->mergeOverlappingRectsByPage($cleanByPage); // overlap merge ✅

            // -----------------------------------------------------
            // OUTPUT PATH
            // -----------------------------------------------------
            $outRel = $srcPath;

            $absIn = $this->materializePdfForProcessing($srcDisk, $srcPath);
            $absOut = null;

            try {
                $baseOut = tempnam(sys_get_temp_dir(), 'pdfhlout_');
                if ($baseOut === false) {
                    throw new \RuntimeException('Could not allocate output temp file');
                }
                @unlink($baseOut);
                $absOut = $baseOut . '.pdf';

                // -----------------------------------------------------
                // RENDER PDF (minimal changes: use normalized style)
                // -----------------------------------------------------
                $pdf = new Fpdi();
                $pageCount = $pdf->setSourceFile($absIn);

                foreach (range(1, $pageCount) as $pageNo) {
                    $tplIdx = $pdf->importPage($pageNo);
                    $size   = $pdf->getTemplateSize($tplIdx);

                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($tplIdx);

                    foreach ($cleanByPage[$pageNo] ?? [] as $rect) {
                        $style = $this->normalizeStyle($rect['style'] ?? null);
                        $color = $style['color'];
                        $alpha = $style['alpha'];

                        [$r, $g, $b] = $this->hexToRgb($color);

                        $pdf->SetFillColor($r, $g, $b);
                        $pdf->SetAlpha($alpha);

                        $pdf->Rect(
                            $rect['x'] * $size['width'],
                            $rect['y'] * $size['height'],
                            $rect['w'] * $size['width'],
                            $rect['h'] * $size['height'],
                            'F'
                        );
                    }

                    $pdf->SetAlpha(1);
                }

                $pdf->SetTitle('Highlighted');
                $pdf->Output($absOut, 'F');

                $binaryOut = file_get_contents($absOut);
                if ($binaryOut === false || $binaryOut === '') {
                    throw new \RuntimeException('Highlighted PDF output was empty');
                }

                Storage::disk($srcDisk)->put($srcPath, $binaryOut);

                $fileRow = PaperFile::query()
                    ->where('paper_id', $paper->id)
                    ->where('path', $srcPath)
                    ->first();

                if ($fileRow) {
                    $url = route('papers.files.download', ['paper' => $paper->id, 'file' => $fileRow->id], true);
                } else {
                    try {
                        $url = Storage::disk($srcDisk)->url($srcPath);
                    } catch (\Throwable $e) {
                        $url = '';
                    }
                }
            } finally {
                @unlink($absIn);
                if ($absOut && is_file($absOut)) {
                    @unlink($absOut);
                }
            }

            // -----------------------------------------------------
            // AUDIT LOG — SUCCESS
            // -----------------------------------------------------
            AuditLogger::log(
                request: $request,
                action: 'pdf.highlight.apply',
                entityType: Paper::class,
                entityId: $paper->id,
                payload: $auditPayload,
                success: true
            );

            return response()->json([
                'message'  => 'Highlights applied',
                'file_url' => $url,
                'raw_url'  => $url,
                'replaced' => $replace,
            ]);
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {

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

            throw $e; // preserve existing error handling
        }
    }
}
