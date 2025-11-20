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


class PdfHighlightController extends Controller
{
    use OwnerAuthorizes;
    use ResolvesPublicUploads;

public function store(Request $request)
{
    $request->validate([
        'file'       => 'required|file|mimetypes:application/pdf|max:51200', // 50MB
        'dest_url'   => 'nullable|string',
        'dest_path'  => 'nullable|string',
        'overwrite'  => 'nullable|boolean',
        'keep_name'  => 'nullable|boolean',
        'label'      => 'nullable|string|max:100',
    ]);

    $file        = $request->file('file');
    $defaultDisk = 'uploads'; // <<< force uploads
    $overwrite   = $request->boolean('overwrite', ($request->filled('dest_url') || $request->filled('dest_path')));

    // --- Resolve destination (URL or path) ---
    [$disk, $rel] = [null, null];

    if ($request->filled('dest_url')) {
        [$disk, $rel] = $this->resolveFromUrl($request->string('dest_url'));
    } elseif ($request->filled('dest_path')) {
        [$disk, $rel] = $this->resolveFromPath($request->string('dest_path'));
    }

    // Coerce to uploads if resolver didn't return a disk but we have a path
    if ($rel && !$disk) $disk = $defaultDisk;

    // --- Overwrite exact destination if provided ---
    if ($disk && $rel) {
        if (!$this->isSafeRelative($rel)) {
            return response()->json(['message' => 'Invalid destination path'], 422);
        }

        $dir  = trim(dirname($rel), '/');
        $name = basename($rel);

        Storage::disk($disk)->makeDirectory($dir);

        // atomic temp write then move
        $tmpName = $name.'.tmp-'.Str::random(6).'.pdf';
        $tmpRel  = ($dir ? "$dir/" : '').$tmpName;

        Storage::disk($disk)->putFileAs($dir, $file, $tmpName);

        if ($overwrite && Storage::disk($disk)->exists($rel)) {
            Storage::disk($disk)->delete($rel);
        }
        Storage::disk($disk)->move($tmpRel, $rel);

        return response()->json([
            'message'     => 'Uploaded (overwritten)',
            'disk'        => $disk,                 // 'uploads'
            'path'        => $rel,                  // e.g. library/2025/11/abc.pdf
            'url'         => Storage::disk($disk)->url($rel), // /uploads/library/...
            'overwritten' => true,
        ], 201);
    }

    // --- No explicit destination: save under uploads by default ---
    $activeDisk = $defaultDisk; // always 'uploads'
    $baseRel    = 'library/'.date('Y').'/'.date('m');
    Storage::disk($activeDisk)->makeDirectory($baseRel);

    if ($request->boolean('keep_name') && $file->getClientOriginalName()) {
        $safe = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $safe = Str::slug($safe, '_') ?: 'file';
        $rel  = "{$baseRel}/{$safe}.pdf";
        if (Storage::disk($activeDisk)->exists($rel)) {
            $rel = "{$baseRel}/{$safe}_".Str::random(4).".pdf";
        }
        Storage::disk($activeDisk)->putFileAs($baseRel, $file, basename($rel));
        $storedRel = $rel;
    } else {
        $storedRel = $file->store($baseRel, $activeDisk);
    }

    return response()->json([
        'message'     => 'Uploaded',
        'disk'        => $activeDisk,                               // 'uploads'
        'path'        => $storedRel,                                // library/2025/11/xxx.pdf
        'url'         => Storage::disk($activeDisk)->url($storedRel), // https://.../uploads/...
        'overwritten' => false,
    ], 201);
}


    /**
     * Save a highlighted PDF and associate with a Paper (optional PaperFile model).
     * Expects multipart/form-data with: file: <pdf blob>
     */
    public function storeForPaper(Request $request, Paper $paper)
    {
        // Optional: ensure only owner can add files
        if (method_exists($this, 'authorizeOwner')) {
            // If you use the OwnerAuthorizes trait elsewhere
            $this->authorizeOwner($paper, 'created_by');
        }

        $request->validate([
            'file' => 'required|file|mimetypes:application/pdf|max:51200',
            'label' => 'nullable|string|max:100',
            'replace' => 'nullable|boolean', // if you want to replace the "current" pdf
        ]);

        $y = date('Y'); $m = date('m');
        $rel = "uploads/papers/{$paper->id}/highlighted/{$y}/{$m}";
        $path = $request->file('file')->store($rel, 'public');

        // If you have PaperFile model and want to record it:
        if (class_exists(\App\Models\PaperFile::class)) {
            \App\Models\PaperFile::create([
                'paper_id' => $paper->id,
                'disk'     => 'public',
                'path'     => $path,
                'mime'     => 'application/pdf',
                'size'     => Storage::disk('public')->size($path) ?: null,
                'label'    => $request->input('label', 'Highlighted'),
            ]);
        }

        // Optionally: if `replace=true`, you can set this file as the paper's primary path
        if ($request->boolean('replace')) {
            // If your Paper has columns pdf_path / pdf_url, update as needed.
            $paper->pdf_path = $path;
            $paper->save();
        }

        return response()->json([
            'message' => 'Uploaded',
            'path'    => $path,
            'url'     => asset('storage/'.$path),
        ], 201);
    }


    public function apply(ApplyHighlightsRequest $request, Paper $paper)
    {
        $this->authorizeOwner($paper, 'created_by');

        // --- Prefer current viewed file (cumulative) ---
        $sourceUrl = (string) ($request->input('sourceUrl') ?? $request->input('source_url') ?? '');
        if ($sourceUrl) {
            [$srcDisk, $srcPath] = $this->resolveFromUrl($sourceUrl);
        } else {
            [$srcDisk, $srcPath] = $this->resolvePdfDiskAndPath($paper);
        }

        if (!$srcDisk || !$srcPath || !Storage::disk($srcDisk)->exists($srcPath)) {
            return response()->json(['message' => 'PDF not found: ' . ($srcPath ?: '(null)')], 404);
        }

        $absIn      = Storage::disk($srcDisk)->path($srcPath);
        $replace    = $request->boolean('replace', false);
        $highlights = collect($request->input('highlights', []));

        // --- Validate & normalize highlights ---
        // Structure: [{ page: 1-based int, rects: [{x,y,w,h} 0..1]}]
        $cleanByPage = [];
        $MAX_RECTS   = 2000; // safety cap
        $rectCount   = 0;

        foreach ($highlights as $h) {
            $page = (int) ($h['page'] ?? 0);
            $rects = is_array($h['rects'] ?? null) ? $h['rects'] : [];
            $cleanRects = [];
            foreach ($rects as $r) {
                $x = $this->clamp01($r['x'] ?? null);
                $y = $this->clamp01($r['y'] ?? null);
                $w = $this->clamp01($r['w'] ?? null);
                $hgt = $this->clamp01($r['h'] ?? null);
                if ($x === null || $y === null || $w === null || $hgt === null) continue;
                if ($w <= 0 || $hgt <= 0) continue;
                $cleanRects[] = ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $hgt];
                if (++$rectCount > $MAX_RECTS) break 2;
            }
            if ($page > 0 && $cleanRects) {
                $cleanByPage[$page] = array_merge($cleanByPage[$page] ?? [], $cleanRects);
            }
        }

        if (!$cleanByPage) {
            return response()->json(['message' => 'No valid highlights'], 422);
        }

        // --- Style (optional) ---
        $style = (array) ($request->input('style') ?? []);
        [$r, $g, $b] = $this->hexToRgb($style['color'] ?? '#FFEB3B');  // default yellow
        $alpha = $this->clamp($style['alpha'] ?? 0.35, 0.0, 1.0);

        // --- Output path on same disk ---
        $dir   = trim(dirname($srcPath), '/');
        $base  = pathinfo($srcPath, PATHINFO_FILENAME);
        $outRel = ($replace ? $dir : ($dir . '/highlighted')) . "/{$base}_hl_" . now()->format('Ymd_His') . ".pdf";
        if (!$replace) {
            Storage::disk($srcDisk)->makeDirectory($dir);
        }
        $absOut = Storage::disk($srcDisk)->path($outRel);

        // --- Render with FPDI/TCPDF ---
        $pdf = new Fpdi();
        $pageCount = $pdf->setSourceFile($absIn);

        // Clamp pages to existing pageCount and draw
        foreach (range(1, $pageCount) as $pageNo) {
            $tplIdx = $pdf->importPage($pageNo);
            $size   = $pdf->getTemplateSize($tplIdx);
            $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
            $pdf->useTemplate($tplIdx);

            if (!empty($cleanByPage[$pageNo])) {
                $pdf->SetFillColor($r, $g, $b);
                $pdf->SetAlpha($alpha);
                foreach ($cleanByPage[$pageNo] as $rect) {
                    $x = $rect['x'] * $size['width'];
                    $y = $rect['y'] * $size['height'];
                    $w = $rect['w'] * $size['width'];
                    $h = $rect['h'] * $size['height'];
                    $pdf->Rect($x, $y, $w, $h, 'F');
                }
                $pdf->SetAlpha(1);
            }
        }

        // Optional: tag the output
        $pdf->SetTitle('Highlighted');
        $pdf->Output($absOut, 'F');

        // --- Overwrite or return new file URL ---
        $finalRel = $outRel;
        if ($replace) {
            Storage::disk($srcDisk)->delete($srcPath);
            Storage::disk($srcDisk)->move($outRel, $srcPath);
            $finalRel = $srcPath;
        } else {
            // (Optional) If you track files, create a PaperFile entry
            // Uncomment and adjust if you have App\Models\PaperFile
            /*
            if (class_exists(\App\Models\PaperFile::class)) {
                \App\Models\PaperFile::create([
                    'paper_id' => $paper->id,
                    'disk'     => $srcDisk,
                    'path'     => $finalRel,
                    'mime'     => 'application/pdf',
                    'size'     => Storage::disk($srcDisk)->size($finalRel) ?: null,
                    'label'    => 'Highlighted',
                ]);
            }
            */
        }
        $rawUrl = Storage::disk($srcDisk)->url($finalRel);
        $ver    = now()->timestamp; // or Str::uuid()->toString()
        
        return response()->json([
            'message'   => 'Highlights applied',
            'file_url'  => $rawUrl , // . '?v=' . $ver versioned URL for immediate refresh
            'raw_url'   => $rawUrl,                // keep original in case you need it
            'replaced'  => $replace,
        ]);
    }
}
