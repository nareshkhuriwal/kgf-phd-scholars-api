<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApplyHighlightsRequest;
use App\Models\Paper;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use setasign\Fpdi\Tcpdf\Fpdi;
use App\Http\Controllers\Concerns\OwnerAuthorizes;
use Illuminate\Http\Request;
use App\Http\Controllers\Concerns\ResolvesPublicUploads;
use App\Services\AuditLogger;

class PdfHighlightController extends Controller
{
    use OwnerAuthorizes;
    use ResolvesPublicUploads;

    /* ---------------------------------------------------------
     * HELPERS
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
     * APPLY HIGHLIGHTS (MAIN FIXED METHOD)
     * --------------------------------------------------------- */

    public function apply(ApplyHighlightsRequest $request, Paper $paper)
    {
        $this->authorizeOwner($paper, 'created_by');

        // Prepare audit payload early (raw user intent)
        $auditPayload = [
            'source_url'       => $request->input('sourceUrl') ?? $request->input('source_url'),
            'replace'          => $request->boolean('replace', false),
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

            $absIn   = Storage::disk($srcDisk)->path($srcPath);
            $replace = $request->boolean('replace', false);

            // -----------------------------------------------------
            // NORMALIZE HIGHLIGHTS
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

            // -----------------------------------------------------
            // OUTPUT PATH
            // -----------------------------------------------------
            $dir   = trim(dirname($srcPath), '/');
            $base  = pathinfo($srcPath, PATHINFO_FILENAME);
            $outRel = ($replace ? $dir : ($dir . '/highlighted'))
                . "/{$base}_hl_" . now()->format('Ymd_His') . ".pdf";

            if (!$replace) {
                Storage::disk($srcDisk)->makeDirectory(dirname($outRel));
            }

            $absOut = Storage::disk($srcDisk)->path($outRel);

            // -----------------------------------------------------
            // RENDER PDF
            // -----------------------------------------------------
            $pdf = new Fpdi();
            $pageCount = $pdf->setSourceFile($absIn);

            foreach (range(1, $pageCount) as $pageNo) {
                $tplIdx = $pdf->importPage($pageNo);
                $size   = $pdf->getTemplateSize($tplIdx);

                $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $pdf->useTemplate($tplIdx);

                foreach ($cleanByPage[$pageNo] ?? [] as $rect) {
                    $style = $rect['style'] ?? [];
                    $color = $style['color'] ?? '#FFEB3B';
                    $alpha = $this->clamp($style['alpha'] ?? 0.25, 0.0, 1.0);

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

            if ($replace) {
                Storage::disk($srcDisk)->delete($srcPath);
                Storage::disk($srcDisk)->move($outRel, $srcPath);
                $outRel = $srcPath;
            }

            $url = Storage::disk($srcDisk)->url($outRel);

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