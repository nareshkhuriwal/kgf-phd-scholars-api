<?php

namespace App\Http\Controllers;

use App\Models\Paper;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Support\ResolvesApiScope;
use App\Support\ResolvesDashboardScope;
use App\Services\CitationFormatter;
use App\Models\Citation;
use App\Models\UserSetting;



class ReportController extends Controller
{
    use ResolvesApiScope, ResolvesDashboardScope;

    /**
     * Validate that current user can generate reports for target user
     */
    private function validateReportAccess($currentUser, $targetUserId)
    {
        $role = $currentUser->role;
        $currentUserId = $currentUser->id;

        // User can always generate reports for themselves
        if ($currentUserId == $targetUserId) {
            return;
        }

        // Superuser can generate reports for anyone
        if ($role === 'superuser') {
            return;
        }

        // Admin can generate reports for their supervisors and researchers
        if ($role === 'admin') {
            // Check if target is supervisor created by this admin
            $isMySupervisor = User::where('id', $targetUserId)
                ->where('role', 'supervisor')
                ->where('created_by', $currentUserId)
                ->exists();

            if ($isMySupervisor) {
                return;
            }

            // Check if target is researcher under admin's supervisors
            $supervisorIds = User::where('role', 'supervisor')
                ->where('created_by', $currentUserId)
                ->pluck('id');

            if ($supervisorIds->isNotEmpty()) {
                $isMyResearcher = User::where('users.id', $targetUserId)
                    ->where('users.role', 'researcher')
                    ->join('researcher_invites', 'users.email', '=', 'researcher_invites.researcher_email')
                    ->whereIn('researcher_invites.created_by', $supervisorIds)
                    ->where('researcher_invites.status', 'accepted')
                    ->whereNull('researcher_invites.revoked_at')
                    ->exists();

                if ($isMyResearcher) {
                    return;
                }
            }

            abort(403, 'You do not have access to generate reports for this user');
        }

        // Supervisor can generate reports for their researchers
        if ($role === 'supervisor') {
            $isMyResearcher = User::where('users.id', $targetUserId)
                ->where('users.role', 'researcher')
                ->join('researcher_invites', 'users.email', '=', 'researcher_invites.researcher_email')
                ->where('researcher_invites.created_by', $currentUserId)
                ->where('researcher_invites.status', 'accepted')
                ->whereNull('researcher_invites.revoked_at')
                ->exists();

            if ($isMyResearcher) {
                return;
            }

            abort(403, 'You do not have access to generate reports for this user');
        }

        // Researcher can only generate reports for themselves
        abort(403, 'You can only generate reports for yourself');
    }

    /**
     * Quick JSON for ROL page (accessible users scoped)
     */
    public function rol(Request $request)
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('ROL report called', [
            'user_id' => $request->user()->id,
            'role' => $request->user()->role
        ]);

        // Get user IDs based on dashboard scope
        // $userIds = $this->resolveDashboardUserIds($request);
        $userIds = $this->normalizeUserIds(
            $this->resolveDashboardUserIds($request)
        );


        Log::info('ROL user IDs resolved', [
            'user_ids' => $userIds,
            'count' => count($userIds)
        ]);

        [$columns, $rows] = $this->buildRolDataset(
            userIds: $userIds,
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
     * Literature list for the page (accessible users scoped)
     */
    public function literature(Request $request)
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Literature report called', [
            'user_id' => $request->user()->id,
            'role' => $request->user()->role
        ]);

        // Get user IDs based on dashboard scope
        // $userIds = $this->resolveDashboardUserIds($request);
        $userIds = $this->normalizeUserIds(
            $this->resolveDashboardUserIds($request)
        );


        Log::info('Literature user IDs resolved', [
            'user_ids' => $userIds,
            'count' => count($userIds)
        ]);

        // Latest review per paper for accessible users
        $latestReviewIds = DB::table('reviews')
            ->selectRaw('MAX(id) as id, paper_id')
            ->whereIn('user_id', $userIds)
            // ->where('status', 'done') // enable when ready
            ->groupBy('paper_id');

        $rows = DB::table('papers')
            ->leftJoinSub($latestReviewIds, 'lr', 'lr.paper_id', '=', 'papers.id')
            ->leftJoin('reviews as rv', function ($j) use ($userIds) {
                $j->on('rv.id', '=', 'lr.id')
                    ->whereIn('rv.user_id', $userIds);
            })
            ->whereIn('papers.created_by', $userIds)
            ->orderByDesc('papers.id')
            ->select([
                'papers.id',
                'papers.title',
                'papers.authors',
                'papers.year',
                'rv.review_sections',
            ])
            ->get()
            ->map(function ($rec) {
                $sections = [];

                if (!empty($rec->review_sections)) {
                    $sections = json_decode($rec->review_sections, true) ?: [];
                }

                $lit =
                    $sections['literature_review']
                    ?? $sections['Literature Review']
                    ?? null;

                return [
                    'id'      => $rec->id,
                    'title'   => $rec->title,
                    'authors' => $rec->authors,
                    'year'    => $rec->year,
                    'review'  => is_string($lit) ? $this->cleanText($lit) : null,
                ];
            })
            ->filter(fn($r) => !empty($r['review']))
            ->values();

        Log::info('Literature rows retrieved', [
            'count' => $rows->count()
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

    /* --------------------------- Builders (specific user scoped) --------------------------- */

    /**
     * Build ROL dataset (columns + rows) from latest DONE review per paper for specific user.
     */
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

    private function isLiteratureSection($sec): bool
    {
        $k = strtolower(trim((string) ($sec->key ?? '')));
        $l = strtolower(trim((string) ($sec->label ?? '')));

        $litKeys = [
            'literature_review',
            'literature review',
            'litracture review',
        ];

        return in_array($k, $litKeys, true) || in_array($l, $litKeys, true);
    }


    protected function buildRolDataset(
        array $userIds,
        array $filters,
        array $selections,
        array $opts = []
    ): array {
        $keepHtml = (bool)($opts['keepHtml'] ?? false);

        /* ---------------------------------
         * 1. Load section definitions (DB)
         * --------------------------------- */
        // $sectionDefs = \App\Models\ReviewSectionKey::query()
        //     ->where('active', 1)
        //     ->orderBy('order')
        //     ->get(['label', 'key']);

        $sectionDefs = \App\Models\ReviewSectionKey::query()
            ->where('active', 1)
            ->orderBy('order')
            ->get(['label', 'key'])
            ->filter(fn($sec) => !$this->isLiteratureSection($sec))
            ->values();


        if ($sectionDefs->isEmpty()) {
            return [[], []];
        }

        /* ---------------------------------
         * 2. Base paper columns
         * --------------------------------- */
        $baseCols = [
            'Paper ID'      => 'id',
            'Title'         => 'title',
            'Author(s)'     => 'authors',
            'DOI'           => 'doi',
            'Year'          => 'year',
            'Category'      => 'category',
            'Journal'       => 'journal',
            'ISSN / ISBN'   => 'issn_isbn',
            'Publisher'     => 'publisher',
            'Place'         => 'place',
            'Volume'        => 'volume',
            'Issue'         => 'issue',
            'Page No'       => 'page_no',
            'Area / Sub Area' => 'area',
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
            ->whereIn('user_id', $userIds)
            // ->where('status', 'done')
            ->groupBy('paper_id');

        // $query = DB::table('papers')
        //     ->leftJoinSub($latestDoneIds, 'lr', 'lr.paper_id', '=', 'papers.id')
        //     ->leftJoin('reviews as rv', function ($j) use ($userIds) {
        //         $j->on('rv.id', '=', 'lr.id')
        //             ->whereIn('rv.user_id', $userIds);
        //     })
        //     ->whereIn('papers.created_by', $userIds)
        //     ->select([
        //         'papers.*',
        //         'rv.review_sections',
        //     ])
        //     ->orderByDesc('papers.id');

        $archivedPaperIds = DB::table('reviews')
            ->whereIn('user_id', $userIds)
            ->where('status', 'archived')
            ->select('paper_id');

        $query = DB::table('papers')
            ->leftJoinSub($latestDoneIds, 'lr', 'lr.paper_id', '=', 'papers.id')
            ->leftJoin('reviews as rv', function ($j) use ($userIds) {
                $j->on('rv.id', '=', 'lr.id')
                ->whereIn('rv.user_id', $userIds);
            })
            ->whereIn('papers.created_by', $userIds)
            ->whereNotIn('papers.id', $archivedPaperIds)   // ✅ EXCLUDE ARCHIVED
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

            // Assign section values
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

        Log::info('ROL dataset built', [
            'column_count' => count($columns),
            'row_count' => count($rows)
        ]);

        return [$columns, $rows];
    }

    private function normalizeUserIds(array $userIds): array
    {
        return array_values(
            array_unique(
                array_map(
                    'intval',
                    Arr::flatten($userIds)
                )
            )
        );
    }


    /**
     * Build SYNOPSIS-like dataset for specific user.
     */
    protected function buildSynopsisDataset(array $userIds, array $filters, array $selections): array
    {
        $latestDoneIds = DB::table('reviews')
            ->selectRaw('MAX(id) AS id, paper_id')
            ->whereIn('user_id', $userIds)
            ->where('status', 'done')
            ->groupBy('paper_id');

        $q = DB::table('papers')
            ->leftJoinSub($latestDoneIds, 'lr', 'lr.paper_id', '=', 'papers.id')
            ->leftJoin('reviews as rv', function ($j) use ($userIds) {
                $j->on('rv.id', '=', 'lr.id')
                    ->whereIn('rv.user_id', $userIds);
            })
            ->select([
                'papers.id as paper_id',
                'papers.title',
                'papers.authors',
                'papers.year',
                'rv.review_sections',
            ])
            ->whereIn('papers.created_by', $userIds)
            ->orderByDesc('papers.id');

        if ($years = Arr::get($filters, 'years', [])) $q->whereIn('papers.year', $years);

        $records = $q->get();

        $literature = [];
        $otherSections = [];

        $selectedSections = collect(Arr::get($selections, 'include', []))
            ->filter(fn($v) => (bool) $v === true)
            ->keys()
            ->map(fn($k) => strtolower(trim($k)))
            ->values()
            ->all();


        foreach ($records as $rec) {

            if (empty($rec->review_sections)) {
                continue;
            }

            $sections = is_string($rec->review_sections)
                ? (json_decode($rec->review_sections, true) ?: [])
                : (array) $rec->review_sections;

            // ---------- LITERATURE (UNCHANGED LOGIC, BUT SAFER KEY MATCHING) ----------
            $lit = null;
            foreach ($sections as $key => $value) {
                $normalizedKey = strtolower(trim($key));
                if (in_array($normalizedKey, [
                    'literature review',
                    'literature_review',
                    'litracture review'
                ], true)) {
                    $lit = $value;
                    break;
                }
            }

            if (is_string($lit) && trim(strip_tags($lit)) !== '') {
                $literature[] = [
                    'paper_id' => $rec->paper_id,
                    'title'    => $rec->title,
                    'authors'  => $rec->authors,
                    'year'     => $rec->year,
                    'html'     => $lit,
                    'text'     => $this->cleanText($lit),
                ];
            }

            // ---------- OTHER SECTIONS ----------
            foreach ($sections as $sectionName => $html) {

                $normalizedKey = strtolower(trim($sectionName));

                // Skip literature variants
                if (in_array($normalizedKey, [
                    'literature review',
                    'literature_review',
                    'litracture review',
                    'citations',
                    'citation',
                    'references'
                ], true)) {
                    continue;
                }

                // ✅ NEW: skip if not selected in UI
                if (!in_array($normalizedKey, $selectedSections, true)) {
                    continue;
                }
                if (!is_string($html)) continue;
                if (trim(strip_tags($html)) === '') continue;

                $otherSections[] = [
                    'paper_id' => $rec->paper_id,
                    'title'    => $rec->title,
                    'authors'  => $rec->authors,
                    'year'     => $rec->year,
                    'section'  => $sectionName,
                    'html'     => $html,
                    'text'     => $this->cleanText($html),
                ];
            }
        }


        // chapters selection (specific user scoped)
        // $chapterIds = array_values(array_filter((array) Arr::get($selections, 'chapters', [])));
        $chapterIds = array_values(
            array_unique(
                array_map(
                    'intval',
                    Arr::flatten((array) Arr::get($selections, 'chapters', []))
                )
            )
        );

        $chapters = [];
        if (!empty($chapterIds)) {
            $rows = DB::table('chapters')
                ->select(['id', 'title', 'body_html', 'chapter_section', 'order_index'])
                ->whereIn('user_id', $userIds)
                ->whereIn('id', $chapterIds)
                ->orderBy('order_index', 'asc') 
                // ->orderByRaw("FIELD(id," . implode(',', array_map('intval', $chapterIds)) . ")")
                ->get();

            foreach ($rows as $ch) {
                $chapters[] = [
                    'id'        => $ch->id,
                    'title'     => $ch->title,
                    'body_html' => $this->cleanText($ch->body_html),
                    'chapter_section'   => $ch->chapter_section,
                    'order_index' => $ch->order_index,    
                ];
            }
        }

        // $chapters = DB::table('chapters')
        //     ->select(['id', 'title', 'body_html', 'chapter_section', 'order_index'])
        //     ->whereIn('user_id', $userIds)
        //     ->whereIn('id', $chapterIds)
        //     ->orderBy('order_index', 'asc')   // 🔑 canonical order
        //     ->get()
        //     ->map(function ($ch) {
        //         return [
        //             'id'              => $ch->id,
        //             'title'           => $ch->title,
        //             'body_html'       => $this->cleanText($ch->body_html),
        //             'chapter_section' => $ch->chapter_section,
        //             'order_index'     => $ch->order_index,
        //         ];
        //     })
        //     ->values()
        //     ->all();


        // -------------------------------------------------
        // BUILD FINAL FORMATTED REFERENCES (THESIS SAFE)
        // -------------------------------------------------

        // Resolve citation style from user settings (default: MLA)
        $citationStyle = UserSetting::whereIn('user_id', $userIds)
            ->value('citation_style') ?? 'mla';


        $citationRows = DB::table('review_citations as rc')
            ->join('citations as c', 'c.id', '=', 'rc.citation_id')
            ->join('reviews as r', 'r.id', '=', 'rc.review_id')
            ->whereIn('r.user_id', $userIds)
            ->whereNotNull('rc.first_used_order')
            ->orderBy('rc.first_used_order', 'asc')
            ->select([
                'rc.first_used_order',
                'c.id',
            ])
            ->distinct()
            ->get();

        $formattedCitations = [];

        foreach ($citationRows as $row) {

            /** @var Citation $citation */
            $citation = Citation::find($row->id);
            if (!$citation) continue;

            $formattedCitations[] = [
                'order' => $row->first_used_order,
                'text'  => CitationFormatter::format(
                    $citation,
                    $citationStyle,
                    (int) $row->first_used_order
                ),
                'key'   => $citation->citation_key,
                'style' => $citationStyle
            ];
        }



        return [
            'literature' => $literature,
            'chapters'   => $chapters,
            'citations'  => $formattedCitations,
            'citationStyle' => $citationStyle,
            // 'sections'       => $otherSections,
            'sections' => collect($otherSections)
                ->groupBy('section')
                ->map(fn($items) => $items->pluck('html'))
                ->toArray(),

        ];


        // return ['literature' => $literature, 'chapters' => $chapters];
    }

    /* --------------------------- Selection-driven PREVIEW --------------------------- */

    public function preview(Request $request)
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Report preview called', [
            'user_id' => $request->user()->id,
            'role' => $request->user()->role
        ]);

        $payload = $request->validate([
            'name'       => 'nullable|string|max:255',
            'template'   => 'nullable|string',
            'format'     => 'nullable|string',
            'filters'    => 'required|array',
            'filters.userId' => 'nullable|integer', // The user the report is FOR
            'filters.areas' => 'nullable|array',
            'filters.years' => 'nullable|array',
            'filters.venues' => 'nullable|array',
            'selections' => 'required|array',
            'options'    => 'sometimes|array',
            'options.keepHtml' => 'sometimes|boolean',
        ]);

        $name       = trim($payload['name'] ?? 'Report Preview');
        $template   = strtolower((string) ($payload['template'] ?? 'custom'));
        $filters    = $payload['filters'];
        $selections = $payload['selections'];
        $options    = Arr::get($payload, 'options', []);

        // Get userId from filters - this is the user the report is FOR
        $targetUserId = $filters['userId'] ?? null;

        // If no userId specified, use current user's ID (for researchers)
        if (!$targetUserId) {
            $targetUserId = $request->user()->id;
        }

        // Validate access: ensure current user can generate reports for target user
        $this->validateReportAccess($request->user(), $targetUserId);

        // Use only the target user's data
        // $userIds = [(int) $targetUserId];
        $userIds = $this->normalizeUserIds([$targetUserId]);

        Log::info('Preview using target user', [
            'current_user_id' => $request->user()->id,
            'target_user_id' => $targetUserId,
            'user_ids' => $userIds
        ]);

        $include      = Arr::get($selections, 'include', []);
        $includeOrder = Arr::get($selections, 'includeOrder', []);
        $chaptersSel  = (array) Arr::get($selections, 'chapters', []);

        $hasROL = $template !== 'synopsis' && !!array_filter($include, fn($v) => (bool)$v === true);
        $hasChapters  = count($chaptersSel) > 0;

        // $totalPapers = Paper::whereIn('created_by', $userIds)->count();
        $totalPapers = Paper::whereIn('created_by', $userIds)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                ->from('reviews')
                ->whereColumn('reviews.paper_id', 'papers.id')
                ->where('reviews.status', 'archived');
            })
            ->count();

        $resp = [
            'name'     => $name,
            'template' => $template,
            'meta'     => [
                'totalPapers'      => (int) $totalPapers,
                'selectedSections' => [],
                'chapterCount'     => (int) count($chaptersSel),
                'targetUserId'     => $targetUserId,
            ],
        ];

        if ($hasROL) {
            [$columns, $rows] = $this->buildRolDataset($userIds, $filters, $selections, $options);

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
            $syn = $this->buildSynopsisDataset($userIds, $filters, $selections);
            $resp['literature'] = $syn['literature'];
            $resp['chapters']   = $syn['chapters'];
            $resp['citations']   = $syn['citations'];
            $resp['sections']   = $syn['sections'] ?? [];
        }

        if (!$hasROL && !$hasChapters) {
            $resp['outline'] = array_values($includeOrder);
            $resp['kpis'] = [
                ['label' => 'Total Papers', 'value' => (string) $resp['meta']['totalPapers']],
                ['label' => 'Chapters',     'value' => '0'],
            ];
        }

        Log::info('Report preview generated', [
            'has_rol' => $hasROL,
            'has_chapters' => $hasChapters,
            'total_papers' => $totalPapers,
            'target_user_id' => $targetUserId
        ]);

        return response()->json($resp);
    }

    /* --------------------------- Generate (specific user scoped) --------------------------- */

    public function generate(Request $request)
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Report generate called', [
            'user_id' => $request->user()->id,
            'role' => $request->user()->role
        ]);

        $payload = $request->validate([
            'template'   => 'nullable|string',
            'format'     => 'required|string|in:pdf,docx,xlsx,pptx',
            'filename'   => 'nullable|string|max:255',
            'filters'    => 'required|array',
            'filters.userId' => 'nullable|integer',
            'selections' => 'required|array',
            'options'    => 'sometimes|array',
        ]);

        // Get userId from filters
        $targetUserId = $payload['filters']['userId'] ?? null;

        if (!$targetUserId) {
            $targetUserId = $request->user()->id;
        }

        // Validate access
        $this->validateReportAccess($request->user(), $targetUserId);

        // Use only the target user's data
        // $userIds = [(int) $targetUserId];
        $userIds = $this->normalizeUserIds([$targetUserId]);

        $format   = strtolower($payload['format']);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $payload['filename'] ?? 'report') . ".{$format}";
        $disk     = 'uploads';
        $dir      = 'reports/' . now()->format('Y/m');

        Storage::disk($disk)->makeDirectory($dir);
        $path = "{$dir}/{$filename}";

        // Store a summary with target user info
        $summary = [
            'format'          => $format,
            'selections'      => $payload['selections'],
            'filters'         => $payload['filters'],
            'generatedAt'     => now()->toDateTimeString(),
            'generatedBy'     => $request->user()->id,
            'targetUserId'    => $targetUserId,
        ];
        Storage::disk($disk)->put($path, json_encode($summary, JSON_PRETTY_PRINT));

        Log::info('Report generated', [
            'format' => $format,
            'filename' => $filename,
            'generated_by' => $request->user()->id,
            'target_user_id' => $targetUserId
        ]);

        return response()->json([
            'disk'        => $disk,
            'path'        => $path,
            'downloadUrl' => Storage::disk($disk)->url($path),
        ]);
    }

    /* --------------------------- Bulk export (accessible users scoped) --------------------------- */

    public function bulkExport(Request $request)
    {
        $request->user() ?? abort(401, 'Unauthenticated');

        Log::info('Bulk export called', [
            'user_id' => $request->user()->id,
            'role' => $request->user()->role
        ]);

        // Get user IDs based on dashboard scope
        // $userIds = $this->resolveDashboardUserIds($request);
        $userIds = $this->normalizeUserIds(
            $this->resolveDashboardUserIds($request)
        );


        $payload = $request->validate([
            'type'    => 'required|string|in:all-users,all-papers,by-collection',
            'format'  => 'required|string|in:xlsx,csv,pdf',
            'filters' => 'array',
        ]);

        $disk = 'public';
        $dir  = 'reports/' . now()->format('Y/m');
        Storage::disk($disk)->makeDirectory($dir);
        $path = "{$dir}/bulk_export_" . now()->timestamp . ".txt";

        $payloadToSave = $payload;
        $payloadToSave['effective_scope'] = 'dashboard-scope';
        $payloadToSave['user_id'] = $request->user()->id;
        $payloadToSave['user_ids'] = $userIds;
        $payloadToSave['scope'] = $request->query('scope', 'self');

        Storage::disk($disk)->put($path, json_encode($payloadToSave, JSON_PRETTY_PRINT));

        Log::info('Bulk export generated', [
            'type' => $payload['type'],
            'format' => $payload['format'],
            'user_count' => count($userIds)
        ]);

        return response()->json([
            'disk'        => $disk,
            'path'        => $path,
            'downloadUrl' => Storage::disk($disk)->url($path),
        ]);
    }
}
