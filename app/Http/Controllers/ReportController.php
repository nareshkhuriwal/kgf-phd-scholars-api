<?php

namespace App\Http\Controllers;

use App\Models\Paper;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;


class ReportController extends Controller
{
    /**
     * ROL JSON source (kept for list/table pages)
     */
    public function rol(Request $request)
    {
        $rows = Paper::query()
            ->select(['id','doi','authors','title','year','category_of_paper','key_issue'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($p) {
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
     * Literature Reviews list (normalized field for the Literature page)
     */
    public function literature(Request $request)
    {
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
                'review' => $p->literature_review,
            ]);

        return response()->json($rows);
    }


    private function labelToKey(string $label): string
    {
        $k = strtolower(trim($label));
        $k = preg_replace('/[^a-z0-9]+/i', '_', $k);
        return trim($k, '_') ?: 'col';
    }

    private function cleanText(?string $htmlOrText): ?string
    {
        if ($htmlOrText === null) return null;
        // strip tags but keep paragraph breaks somewhat
        $text = preg_replace('/<\s*\/p\s*>/i', "\n\n", $htmlOrText);
        $text = strip_tags($text);
        // collapse whitespace
        $text = preg_replace('/[ \t]+\n/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Build ROL dataset – (already in your file) …
     * protected function buildRolDataset(...) { ... }
     */

    /**
     * Build SYNOPSIS dataset:
     *  - Pull latest DONE review per paper
     *  - Extract ONLY "Litracture Review" text
     *  - Pull requested chapter bodies
     *
     * Returns: [ 'literature' => [...], 'chapters' => [...] ]
     */
    protected function buildSynopsisDataset(array $filters, array $selections): array
    {
        // ---- Literature Review items (latest DONE review per paper)
        $latestDoneIds = DB::table('reviews')
            ->selectRaw('MAX(id) AS id, paper_id')
            ->where('status', 'done')
            ->groupBy('paper_id');

        $q = DB::table('papers')
            ->leftJoinSub($latestDoneIds, 'lr', 'lr.paper_id', '=', 'papers.id')
            ->leftJoin('reviews as rv', 'rv.id', '=', 'lr.id')
            ->select([
                'papers.id as paper_id',
                'papers.title',
                'papers.authors',
                'papers.year',
                'rv.review_sections',
            ])
            ->orderByDesc('papers.id');

        if ($years = Arr::get($filters, 'years', [])) $q->whereIn('papers.year', $years);
        // Add more optional filters later if you track area/venue/user

        $literature = [];
        foreach ($q->get() as $rec) {
            $sections = [];
            if (!empty($rec->review_sections)) {
                $sections = is_string($rec->review_sections)
                    ? (json_decode($rec->review_sections, true) ?: [])
                    : (array)$rec->review_sections;
            }
            $lit = Arr::get($sections, 'Litracture Review');
            $lit = $this->cleanText(is_string($lit) ? $lit : (is_null($lit) ? null : json_encode($lit)));
            if ($lit) {
                $literature[] = [
                    'paper_id' => $rec->paper_id,
                    'title'    => $rec->title,
                    'authors'  => $rec->authors,
                    'year'     => $rec->year,
                    'text'     => $lit,
                ];
            }
        }

        // ---- Chapters (IDs come from selections.chapters)
        $chapterIds = array_values(array_filter((array) Arr::get($selections, 'chapters', [])));
        $chapters = [];
        if (!empty($chapterIds)) {
            // Use DB directly to avoid model assumptions
            $rows = DB::table('chapters')
                ->select(['id','title','body_html'])
                ->whereIn('id', $chapterIds)
                ->orderByRaw("FIELD(id," . implode(',', array_map('intval',$chapterIds)) . ")") // preserve order
                ->get();

            foreach ($rows as $ch) {
                $chapters[] = [
                    'id'    => $ch->id,
                    'title' => $ch->title,
                    'body_html'  => $this->cleanText($ch->body_html),
                ];
            }
        }

        return [
            'literature' => $literature, // [{paper_id,title,authors,year,text}]
            'chapters'   => $chapters,   // [{id,title,body}]
        ];
    }

    /* ------------------------------ preview ------------------------------ */

    public function preview(Request $request)
    {
        $payload = $request->validate([
            'name'       => 'nullable|string|max:255',
            'template'   => 'required|string|in:synopsis,rol,final_thesis,presentation',
            'format'     => 'nullable|string', // client decides renderer
            'filters'    => 'required|array',
            'selections' => 'required|array',
            'options'    => 'sometimes|array',
        ]);

        $name     = trim($payload['name'] ?? 'Report Preview');
        $template = strtolower($payload['template']);

        if ($template === 'rol') {
            // your existing ROL branch (unchanged) ...
            [$columns, $rows] = $this->buildRolDataset(
                $payload['filters'],
                $payload['selections'],
                Arr::get($payload, 'options', [])
            );
            $baseCount = 5 + (Schema::hasColumn('papers', 'category') ? 1 : 0);
            $selectedSectionLabels = array_map(
                fn($c) => $c['label'],
                array_slice($columns, $baseCount)
            );

            return response()->json([
                'name'     => $name,
                'template' => 'rol',
                'meta'     => [
                    'totalPapers'      => (int) Paper::count(),
                    'selectedSections' => $selectedSectionLabels,
                ],
                'columns'  => $columns,
                'rows'     => $rows,
            ]);
        }

        if ($template === 'synopsis') {
            $data = $this->buildSynopsisDataset($payload['filters'], $payload['selections']);
            return response()->json([
                'name'      => $name ?: 'Synopsis Report',
                'template'  => 'synopsis',
                'outline'   => array_values(Arr::get($payload, 'selections.includeOrder', [])), // you’re sending this
                'kpis'      => [
                    ['label' => 'Total Papers', 'value' => (string) Paper::count()],
                    ['label' => 'Chapters',     'value' => (string) count(Arr::get($payload, 'selections.chapters', []))],
                ],
                'literature'=> $data['literature'],
                'chapters'  => $data['chapters'],
            ]);
        }

        // other templates (placeholder)
        return response()->json([
            'name'     => $name,
            'template' => $template,
            'outline'  => array_values(Arr::get($payload, 'selections.includeOrder', [])),
            'kpis'     => [
                ['label' => 'Total Papers', 'value' => (string) Paper::count()],
                ['label' => 'Chapters',     'value' => (string) count(Arr::get($payload, 'selections.chapters', []))],
            ],
        ]);
    }

    /**
     * Build ROL dataset (columns + rows) using latest DONE review per paper.
     * Returns [columns[], rows[]], where:
     *   columns = [{ key, label }, ...]
     *   rows    = [{ key => value, ... }, ...]
     *
     * $opts['keepHtml'] (bool) — if true, keeps HTML from review sections.
     */
    protected function buildRolDataset(array $filters, array $selections, array $opts = []): array
    {
        $keepHtml = (bool)($opts['keepHtml'] ?? false);

        // UI labels (match keys inside reviews.review_sections JSON)
        $sectionLabels = [
            'Litracture Review',
            'Key Issue',
            'Solution Approach / Methodology used',
            'Related Work',
            'Input Parameters used',
            'Hardware / Software / Technology Used',
            'Results',
            'Key advantages',
            'Limitations',
            'Citations',
            'Remarks',
        ];

        // Base fields from papers (always included)
        $baseCols = [
            'Paper ID' => 'id',
            'DOI'      => 'doi',
            'Authors'  => 'authors',
            'Title'    => 'title',
            'Year'     => 'year',
        ];
        // Include category if it exists in your schema
        if (Schema::hasColumn('papers', 'category')) {
            $baseCols['Category'] = 'category';
        }

        // Which sections user checked (preserve includeOrder)
        $include      = Arr::get($selections, 'include', []);
        $includeOrder = Arr::get($selections, 'includeOrder', $sectionLabels);

        $selectedLabels = [];
        foreach ($includeOrder as $label) {
            if (!empty($include[$label])) {
                $selectedLabels[] = $label;
            }
        }

        // Final columns: base + selected sections
        $columns = [];
        foreach ($baseCols as $label => $colname) {
            $columns[] = ['key' => $this->labelToKey($label), 'label' => $label];
        }
        foreach ($selectedLabels as $label) {
            $columns[] = ['key' => $this->labelToKey($label), 'label' => $label];
        }

        // ---- Query latest DONE review per paper ----
        $latestDoneIds = DB::table('reviews')
            ->selectRaw('MAX(id) AS id, paper_id')
            ->where('status', 'done')
            ->groupBy('paper_id');

        $q = DB::table('papers')
            ->leftJoinSub($latestDoneIds, 'lr', 'lr.paper_id', '=', 'papers.id')
            ->leftJoin('reviews as rv', 'rv.id', '=', 'lr.id')
            ->select([
                'papers.id',
                'papers.doi',
                'papers.authors',
                'papers.title',
                'papers.year',
                ...(Schema::hasColumn('papers', 'category') ? ['papers.category'] : []),
                'rv.review_sections',
            ])
            ->orderByDesc('papers.id');

        // Optional filters
        if ($years = Arr::get($filters, 'years', [])) {
            $q->whereIn('papers.year', $years);
        }
        // Uncomment/adapt if you later add these:
        // if ($areas = Arr::get($filters, 'areas', []))   { $q->whereIn('papers.area', $areas); }
        // if ($venues = Arr::get($filters, 'venues', [])) { $q->whereIn('papers.venue', $venues); }
        // if ($uids = Arr::get($filters, 'userIds', []))  { $q->whereIn('rv.user_id', $uids); }

        $rows = [];
        foreach ($q->get() as $rec) {
            // base fields
            $row = [];
            foreach ($baseCols as $label => $colname) {
                $row[$this->labelToKey($label)] = $rec->{$colname} ?? null;
            }

            // section values from JSON
            $sections = [];
            if (!empty($rec->review_sections)) {
                $sections = is_string($rec->review_sections)
                    ? (json_decode($rec->review_sections, true) ?: [])
                    : (array)$rec->review_sections;
            }

            foreach ($selectedLabels as $label) {
                $val = Arr::get($sections, $label);
                if (!$keepHtml && is_string($val)) {
                    $val = trim(strip_tags($val));
                }
                $row[$this->labelToKey($label)] = $val;
            }

            $rows[] = $row;
        }

        // Keep only rows with at least one selected section filled ("review completed")
        if (count($selectedLabels) > 0) {
            $selectedKeys = array_map(fn($lab) => $this->labelToKey($lab), $selectedLabels);

            $rows = array_values(array_filter($rows, function ($r) use ($selectedKeys) {
                foreach ($selectedKeys as $k) {
                    if (array_key_exists($k, $r) && $r[$k] !== null && $r[$k] !== '') {
                        return true;
                    }
                }
                return false;
            }));
        }

        return [$columns, $rows];
    }

    /**
     * PREVIEW — returns DATA ONLY (React renders & exports).
     * Request:
     *  - name? string
     *  - template: 'rol' | 'synopsis' | 'final_thesis' | 'presentation'
     *  - format? string (ignored by server here)
     *  - filters: {...}
     *  - selections: { include, includeOrder, chapters? }
     *  - options.keepHtml? boolean
     *
     * Response (ROL):
     *  {
     *    name, template,
     *    meta: { totalPapers, selectedSections },
     *    columns: [{key,label},...],
     *    rows: [{key:value,...},...]
     *  }
     */
    public function preview_excel(Request $request)
    {
        $payload = $request->validate([
            'name'       => 'nullable|string|max:255',
            'template'   => 'required|string|in:synopsis,rol,final_thesis,presentation',
            'format'     => 'nullable|string', // ignored here
            'filters'    => 'required|array',
            'selections' => 'required|array',
            'options'    => 'sometimes|array',
            'options.keepHtml' => 'sometimes|boolean',
        ]);

        $name     = trim($payload['name'] ?? 'Report Preview');
        $template = strtolower($payload['template']);

        if ($template === 'rol') {
            [$columns, $rows] = $this->buildRolDataset(
                $payload['filters'],
                $payload['selections'],
                Arr::get($payload, 'options', [])
            );

            // Selected section labels (after base columns)
            $baseCount = 5 + (Schema::hasColumn('papers', 'category') ? 1 : 0);
            $selectedSectionLabels = array_map(
                fn($c) => $c['label'],
                array_slice($columns, $baseCount)
            );

            return response()->json([
                'name'     => $name,
                'template' => 'rol',
                'meta'     => [
                    'totalPapers'      => (int) Paper::count(),
                    'selectedSections' => $selectedSectionLabels,
                ],
                'columns'  => $columns,
                'rows'     => $rows,
            ]);
        }

        // Lightweight placeholders for other templates — extend later as needed
        return response()->json([
            'name'     => $name,
            'template' => $template,
            'outline'  => array_values(Arr::get($payload, 'selections.includeOrder', [])),
            'kpis'     => [
                ['label' => 'Total Papers', 'value' => (string) Paper::count()],
                ['label' => 'Chapters',     'value' => (string) count(Arr::get($payload, 'selections.chapters', []))],
            ],
        ]);
    }


    public function generate(Request $request)
    {
        $payload = $request->validate([
            'template'   => 'required|string|in:synopsis,rol,final_thesis,presentation',
            'format'     => 'required|string|in:pdf,docx,xlsx,pptx',
            'filename'   => 'nullable|string|max:255',
            'filters'    => 'required|array',
            'selections' => 'required|array',
        ]);

        $format   = strtolower($payload['format']);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $payload['filename'] ?? 'report') . ".{$format}";
        $disk     = 'uploads'; // <-- changed
        $dir      = 'reports/' . now()->format('Y/m');

        Storage::disk($disk)->makeDirectory($dir);
        $path = "{$dir}/{$filename}";

        $outline = array_values(Arr::get($payload, 'selections.includeOrder', []));
        $content = "Template: {$payload['template']}\n"
                . "Format: {$format}\n"
                . "Outline: " . implode(', ', $outline) . "\n";

        Storage::disk($disk)->put($path, $content);

        return response()->json([
            'disk'        => $disk,
            'path'        => $path,
            'downloadUrl' => Storage::disk($disk)->url($path), // public URL
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
            'filters' => 'array',
        ]);

        $disk = 'public';
        $dir  = 'reports/' . now()->format('Y/m');
        Storage::disk($disk)->makeDirectory($dir);
        $path = "{$dir}/bulk_export_" . now()->timestamp . ".txt";
        Storage::disk($disk)->put($path, json_encode($payload, JSON_PRETTY_PRINT));

        return response()->json([
            'disk'        => $disk,
            'path'        => $path,
            'downloadUrl' => Storage::disk($disk)->url($path),
        ]);
    }
}
