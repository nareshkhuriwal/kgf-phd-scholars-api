<?php

namespace App\Http\Controllers;

use App\Models\Paper;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Concerns\SupervisesResearchers;


class ReportController extends Controller
{
    use SupervisesResearchers;

    /**
     * Quick JSON for ROL page (owner-scoped)
     */
    public function rolOld(Request $request)
    {
        $uid = $request->user()->id ?? abort(401, 'Unauthenticated');

        $visibleUserIds = $this->visibleUserIdsForCurrent($request);

        $rows = Paper::query()
            ->select(['id', 'doi', 'authors', 'title', 'year', ...(Schema::hasColumn('papers', 'category') ? ['category'] : [])])
            ->whereIn('created_by', $visibleUserIds)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($p) {
                return [
                    'id'       => $p->id,
                    'doi'      => $p->doi,
                    'authors'  => $p->authors,
                    'title'    => $p->title,
                    'year'     => $p->year,
                    'category' => Schema::hasColumn('papers', 'category') ? $p->category : null,
                    'keyIssue' => method_exists($p, 'getAttribute') ? $p->getAttribute('key_issue') : null,
                ];
            });

        return response()->json($rows);
    }


    public function rol(Request $request)
    {
        $uid = $request->user()->id ?? abort(401);

        [$columns, $rows] = $this->buildRolDataset(
            uid: $uid,
            filters: [],
            selections: [],
            opts: ['keepHtml' => false]
        );

        return response()->json([
            'columns' => $columns,
            'rows'    => $rows,
            'total'   => count($rows),
        ]);
    }


    /**
     * Literature list for the page (owner-scoped)
     */
    public function literature(Request $request)
    {
        $uid = $request->user()->id ?? abort(401, 'Unauthenticated');

        $rows = Paper::query()
            ->select(['id', 'title', 'authors', 'year', 'literature_review'])
            ->where('created_by', $uid)
            ->whereNotNull('literature_review')
            ->orderByDesc('id')
            ->get()
            ->map(fn($p) => [
                'id'     => $p->id,
                'title'  => $p->title,
                'authors' => $p->authors,
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

    private function cleanText(?string $html): string
    {
        if ($html === null) return '';
        $s = (string)$html;

        $s = preg_replace('/<\s*br\s*\/?>/i', "\n", $s);
        $s = preg_replace('/<\/\s*p\s*>/i', "\n", $s);
        $s = preg_replace('/<\s*p[^>]*>/i', '', $s);

        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace('/\x{00A0}{2,}/u', "\n", $s);
        $s = str_replace("\xC2\xA0", ' ', $s);

        $lines = array_map(fn($ln) => rtrim($ln, " \t"), explode("\n", $s));
        $s = implode("\n", $lines);

        $s = preg_replace("/\n{3,}/", "\n\n", $s);
        return trim($s);
    }

    /* --------------------------- Builders (owner-scoped) --------------------------- */

    /**
     * Build ROL dataset (columns + rows) from latest DONE review per paper for this user.
     */
    protected function buildRolDatasetOld(int $uid, array $filters, array $selections, array $opts = []): array
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

        $columns = [];
        foreach ($baseCols as $label => $colname) {
            $columns[] = ['key' => $this->labelToKey($label), 'label' => $label];
        }
        foreach ($selectedLabels as $label) {
            $columns[] = ['key' => $this->labelToKey($label), 'label' => $label];
        }

        // latest DONE review per paper (owner-scoped: papers.created_by = $uid and reviews.user_id = $uid)
        $latestDoneIds = DB::table('reviews')
            ->selectRaw('MAX(id) AS id, paper_id')
            ->where('user_id', $uid)
            ->where('status', 'done')
            ->groupBy('paper_id');

        $q = DB::table('papers')
            ->leftJoinSub($latestDoneIds, 'lr', 'lr.paper_id', '=', 'papers.id')
            ->leftJoin('reviews as rv', function ($j) use ($uid) {
                $j->on('rv.id', '=', 'lr.id')->where('rv.user_id', '=', $uid);
            })
            ->select([
                'papers.id',
                'papers.doi',
                'papers.authors',
                'papers.title',
                'papers.year',
                ...(Schema::hasColumn('papers', 'category') ? ['papers.category'] : []),
                'rv.review_sections',
            ])
            ->where('papers.created_by', $uid)
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

    private function normalizeReviewSections(array $sections, $sectionDefs): array
    {
        $normalized = [];

        foreach ($sectionDefs as $sec) {
            // Try DB key first
            if (array_key_exists($sec->key, $sections)) {
                $normalized[$sec->key] = $sections[$sec->key];
                continue;
            }

            // Fallback: label-based legacy data
            if (array_key_exists($sec->label, $sections)) {
                $normalized[$sec->key] = $sections[$sec->label];
            }
        }

        return $normalized;
    }


    protected function buildRolDataset(
        int $uid,
        array $filters,
        array $selections,
        array $opts = []
    ): array {
        $keepHtml = (bool)($opts['keepHtml'] ?? false);

        /* ---------------------------------
     * 1. Load section definitions (DB)
     * --------------------------------- */
        $sectionDefs = \App\Models\ReviewSectionKey::query()
            ->where('active', 1)
            ->orderBy('order')
            ->get(['label', 'key']);

        if ($sectionDefs->isEmpty()) {
            return [[], []];
        }

        /* ---------------------------------
     * 2. Base paper columns
     * --------------------------------- */
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

        /* ---------------------------------
     * 3. Build column metadata
     * --------------------------------- */
        $columns = [];

        foreach ($baseCols as $label => $col) {
            $columns[] = [
                'key'   => $this->labelToKey($label),
                'label' => $label,
            ];
        }

        foreach ($sectionDefs as $sec) {
            $columns[] = [
                'key'   => $sec->key,
                'label' => $sec->label,
            ];
        }

        /* ---------------------------------
     * 4. Latest DONE review per paper
     * --------------------------------- */
        $latestDoneIds = DB::table('reviews')
            ->selectRaw('MAX(id) AS id, paper_id')
            ->where('user_id', $uid)
            // ->where('status', 'done')
            ->groupBy('paper_id');

        $query = DB::table('papers')
            ->leftJoinSub($latestDoneIds, 'lr', 'lr.paper_id', '=', 'papers.id')
            ->leftJoin('reviews as rv', function ($j) use ($uid) {
                $j->on('rv.id', '=', 'lr.id')
                    ->where('rv.user_id', '=', $uid);
            })
            ->where('papers.created_by', $uid)
            ->select([
                'papers.*',
                'rv.review_sections',
            ])
            ->orderByDesc('papers.id');

        if ($years = Arr::get($filters, 'years')) {
            $query->whereIn('papers.year', $years);
        }

        /* ---------------------------------
     * 5. Build rows
     * --------------------------------- */

        $rows = [];

        foreach ($query->get() as $rec) {
            $row = [];

            // Base columns
            foreach ($baseCols as $label => $col) {
                $row[$this->labelToKey($label)] = $rec->{$col} ?? null;
            }

            // Decode review JSON
            $rawSections = [];
            if (!empty($rec->review_sections)) {
                $rawSections = json_decode($rec->review_sections, true) ?: [];
            }

            // Normalize (LABEL → KEY)
            $sections = $this->normalizeReviewSections($rawSections, $sectionDefs);

            $hasReview = false;

            // Assign section values (THIS WAS MISSING)
            foreach ($sectionDefs as $sec) {
                $value = $sections[$sec->key] ?? null;

                if (!$keepHtml && is_string($value)) {
                    $value = $this->cleanText($value);
                }

                if (!empty($value)) {
                    $hasReview = true;
                }

                $row[$sec->key] = $value;
            }

            $row['_has_review'] = $hasReview;
            $rows[] = $row;
        }

        return [$columns, $rows];
    }


    /**
     * Build SYNOPSIS-like dataset for this user.
     */
    protected function buildSynopsisDataset(int $uid, array $filters, array $selections): array
    {
        $latestDoneIds = DB::table('reviews')
            ->selectRaw('MAX(id) AS id, paper_id')
            ->where('user_id', $uid)
            ->where('status', 'done')
            ->groupBy('paper_id');

        $q = DB::table('papers')
            ->leftJoinSub($latestDoneIds, 'lr', 'lr.paper_id', '=', 'papers.id')
            ->leftJoin('reviews as rv', function ($j) use ($uid) {
                $j->on('rv.id', '=', 'lr.id')->where('rv.user_id', '=', $uid);
            })
            ->select([
                'papers.id as paper_id',
                'papers.title',
                'papers.authors',
                'papers.year',
                'rv.review_sections',
            ])
            ->where('papers.created_by', $uid)
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

        // chapters selection (owner-scoped)
        $chapterIds = array_values(array_filter((array) Arr::get($selections, 'chapters', [])));
        $chapters = [];
        if (!empty($chapterIds)) {
            $rows = DB::table('chapters')
                ->select(['id', 'title', 'body_html'])
                ->where('user_id', $uid)
                ->whereIn('id', $chapterIds)
                ->orderByRaw("FIELD(id," . implode(',', array_map('intval', $chapterIds)) . ")")
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

    public function preview(Request $request)
    {
        $uid = $request->user()->id ?? abort(401, 'Unauthenticated');

        $payload = $request->validate([
            'name'       => 'nullable|string|max:255',
            'template'   => 'nullable|string',
            'format'     => 'nullable|string',
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

        $include      = Arr::get($selections, 'include', []);
        $includeOrder = Arr::get($selections, 'includeOrder', []);
        $chaptersSel  = (array) Arr::get($selections, 'chapters', []);

        $hasROL       = !!array_filter($include, fn($v) => (bool)$v === true);
        $hasChapters  = count($chaptersSel) > 0;

        $resp = [
            'name'     => $name,
            'template' => $template,
            'meta'     => [
                'totalPapers'      => (int) Paper::where('created_by', $uid)->count(),
                'selectedSections' => [],
                'chapterCount'     => (int) count($chaptersSel),
            ],
        ];

        if ($hasROL) {
            [$columns, $rows] = $this->buildRolDataset($uid, $filters, $selections, $options);

            $baseCount = 5 + (Schema::hasColumn('papers', 'category') ? 1 : 0);
            $selectedSectionLabels = array_map(
                fn($c) => $c['label'],
                array_slice($columns, $baseCount)
            );

            $resp['meta']['selectedSections'] = $selectedSectionLabels;
            $resp['columns'] = $columns;
            $resp['rows']    = $rows;
        }

        if ($hasChapters) {
            $syn = $this->buildSynopsisDataset($uid, $filters, $selections);
            $resp['literature'] = $syn['literature'];
            $resp['chapters']   = $syn['chapters'];
        }

        if (!$hasROL && !$hasChapters) {
            $resp['outline'] = array_values($includeOrder);
            $resp['kpis'] = [
                ['label' => 'Total Papers', 'value' => (string) $resp['meta']['totalPapers']],
                ['label' => 'Chapters',     'value' => '0'],
            ];
        }

        return response()->json($resp);
    }

    /* --------------------------- Generate (owner-scoped stub) --------------------------- */

    public function generate(Request $request)
    {
        $uid = $request->user()->id ?? abort(401, 'Unauthenticated');

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

        // Store a small summary (still owner-scoped content on client side)
        $summary = [
            'format'      => $format,
            'selections'  => $payload['selections'],
            'generatedAt' => now()->toDateTimeString(),
            'userId'      => $uid,
        ];
        Storage::disk($disk)->put($path, json_encode($summary, JSON_PRETTY_PRINT));

        return response()->json([
            'disk'        => $disk,
            'path'        => $path,
            'downloadUrl' => Storage::disk($disk)->url($path),
        ]);
    }

    /* --------------------------- Bulk export (owner-scoped stub) --------------------------- */

    public function bulkExport(Request $request)
    {
        $uid = $request->user()->id ?? abort(401, 'Unauthenticated');

        $payload = $request->validate([
            'type'    => 'required|string|in:all-users,all-papers,by-collection',
            'format'  => 'required|string|in:xlsx,csv,pdf',
            'filters' => 'array',
        ]);

        // Even if client says "all-users", we only export THIS user's scope here.
        $disk = 'public';
        $dir  = 'reports/' . now()->format('Y/m');
        Storage::disk($disk)->makeDirectory($dir);
        $path = "{$dir}/bulk_export_" . now()->timestamp . ".txt";

        $payloadToSave = $payload;
        $payloadToSave['effective_scope'] = 'current-user';
        $payloadToSave['user_id'] = $uid;

        Storage::disk($disk)->put($path, json_encode($payloadToSave, JSON_PRETTY_PRINT));

        return response()->json([
            'disk'        => $disk,
            'path'        => $path,
            'downloadUrl' => Storage::disk($disk)->url($path),
        ]);
    }
}
