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
     * Quick JSON for ROL page (unchanged convenience endpoint)
     */
    public function rol(Request $request)
    {
        $rows = Paper::query()
            ->select(['id','doi','authors','title','year'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($p) {
                return [
                    'id'       => $p->id,
                    'doi'      => $p->doi,
                    'authors'  => $p->authors,
                    'title'    => $p->title,
                    'year'     => $p->year,
                    'category' => Schema::hasColumn('papers','category') ? $p->category : null,
                    'keyIssue' => method_exists($p, 'getAttribute') ? $p->getAttribute('key_issue') : null,
                ];
            });

        return response()->json($rows);
    }

    /**
     * Literature list for the page (unchanged)
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

    /* --------------------------- Utilities --------------------------- */

    private function labelToKey(string $label): string
    {
        $k = strtolower(trim($label));
        $k = preg_replace('/[^a-z0-9]+/i', '_', $k);
        $k = trim($k, '_');
        return $k ?: 'col';
    }

    /**
     * Minimal HTML -> plain text cleaner for server payloads.
     * - decode entities
     * - normalize <br>/</p> to newlines
     * - strip tags
     * - collapse >2 newlines -> 2
     */
    private function cleanText(?string $html): string
    {
        if ($html === null) return '';
        $s = (string)$html;

        // normalize breaks
        $s = preg_replace('/<\s*br\s*\/?>/i', "\n", $s);
        $s = preg_replace('/<\/\s*p\s*>/i', "\n", $s);
        $s = preg_replace('/<\s*p[^>]*>/i', '', $s);

        // decode entities (incl. &nbsp;)
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // convert 2+ NBSP to newline, single NBSP to space
        $s = preg_replace('/\x{00A0}{2,}/u', "\n", $s);
        $s = str_replace("\xC2\xA0", ' ', $s); // NBSP to space (utf-8 bytes)

        // strip residual tags
        $s = strip_tags($s);

        // trim trailing spaces per line
        $lines = array_map(fn($ln) => rtrim($ln, " \t"), explode("\n", $s));
        $s = implode("\n", $lines);

        // collapse 3+ newlines to 2, trim
        $s = preg_replace("/\n{3,}/", "\n\n", $s);
        return trim($s);
    }

    /* --------------------------- Builders --------------------------- */

    /**
     * Build ROL dataset (columns + rows) from latest DONE review per paper.
     * $opts['keepHtml'] true to keep HTML in section values.
     */
    protected function buildRolDataset(array $filters, array $selections, array $opts = []): array
    {
        $keepHtml = (bool)($opts['keepHtml'] ?? false);

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

        $baseCols = [
            'Paper ID' => 'id',
            'DOI'      => 'doi',
            'Authors'  => 'authors',
            'Title'    => 'title',
            'Year'     => 'year',
        ];
        if (Schema::hasColumn('papers', 'category')) {
            $baseCols['Category'] = 'category';
        }

        $include      = Arr::get($selections, 'include', []);
        $includeOrder = Arr::get($selections, 'includeOrder', $sectionLabels);

        $selectedLabels = [];
        foreach ($includeOrder as $label) {
            if (!empty($include[$label])) $selectedLabels[] = $label;
        }

        // columns
        $columns = [];
        foreach ($baseCols as $label => $colname) {
            $columns[] = ['key' => $this->labelToKey($label), 'label' => $label];
        }
        foreach ($selectedLabels as $label) {
            $columns[] = ['key' => $this->labelToKey($label), 'label' => $label];
        }

        // query latest DONE review per paper
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

        if ($years = Arr::get($filters, 'years', [])) {
            $q->whereIn('papers.year', $years);
        }

        $rows = [];
        foreach ($q->get() as $rec) {
            $row = [];
            foreach ($baseCols as $label => $colname) {
                $row[$this->labelToKey($label)] = $rec->{$colname} ?? null;
            }

            $sections = [];
            if (!empty($rec->review_sections)) {
                $sections = is_string($rec->review_sections)
                    ? (json_decode($rec->review_sections, true) ?: [])
                    : (array)$rec->review_sections;
            }

            foreach ($selectedLabels as $label) {
                $val = Arr::get($sections, $label);
                if (!$keepHtml && is_string($val)) $val = $this->cleanText($val);
                $row[$this->labelToKey($label)] = $val;
            }

            $rows[] = $row;
        }

        // filter to rows with at least one selected section present
        if (count($selectedLabels) > 0) {
            $selectedKeys = array_map(fn($lab) => $this->labelToKey($lab), $selectedLabels);
            $rows = array_values(array_filter($rows, function ($r) use ($selectedKeys) {
                foreach ($selectedKeys as $k) {
                    if (array_key_exists($k, $r) && $r[$k] !== null && $r[$k] !== '') return true;
                }
                return false;
            }));
        }

        return [$columns, $rows];
    }

    /**
     * Build SYNOPSIS-like dataset:
     *  - Literature: latest DONE review's "Litracture Review"
     *  - Chapters:   body_html from chapters table, in requested order
     */
    protected function buildSynopsisDataset(array $filters, array $selections): array
    {
        // latest DONE review per paper
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

        $literature = [];
        foreach ($q->get() as $rec) {
            $sections = [];
            if (!empty($rec->review_sections)) {
                $sections = is_string($rec->review_sections)
                    ? (json_decode($rec->review_sections, true) ?: [])
                    : (array)$rec->review_sections;
            }
            $lit = Arr::get($sections, 'Litracture Review');
            $lit = is_string($lit) ? $this->cleanText($lit) : (is_null($lit) ? null : $this->cleanText(json_encode($lit)));

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

        // chapters in requested order
        $chapterIds = array_values(array_filter((array) Arr::get($selections, 'chapters', [])));
        $chapters = [];
        if (!empty($chapterIds)) {
            $rows = DB::table('chapters')
                ->select(['id','title','body_html'])
                ->whereIn('id', $chapterIds)
                ->orderByRaw("FIELD(id," . implode(',', array_map('intval',$chapterIds)) . ")")
                ->get();

            foreach ($rows as $ch) {
                $chapters[] = [
                    'id'        => $ch->id,
                    'title'     => $ch->title,
                    'body_html' => $this->cleanText($ch->body_html),
                ];
            }
        }

        return ['literature' => $literature, 'chapters' => $chapters];
    }

    /* --------------------------- Selection-driven PREVIEW --------------------------- */

    /**
     * PREVIEW — selection-driven response.
     *
     * Request:
     *  - name? string
     *  - template? string  (ignored for dataset composition)
     *  - format? string    (ignored for dataset composition; client renders)
     *  - filters: array
     *  - selections: { include, includeOrder, chapters? }
     *  - options.keepHtml? boolean (only affects ROL sections)
     *
     * Response always includes:
     *  {
     *    name, template,
     *    meta: { totalPapers, selectedSections, chapterCount },
     *    // present only if requested:
     *    columns, rows,            // ROL dataset
     *    literature, chapters      // Synopsis-like dataset
     *  }
     */
    public function preview(Request $request)
    {
        $payload = $request->validate([
            'name'       => 'nullable|string|max:255',
            'template'   => 'nullable|string',   // no longer decisive
            'format'     => 'nullable|string',   // no longer decisive
            'filters'    => 'required|array',
            'selections' => 'required|array',
            'options'    => 'sometimes|array',
            'options.keepHtml' => 'sometimes|boolean',
        ]);

        $name       = trim($payload['name'] ?? 'Report Preview');
        $template   = strtolower((string) ($payload['template'] ?? 'custom'));
        $filters    = $payload['filters'];
        $selections = $payload['selections'];
        $options    = Arr::get($payload, 'options', []);

        // Determine requested blocks
        $include      = Arr::get($selections, 'include', []);
        $includeOrder = Arr::get($selections, 'includeOrder', []);
        $chaptersSel  = (array) Arr::get($selections, 'chapters', []);

        $hasROL       = !!array_filter($include, fn($v) => (bool)$v === true);
        $hasChapters  = count($chaptersSel) > 0;

        $resp = [
            'name'     => $name,
            'template' => $template,
            'meta'     => [
                'totalPapers'  => (int) Paper::count(),
                'selectedSections' => [], // filled below if ROL requested
                'chapterCount' => (int) count($chaptersSel),
            ],
        ];

        // ROL block (if any section checked)
        if ($hasROL) {
            [$columns, $rows] = $this->buildRolDataset($filters, $selections, $options);

            // derive human labels for selected sections (after base columns)
            $baseCount = 5 + (Schema::hasColumn('papers', 'category') ? 1 : 0);
            $selectedSectionLabels = array_map(
                fn($c) => $c['label'],
                array_slice($columns, $baseCount)
            );

            $resp['meta']['selectedSections'] = $selectedSectionLabels;
            $resp['columns'] = $columns;
            $resp['rows']    = $rows;
        }

        // Synopsis-like block (if chapters requested)
        if ($hasChapters) {
            $syn = $this->buildSynopsisDataset($filters, $selections);
            $resp['literature'] = $syn['literature']; // [{paper_id,title,authors,year,text}]
            $resp['chapters']   = $syn['chapters'];   // [{id,title,body_html}]
        }

        // If neither selected, still send outline/kpis for UI (optional)
        if (!$hasROL && !$hasChapters) {
            $resp['outline'] = array_values($includeOrder);
            $resp['kpis'] = [
                ['label' => 'Total Papers', 'value' => (string) $resp['meta']['totalPapers']],
                ['label' => 'Chapters',     'value' => '0'],
            ];
        }

        return response()->json($resp);
    }

    /* --------------------------- Generate (stub) --------------------------- */

    public function generate(Request $request)
    {
        $payload = $request->validate([
            'template'   => 'nullable|string',
            'format'     => 'required|string|in:pdf,docx,xlsx,pptx',
            'filename'   => 'nullable|string|max:255',
            'filters'    => 'required|array',
            'selections' => 'required|array',
            'options'    => 'sometimes|array',
        ]);

        $format   = strtolower($payload['format']);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $payload['filename'] ?? 'report') . ".{$format}";
        $disk     = 'uploads';
        $dir      = 'reports/' . now()->format('Y/m');

        Storage::disk($disk)->makeDirectory($dir);
        $path = "{$dir}/{$filename}";

        // For now just store a small summary. Client still renders.
        $summary = [
            'format'     => $format,
            'selections' => $payload['selections'],
            'generatedAt'=> now()->toDateTimeString(),
        ];
        Storage::disk($disk)->put($path, json_encode($summary, JSON_PRETTY_PRINT));

        return response()->json([
            'disk'        => $disk,
            'path'        => $path,
            'downloadUrl' => Storage::disk($disk)->url($path),
        ]);
    }

    /* --------------------------- Bulk export (unchanged stub) --------------------------- */

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
