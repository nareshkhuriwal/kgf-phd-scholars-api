<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use App\Models\Paper;
use League\Csv\Reader; // composer require league/csv
use Symfony\Component\HttpFoundation\StreamedResponse;

class LibraryImportController extends Controller
{

    private const CSV_FIELD_MAP = [
        'paper_code' => 'paper_code',
        'doi'        => 'doi',
        'authors'    => 'authors',
        'year'       => 'year',
        'title'      => 'title',
        'journal'    => 'journal',
        'issn_isbn'  => 'issn_isbn',
        'publisher'  => 'publisher',
        'place'      => 'place',
        'area'       => 'area',
        'volume'     => 'volume',
        'issue'      => 'issue',
        'page_no'    => 'page_no',
        'category'   => 'category',
    ];

    /**
     * POST /api/library/import
     * FormData:  files[] (PDFs etc), csv (optional), meta(JSON) => { sources:{ urls:[], bibtex:string } }
     * JSON:      { sources:{ urls:[], bibtex:string } }  // files ignored unless base64 (not supported here)
     *
     * Returns: { message, created: Paper[], skipped:[], errors:[] }
     */
    public function import(Request $request)
    {
        [$files, $csvFile, $sources] = $this->normalizePayload($request);

        if (empty($files) && empty($sources['urls']) && empty($sources['bibtex']) && !$csvFile) {
            return response()->json([
                'message' => 'No import sources supplied',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $created = [];
        $skipped = [];
        $errors  = [];

        // --- plan / quota from User model helpers ---
        $user = $request->user() ?? abort(401, 'Unauthenticated');

        // Optional: disallow imports if plan expired
        if (method_exists($user, 'planIsActive') && ! $user->planIsActive()) {
            return response()->json([
                'message' => 'Your subscription/plan has expired. Please renew to continue importing.',
            ], Response::HTTP_FORBIDDEN);
        }

        // remaining slots (model returns PHP_INT_MAX for unlimited)
        $remaining = method_exists($user, 'remainingPaperQuota') ? $user->remainingPaperQuota() : PHP_INT_MAX;

        // 1) Handle uploaded files
        foreach ($files as $file) {
            if ($remaining <= 0) {
                $skipped[] = [
                    'name' => $file->getClientOriginalName(),
                    'reason' => 'Upload quota exceeded for your plan',
                ];
                continue;
            }

            try {
                $paper = $this->storeUploadedPaper($request, $file);
                $created[] = $paper->fresh('files');
                $remaining--;
            } catch (\Throwable $e) {
                $errors[] = ['name' => $file->getClientOriginalName(), 'error' => $e->getMessage()];
            }
        }

        // 2) Handle URLs (download, then attach)
        foreach ($sources['urls'] as $url) {
            if ($remaining <= 0) {
                $skipped[] = ['url' => $url, 'reason' => 'Upload quota exceeded for your plan'];
                continue;
            }

            try {
                $paper = $this->importFromUrl($request, $url);
                if ($paper) {
                    $created[] = $paper->fresh('files');
                    $remaining--;
                } else {
                    $skipped[] = ['url' => $url, 'reason' => 'Unsupported or empty'];
                }
            } catch (\Throwable $e) {
                $errors[] = ['url' => $url, 'error' => $e->getMessage()];
            }
        }

        // 3) Handle pasted BibTeX/RIS
        if (!empty($sources['bibtex'])) {
            try {
                $entries = $this->parseBibOrRis($sources['bibtex']);
                foreach ($entries as $entry) {
                    if ($remaining <= 0) {
                        $skipped[] = ['entry' => $entry['title'] ?? '(unknown)', 'reason' => 'Upload quota exceeded for your plan'];
                        continue;
                    }

                    try {
                        $paper = $this->createPaperFromEntry($request, $entry);
                        $created[] = $paper->fresh('files');
                        $remaining--;
                    } catch (\Throwable $e) {
                        $errors[] = ['entry' => $entry['title'] ?? '(unknown)', 'error' => $e->getMessage()];
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = ['bibtex' => 'parse', 'error' => $e->getMessage()];
            }
        }

        // 4) Handle CSV (title,authors,year,doi,url,pdf_url,…)
        if ($csvFile instanceof UploadedFile) {
            try {
                [$c2, $s2, $e2] = $this->importFromCsvWithQuota($request, $csvFile, $remaining);
                array_push($created, ...$c2);
                array_push($skipped, ...$s2);
                array_push($errors,  ...$e2);
            } catch (\Throwable $e) {
                $errors[] = ['csv' => $csvFile->getClientOriginalName(), 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'message' => 'Import finished.',
            'created' => $created,
            'skipped' => $skipped,
            'errors'  => $errors,
        ], Response::HTTP_CREATED);
    }

    /* ---------------- Normalization ---------------- */

    /**
     * Normalize input from either multipart or JSON.
     * @return array [UploadedFile[], UploadedFile|null, ['urls'=>[], 'bibtex'=>string]]
     */
    private function normalizePayload(Request $request): array
    {
        $files   = [];
        $csvFile = null;
        $sources = ['urls' => [], 'bibtex' => ''];

        if ($request->isMethod('post') && Str::startsWith($request->header('Content-Type', ''), 'multipart/form-data')) {
            // Multipart: files[], csv, meta(JSON)
            if ($request->hasFile('files')) {
                $request->validate([
                    'files'   => ['array', 'min:1'],
                    'files.*' => ['file', 'max:51200'], // 50MB (KB)
                ]);
                $files = $request->file('files', []);
            }
            if ($request->hasFile('csv')) {
                $request->validate(['csv' => ['file', 'mimes:csv,txt', 'max:20480']]);
                $csvFile = $request->file('csv');
            }
            $meta = [];
            if ($request->filled('meta')) {
                $meta = json_decode((string)$request->input('meta'), true) ?: [];
            }
            $sources['urls']   = array_values(array_filter(array_map('trim', $meta['sources']['urls'] ?? [])));
            $sources['bibtex'] = trim($meta['sources']['bibtex'] ?? '');
        } else {
            // JSON body
            $payload = $request->json()->all();
            $urls    = $payload['sources']['urls']   ?? [];
            $bibtex  = $payload['sources']['bibtex'] ?? '';
            $sources['urls']   = array_values(array_filter(array_map('trim', is_array($urls) ? $urls : [])));
            $sources['bibtex'] = is_string($bibtex) ? trim($bibtex) : '';
        }

        return [$files, $csvFile, $sources];
    }

    /* ---------------- Files (Option-B) ---------------- */

    /**
     * Create Paper first, then create PaperFile (attached upload).
     */
    private function storeUploadedPaper(Request $request, UploadedFile $file): Paper
    {
        // Create the paper row first
        $title = $this->titleFromFilename($file->getClientOriginalName());
        $paper = Paper::create([
            'title'      => $title,
            'created_by' => $request->user()->id ?? null,
            'source'     => 'upload',
        ]);

        // Store the file on uploads disk
        $disk   = 'uploads';
        $subdir = now()->format('Y/m');
        $path   = $file->store("library/{$subdir}", $disk);

        // Attach it into paper_files
        $paper->files()->create([
            'disk'          => $disk,
            'path'          => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime'          => $file->getClientMimeType(),
            'size_bytes'    => $file->getSize(),
            'checksum'      => hash_file('sha256', $file->getRealPath()),
            'uploaded_by'   => $request->user()->id ?? null,
        ]);

        return $paper;
    }

    /* ---------------- URLs (Option-B) ---------------- */

    /**
     * Download from URL, create Paper, then create PaperFile.
     */
    private function importFromUrl(Request $request, string $url): ?Paper
    {
        $url = trim($url);
        if ($url === '') return null;

        $resp = Http::timeout(20)->withHeaders([
            'User-Agent' => 'KGF-LibraryBot/1.0',
            'Accept'     => 'application/pdf,application/octet-stream,*/*',
        ])->get($url);

        if (!$resp->ok()) {
            throw new \RuntimeException("HTTP {$resp->status()} for $url");
        }

        $bytes = $resp->body();
        if (!strlen($bytes)) return null;

        $mime   = $resp->header('Content-Type', 'application/octet-stream');
        $disk   = 'uploads';
        $subdir = now()->format('Y/m');
        $ext    = $this->extensionFromMime($mime) ?? 'bin';
        $name   = basename(parse_url($url, PHP_URL_PATH) ?: 'download');
        if (!Str::contains($name, '.')) $name .= ".{$ext}";

        $path = "library/{$subdir}/" . Str::random(10) . "_" . $name;
        Storage::disk($disk)->put($path, $bytes);

        // Create Paper first
        $paper = Paper::create([
            'title'      => $this->titleFromFilename($name),
            'doi'        => null,
            'year'       => null,
            'created_by' => $request->user()->id ?? null,
            'source'     => 'url',
            'url'        => $url,
        ]);

        // Attach PaperFile
        $paper->files()->create([
            'disk'          => $disk,
            'path'          => $path,
            'original_name' => $name,
            'mime'          => $mime,
            'size_bytes'    => strlen($bytes),
            'checksum'      => hash('sha256', $bytes),
            'uploaded_by'   => $request->user()->id ?? null,
        ]);

        return $paper;
    }

    private function extensionFromMime(?string $mime): ?string
    {
        $map = [
            'application/pdf' => 'pdf',
            'text/plain'      => 'txt',
            'text/csv'        => 'csv',
            'application/csv' => 'csv',
            'application/zip' => 'zip',
        ];
        return $map[$mime] ?? null;
    }

    /* ---------------- BibTeX / RIS (Option-B) ---------------- */

    private function parseBibOrRis(string $text): array
    {
        $text = trim($text);
        if ($text === '') return [];

        if (Str::contains(Str::lower($text), '@article') || Str::contains(Str::lower($text), '@inproceedings')) {
            return $this->parseBibtex($text);
        }
        return $this->parseRis($text);
    }

    private function parseBibtex(string $bib): array
    {
        $entries = [];
        foreach (preg_split('/\n@/i', "\n" . $bib) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') continue;
            $title   = $this->matchField($chunk, 'title');
            $author  = $this->matchField($chunk, 'author');
            $year    = $this->matchField($chunk, 'year');
            $doi     = $this->matchField($chunk, 'doi');
            $url     = $this->matchField($chunk, 'url');
            $pdf_url = $this->matchField($chunk, 'pdf');
            $entries[] = [
                'title'   => $this->cleanBraces($title),
                'authors' => $this->cleanBraces($author),
                'year'    => $this->cleanBraces($year),
                'doi'     => $this->cleanBraces($doi),
                'url'     => $this->cleanBraces($url),
                'pdf_url' => $this->cleanBraces($pdf_url),
            ];
        }
        return $entries;
    }

    private function parseRis(string $ris): array
    {
        $entries = [];
        $current = [];
        foreach (preg_split('/\r\n|\r|\n/', $ris) as $line) {
            if (preg_match('/^TY\s*-\s*/', $line)) $current = [];
            if (preg_match('/^TI\s*-\s*(.*)$/', $line, $m)) $current['title'] = trim($m[1]);
            if (preg_match('/^AU\s*-\s*(.*)$/', $line, $m)) {
                $current['authors'] = trim(($current['authors'] ?? '') . '; ' . $m[1], '; ');
            }
            if (preg_match('/^PY\s*-\s*(\d{4})/', $line, $m)) $current['year'] = $m[1];
            if (preg_match('/^DO\s*-\s*(.*)$/', $line, $m)) $current['doi'] = trim($m[1]);
            if (preg_match('/^UR\s*-\s*(.*)$/', $line, $m)) $current['url'] = trim($m[1]);
            if (preg_match('/^ER\s*-\s*/', $line)) {
                $entries[] = $current;
                $current = [];
            }
        }
        if (!empty($current)) $entries[] = $current;
        return $entries;
    }

    private function matchField(string $chunk, string $field): string
    {
        if (preg_match('/' . $field . '\s*=\s*[{"]([^}"]+)[}"]/i', $chunk, $m)) return trim($m[1]);
        return '';
    }

    private function cleanBraces(?string $s): string
    {
        return trim(preg_replace('/[{}"]/', '', (string)$s));
    }

    /**
     * Create Paper from Bib/RIS entry, then optionally fetch/attach PDF as PaperFile.
     */
    private function createPaperFromEntry(Request $request, array $e): Paper
    {
        // 1) Always create the paper row first
        $paper = Paper::create([
            'title'      => $e['title'] ?: 'Untitled',
            'authors'    => $e['authors'] ?? null,
            'year'       => $e['year'] ?? null,
            'doi'        => $e['doi'] ?? null,
            'url'        => $e['url'] ?? null,
            'created_by' => $request->user()->id ?? null,
            'source'     => 'bibtex/ris',
        ]);

        // 2) If pdf_url present, try to fetch and attach
        if (!empty($e['pdf_url'])) {
            try {
                $resp = Http::timeout(20)->get($e['pdf_url']);
                if ($resp->ok() && strlen($resp->body())) {
                    $bytes  = $resp->body();
                    $mime   = $resp->header('Content-Type', 'application/pdf');
                    $disk   = 'uploads';
                    $subdir = now()->format('Y/m');
                    $orig   = basename(parse_url($e['pdf_url'], PHP_URL_PATH) ?: 'paper.pdf');
                    $path   = "library/{$subdir}/" . Str::random(10) . "_" . $orig;

                    Storage::disk($disk)->put($path, $bytes);

                    $paper->files()->create([
                        'disk'          => $disk,
                        'path'          => $path,
                        'original_name' => $orig,
                        'mime'          => $mime,
                        'size_bytes'    => strlen($bytes),
                        'checksum'      => hash('sha256', $bytes),
                        'uploaded_by'   => $request->user()->id ?? null,
                    ]);
                }
            } catch (\Throwable $ex) {
                // swallow; record still created without file
            }
        }

        return $paper;
    }

    /* ---------------- CSV (Option-B) ---------------- */

    /**
     * Original CSV importer (kept for backward compatibility if used elsewhere).
     * Returns [$created, $skipped, $errors]
     */
    private function importFromCsv(Request $request, UploadedFile $csvFile): array
    {
        $reader = Reader::createFromPath($csvFile->getRealPath(), 'r');
        $reader->setHeaderOffset(0);
        $created = [];
        $skipped = [];
        $errors = [];

        foreach ($reader->getRecords() as $row) {
            try {
                $title = trim($row['title'] ?? '');
                if ($title === '') {
                    $skipped[] = ['row' => $row, 'reason' => 'Missing title'];
                    continue;
                }

                // 1) Create paper first
                $paper = Paper::create([
                    'title'      => $title,
                    'authors'    => $row['authors'] ?? null,
                    'year'       => $row['year'] ?? null,
                    'doi'        => $row['doi'] ?? null,
                    'url'        => $row['url'] ?? null,
                    'created_by' => $request->user()->id ?? null,
                    'source'     => 'csv',
                ]);

                // 2) Fetch and attach pdf_url if present
                if (!empty($row['pdf_url'])) {
                    try {
                        $resp = Http::timeout(20)->get($row['pdf_url']);
                        if ($resp->ok() && strlen($resp->body())) {
                            $bytes  = $resp->body();
                            $mime   = $resp->header('Content-Type', 'application/pdf');
                            $disk   = 'uploads';
                            $subdir = now()->format('Y/m');
                            $orig   = basename(parse_url($row['pdf_url'], PHP_URL_PATH) ?: 'paper.pdf');
                            $path   = "library/{$subdir}/" . Str::random(10) . "_" . $orig;

                            Storage::disk($disk)->put($path, $bytes);

                            $paper->files()->create([
                                'disk'          => $disk,
                                'path'          => $path,
                                'original_name' => $orig,
                                'mime'          => $mime,
                                'size_bytes'    => strlen($bytes),
                                'checksum'      => hash('sha256', $bytes),
                                'uploaded_by'   => $request->user()->id ?? null,
                            ]);
                        }
                    } catch (\Throwable $e) {
                        $errors[] = ['row' => $row, 'error' => 'pdf_url fetch failed: ' . $e->getMessage()];
                    }
                }

                $created[] = $paper->fresh('files');
            } catch (\Throwable $e) {
                $errors[] = ['row' => $row, 'error' => $e->getMessage()];
            }
        }
        return [$created, $skipped, $errors];
    }

/**
 * CSV importer that respects remaining quota.
 * Uses native PHP (fgetcsv) – no external libraries.
 * Returns [$created, $skipped, $errors]
 */
private function importFromCsvWithQuota(Request $request, UploadedFile $csvFile, int &$remaining): array
{
    $created = [];
    $skipped = [];
    $errors  = [];

    if (($handle = fopen($csvFile->getRealPath(), 'r')) === false) {
        return [[], [], [['error' => 'Unable to open CSV file']]];
    }

    // Read header row
    $headers = fgetcsv($handle);
    if (!$headers || !is_array($headers)) {
        fclose($handle);
        return [[], [], [['error' => 'Invalid or empty CSV header']]];
    }

    // Normalize headers (trim + lowercase)
    $headers = array_map(
        fn ($h) => strtolower(trim((string)$h)),
        $headers
    );

    $rowNumber = 1; // header row

    while (($row = fgetcsv($handle)) !== false) {
        $rowNumber++;

        if ($remaining <= 0) {
            $skipped[] = ['row' => $rowNumber, 'reason' => 'Upload quota exceeded for your plan'];
            continue;
        }

        // Skip empty lines
        if (count(array_filter($row, fn ($v) => trim((string)$v) !== '')) === 0) {
            continue;
        }

        // Combine header → row
        if (count($headers) !== count($row)) {
            $errors[] = [
                'row'   => $rowNumber,
                'error' => 'Column count mismatch',
            ];
            continue;
        }

        $rowData = array_combine($headers, $row);

        try {
            // Build Paper data
            $data = [
                'created_by' => $request->user()->id ?? null,
                'source'     => 'csv',
            ];

            foreach (self::CSV_FIELD_MAP as $csvKey => $dbColumn) {
                if (isset($rowData[$csvKey]) && trim((string)$rowData[$csvKey]) !== '') {
                    $data[$dbColumn] = trim((string)$rowData[$csvKey]);
                }
            }

            if (empty($data['title'])) {
                $skipped[] = [
                    'row'    => $rowNumber,
                    'reason' => 'Missing title',
                ];
                continue;
            }

            // Create Paper
            $paper = Paper::create($data);

            // Optional PDF attachment
            if (!empty($rowData['pdf_url'])) {
                try {
                    $resp = Http::timeout(20)->get(trim($rowData['pdf_url']));
                    if ($resp->ok() && strlen($resp->body())) {
                        $bytes  = $resp->body();
                        $mime   = $resp->header('Content-Type', 'application/pdf');
                        $disk   = 'uploads';
                        $subdir = now()->format('Y/m');
                        $orig   = basename(parse_url($rowData['pdf_url'], PHP_URL_PATH) ?: 'paper.pdf');
                        $path   = "library/{$subdir}/" . Str::random(10) . "_" . $orig;

                        Storage::disk($disk)->put($path, $bytes);

                        $paper->files()->create([
                            'disk'          => $disk,
                            'path'          => $path,
                            'original_name' => $orig,
                            'mime'          => $mime,
                            'size_bytes'    => strlen($bytes),
                            'checksum'      => hash('sha256', $bytes),
                            'uploaded_by'   => $request->user()->id ?? null,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $errors[] = [
                        'row'   => $rowNumber,
                        'error' => 'pdf_url fetch failed: ' . $e->getMessage(),
                    ];
                }
            }

            $created[] = $paper->fresh('files');
            $remaining--;

        } catch (\Throwable $e) {
            $errors[] = [
                'row'   => $rowNumber,
                'error' => $e->getMessage(),
            ];
        }
    }

    fclose($handle);

    return [$created, $skipped, $errors];
}


    /* ---------------- Helpers ---------------- */

    private function titleFromFilename(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[_\-]+/', ' ', $base);
        $base = trim($base);
        return Str::title($base ?: 'Untitled');
    }



    public function csvTemplate(): StreamedResponse
    {
        $headers = [
            'paper_code',
            'doi',
            'authors',
            'year',
            'title',
            'journal',
            'issn_isbn',
            'publisher',
            'place',
            'area',
            'volume',
            'issue',
            'page_no',
            'category',
        ];

        $rows = [
            [
                'QEC-1995-001',
                '10.1109/SFCS.1995.492461',
                'Shor, Peter W.; Steane, Andrew',
                '1995',
                'Quantum Error Correction for Beginners',
                'Proceedings of IEEE Symposium on Foundations of Computer Science',
                '1063-6900',
                'IEEE',
                'Los Alamitos',
                'Quantum Computing / Error Correction',
                '36',
                '1',
                '56-65',
                'Conference Paper',
            ],
        ];

        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, 'papers_import_sample.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
