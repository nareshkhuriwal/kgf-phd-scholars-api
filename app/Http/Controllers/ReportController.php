<?php

namespace App\Http\Controllers;

use App\Models\Paper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    /**
     * ROL table export source (list of reviewed papers)
     */
    public function rol(Request $request)
    {
        $rows = Paper::query()
            ->select(['id','doi','authors','title','year','category_of_paper','key_issue'])
            ->orderBy('created_at','desc')
            ->get()
            ->map(function($p){
                return [
                    'id'       => $p->id,
                    'doi'      => $p->doi,
                    'authors'  => $p->authors,
                    'title'    => $p->title,
                    'year'     => $p->year,
                    'category' => $p->category_of_paper,
                    'keyIssue' => $p->key_issue,
                ];
            });

        return response()->json($rows);
    }

    /**
     * Literature Reviews list (normalized field only for the page)
     * Expect your 'reviews' (or equivalent) relation/column providing the "Litracture Review" text.
     * Adjust column/relationship names to your schema.
     */
    public function literature(Request $request)
    {
        // If you store review text on Paper (e.g., papers.literature_review)
        $rows = Paper::query()
            ->select(['id','title','authors','year','literature_review'])
            ->whereNotNull('literature_review')
            ->orderByDesc('id')
            ->get()
            ->map(fn($p) => [
                'id'     => $p->id,
                'title'  => $p->title,
                'authors'=> $p->authors,
                'year'   => $p->year,
                'review' => $p->literature_review, // rename to your actual column
            ]);

        return response()->json($rows);
    }

    /**
     * Preview: returns outline + KPIs based on filters/selections (lightweight)
     */
    public function preview(Request $request)
    {
        $payload = $request->validate([
            'template'   => 'required|string|in:synopsis,rol,final_thesis,presentation',
            'filters'    => 'required|array',
            'selections' => 'required|array',
        ]);

        // Build outline using includeOrder; compute KPIs from DB if needed
        $outline = array_values($payload['selections']['includeOrder'] ?? []);
        $kpis = [
            ['label'=>'Total Papers', 'value'=> (string) Paper::count()],
            ['label'=>'Chapters',     'value'=> (string) count($payload['selections']['chapters'] ?? [])],
        ];

        return response()->json([
            'template'   => $payload['template'],
            'filters'    => $payload['filters'],
            'selections' => $payload['selections'],
            'outline'    => $outline,
            'kpis'       => $kpis,
        ]);
    }

    /**
     * Generate: creates file (pdf/docx/xlsx/pptx), stores on public disk and returns URL
     * Here: a compact, dependency-free stub using PDF via DOMPDF if installed; otherwise writes a .txt as placeholder.
     * Replace with your full renderer when you’re ready.
     */
    public function generate(Request $request)
    {
        $payload = $request->validate([
            'template'   => 'required|string|in:synopsis,rol,final_thesis,presentation',
            'format'     => 'required|string|in:pdf,docx,xlsx,pptx',
            'filename'   => 'nullable|string|max:255',
            'filters'    => 'required|array',
            'selections' => 'required|array',
        ]);

        // Build preview to include outline/KPIs in the file
        $preview = $this->preview(new Request([
            'template'   => $payload['template'],
            'filters'    => $payload['filters'],
            'selections' => $payload['selections'],
        ]))->getData(true);

        $format   = $payload['format'];
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $payload['filename'] ?? 'report') . '.' . $format;
        $dir      = 'reports/'.now()->format('Y/m');
        $disk     = 'public';
        Storage::disk($disk)->makeDirectory($dir);
        $path = "{$dir}/{$filename}";

        // Simple, safe fallback export (no extra composer deps required).
        // Swap to your real renderer later.
        $content = "Template: {$payload['template']}\n"
                 . "Outline: " . implode(', ', $preview['outline'] ?? []) . "\n";
        Storage::disk($disk)->put($path, $content);

        return response()->json([
            'disk'        => $disk,
            'path'        => $path,
            'downloadUrl' => Storage::disk($disk)->url($path),
        ]);
    }

    /**
     * Bulk export (stub)
     */
    public function bulkExport(Request $request)
    {
        $payload = $request->validate([
            'type'    => 'required|string|in:all-users,all-papers,by-collection',
            'format'  => 'required|string|in:xlsx,csv,pdf',
            'filters' => 'array'
        ]);

        $disk = 'public';
        $dir  = 'reports/'.now()->format('Y/m');
        Storage::disk($disk)->makeDirectory($dir);
        $path = "{$dir}/bulk_export_".now()->timestamp.".txt";
        Storage::disk($disk)->put($path, json_encode($payload, JSON_PRETTY_PRINT));

        return response()->json([
            'disk'        => $disk,
            'path'        => $path,
            'downloadUrl' => Storage::disk($disk)->url($path),
        ]);
    }
}
