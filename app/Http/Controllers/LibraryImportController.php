<?php

namespace App\Http\Controllers;

use App\Models\Paper;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Csv\Reader;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LibraryImportController extends Controller
{
    private const MAX_UPLOAD_SIZE_KB = 51200; // 50 MB
    private const MAX_CSV_SIZE_KB    = 20480; // 20 MB
    private const REMOTE_TIMEOUT_SEC = 60;
    private const REMOTE_MAX_BYTES   = 52428800; // 50 MB

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
     * FormData: files[] (optional), csv (optional), meta(JSON) => { sources:{ urls:[], bibtex:string } }
     * JSON:     { sources:{ urls:[], bibtex:string } }
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

        $user = $request->user() ?? abort(401, 'Unauthenticated');

        if (method_exists($user, 'planIsActive') && !$user->planIsActive()) {
            return response()->json([
                'message' => 'Your subscription/plan has expired. Please renew to continue importing.',
            ], Response::HTTP_FORBIDDEN);
        }

        $remaining = method_exists($user, 'remainingPaperQuota')
            ? $user->remainingPaperQuota()
            : PHP_INT_MAX;

        // 1) Uploaded files
        foreach ($files as $file) {
            if ($remaining <= 0) {
                $skipped[] = [
                    'name'   => $file->getClientOriginalName(),
                    'reason' => 'Upload quota exceeded for your plan',
                ];
                continue;
            }

            try {
                $paper = $this->storeUploadedPaper($request, $file);
                $created[] = $paper->fresh('files');
                $remaining--;
            } catch (\Throwable $e) {
                Log::error('Library file upload import failed', [
                    'user_id' => $user->id ?? null,
                    'name'    => $file->getClientOriginalName(),
                    'error'   => $e->getMessage(),
                ]);

                $errors[] = [
                    'name'  => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        // 2) URL imports
        foreach ($sources['urls'] as $url) {
            if ($remaining <= 0) {
                $skipped[] = [
                    'url'    => $url,
                    'reason' => 'Upload quota exceeded for your plan',
                ];
                continue;
            }

            try {
                $paper = $this->importFromUrl($request, $url);

                if ($paper) {
                    $created[] = $paper->fresh('files');
                    $remaining--;
                } else {
                    $skipped[] = [
                        'url'    => $url,
                        'reason' => 'Unsupported or empty',
                    ];
                }
            } catch (\Throwable $e) {
                Log::error('Library URL import failed', [
                    'user_id' => $user->id ?? null,
                    'url'     => $url,
                    'error'   => $e->getMessage(),
                ]);

                $errors[] = [
                    'url'   => $url,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // 3) BibTeX / RIS
        if (!empty($sources['bibtex'])) {
            try {
                $entries = $this->parseBibOrRis($sources['bibtex']);

                foreach ($entries as $entry) {
                    if ($remaining <= 0) {
                        $skipped[] = [
                            'entry'  => $entry['title'] ?? '(unknown)',
                            'reason' => 'Upload quota exceeded for your plan',
                        ];
                        continue;
                    }

                    try {
                        $paper = $this->createPaperFromEntry($request, $entry);
                        $created[] = $paper->fresh('files');
                        $remaining--;
                    } catch (\Throwable $e) {
                        Log::error('Library BibTeX/RIS import entry failed', [
                            'user_id' => $user->id ?? null,
                            'entry'   => $entry['title'] ?? '(unknown)',
                            'error'   => $e->getMessage(),
                        ]);

                        $errors[] = [
                            'entry' => $entry['title'] ?? '(unknown)',
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = [
                    'bibtex' => 'parse',
                    'error'  => $e->getMessage(),
                ];
            }
        }

        // 4) CSV
        if ($csvFile instanceof UploadedFile) {
            try {
                [$c2, $s2, $e2] = $this->importFromCsvWithQuota($request, $csvFile, $remaining);
                array_push($created, ...$c2);
                array_push($skipped, ...$s2);
                array_push($errors,  ...$e2);
            } catch (\Throwable $e) {
                Log::error('Library CSV import failed', [
                    'user_id' => $user->id ?? null,
                    'csv'     => $csvFile->getClientOriginalName(),
                    'error'   => $e->getMessage(),
                ]);

                $errors[] = [
                    'csv'   => $csvFile->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
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
     * @return array{0: array<int, UploadedFile>, 1: UploadedFile|null, 2: array{urls: array<int, string>, bibtex: string}}
     */
    private function normalizePayload(Request $request): array
    {
        $files   = [];
        $csvFile = null;
        $sources = ['urls' => [], 'bibtex' => ''];

        if (
            $request->isMethod('post') &&
            Str::startsWith((string) $request->header('Content-Type', ''), 'multipart/form-data')
        ) {
            if ($request->hasFile('files')) {
                $request->validate([
                    'files'   => ['array', 'min:1'],
                    'files.*' => ['file', 'max:' . self::MAX_UPLOAD_SIZE_KB],
                ]);

                $files = $request->file('files', []);
            }

            if ($request->hasFile('csv')) {
                $request->validate([
                    'csv' => ['file', 'mimes:csv,txt', 'max:' . self::MAX_CSV_SIZE_KB],
                ]);

                $csvFile = $request->file('csv');
            }

            $meta = [];
            if ($request->filled('meta')) {
                $meta = json_decode((string) $request->input('meta'), true) ?: [];
            }

            $directUrlsRaw = $request->input('urls', []);
            $directUrls    = [];

            if (is_array($directUrlsRaw)) {
                $directUrls = $directUrlsRaw;
            } elseif (is_string($directUrlsRaw)) {
                $decoded = json_decode($directUrlsRaw, true);
                if (is_array($decoded)) {
                    $directUrls = $decoded;
                } else {
                    $directUrls = preg_split('/\r\n|\r|\n/', $directUrlsRaw) ?: [];
                }
            }

            $metaUrls = $meta['sources']['urls'] ?? [];

            $allUrls = array_merge(
                is_array($directUrls) ? $directUrls : [],
                is_array($metaUrls) ? $metaUrls : []
            );

            $sources['urls'] = array_values(array_unique(
                array_filter(
                    array_map(fn ($u) => is_string($u) ? trim($u) : '', $allUrls),
                    fn ($u) => $u !== ''
                )
            ));

            $sources['bibtex'] = is_string($meta['sources']['bibtex'] ?? null)
                ? trim($meta['sources']['bibtex'])
                : '';
        } else {
            $payload = $request->json()->all();

            $urls = $payload['sources']['urls'] ?? [];
            $bib  = $payload['sources']['bibtex'] ?? '';

            $sources['urls'] = array_values(array_unique(
                array_filter(
                    array_map(fn ($u) => is_string($u) ? trim($u) : '', is_array($urls) ? $urls : []),
                    fn ($u) => $u !== ''
                )
            ));

            $sources['bibtex'] = is_string($bib) ? trim($bib) : '';
        }

        return [$files, $csvFile, $sources];
    }

    /* ---------------- Storage Helpers ---------------- */

    private function uploadDisk(): string
    {
        return config('filesystems.default_upload_disk', env('AZURE_STORAGE_DISK', 'azure'));
    }

    private function librarySubdir(): string
    {
        return 'library/' . now()->format('Y/m');
    }

    private function buildStoragePath(string $originalName): string
    {
        $safeName = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', trim($originalName));
        $safeName = ltrim((string) $safeName, '.');

        if ($safeName === '') {
            $safeName = 'file_' . now()->timestamp;
        }

        return $this->librarySubdir() . '/' . Str::uuid()->toString() . '_' . $safeName;
    }

    private function normalizeMime(?string $mime, string $originalName): string
    {
        $mime = strtolower(trim((string) $mime));

        if ($mime !== '') {
            $mime = explode(';', $mime)[0];
        }

        if ($mime !== '') {
            return $mime;
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf'  => 'application/pdf',
            'txt'  => 'text/plain',
            'csv'  => 'text/csv',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'zip'  => 'application/zip',
            default => 'application/octet-stream',
        };
    }

    private function extensionFromMime(?string $mime): ?string
    {
        $mime = $this->normalizeMime($mime, 'file.bin');

        $map = [
            'application/pdf'                                                        => 'pdf',
            'text/plain'                                                             => 'txt',
            'text/csv'                                                               => 'csv',
            'application/csv'                                                        => 'csv',
            'application/zip'                                                        => 'zip',
            'application/msword'                                                     => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];

        return $map[$mime] ?? null;
    }

    private function ensureRemoteFileWithinLimit($response, ?string $label = null): void
    {
        $contentLength = (int) $response->header('Content-Length', 0);

        if ($contentLength > 0 && $contentLength > self::REMOTE_MAX_BYTES) {
            throw new \RuntimeException(($label ? "{$label}: " : '') . 'Remote file exceeds 50 MB limit.');
        }
    }

    private function fetchRemoteFile(string $url, array $accept = ['application/pdf', 'application/octet-stream', '*/*']): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $response = Http::timeout(self::REMOTE_TIMEOUT_SEC)
            ->withHeaders([
                'User-Agent' => 'KGF-LibraryBot/1.0',
                'Accept'     => implode(',', $accept),
            ])
            ->get($url);

        if (!$response->ok()) {
            throw new \RuntimeException("HTTP {$response->status()} for {$url}");
        }

        $this->ensureRemoteFileWithinLimit($response, $url);

        $bytes = $response->body();
        if ($bytes === '' || $bytes === null) {
            return null;
        }

        if (strlen($bytes) > self::REMOTE_MAX_BYTES) {
            throw new \RuntimeException("Remote file exceeds 50 MB limit for {$url}");
        }

        $originalName = basename(parse_url($url, PHP_URL_PATH) ?: 'download');
        $mime = $this->normalizeMime($response->header('Content-Type', 'application/octet-stream'), $originalName);

        if (!Str::contains($originalName, '.')) {
            $ext = $this->extensionFromMime($mime) ?? 'bin';
            $originalName .= '.' . $ext;
        }

        return [
            'bytes'         => $bytes,
            'mime'          => $mime,
            'original_name' => $originalName,
            'size_bytes'    => strlen($bytes),
            'checksum'      => hash('sha256', $bytes),
        ];
    }

    private function uploadBytesToStorage(string $path, string $bytes): void
    {
        Storage::disk($this->uploadDisk())->put($path, $bytes);
    }

    private function uploadStreamToStorage(string $path, $stream): void
    {
        Storage::disk($this->uploadDisk())->writeStream($path, $stream);
    }

    /* ---------------- Files ---------------- */

    private function storeUploadedPaper(Request $request, UploadedFile $file): Paper
    {
        $disk         = $this->uploadDisk();
        $originalName = $file->getClientOriginalName();
        $mime         = $this->normalizeMime($file->getClientMimeType(), $originalName);
        $sizeBytes    = $file->getSize();
        $checksum     = hash_file('sha256', $file->getRealPath());
        $path         = $this->buildStoragePath($originalName);

        $stream = fopen($file->getRealPath(), 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open uploaded file stream.');
        }

        $uploaded = false;

        try {
            $this->uploadStreamToStorage($path, $stream);
            $uploaded = true;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            return DB::transaction(function () use ($request, $disk, $path, $originalName, $mime, $sizeBytes, $checksum) {
                $paper = Paper::create([
                    'title'      => $this->titleFromFilename($originalName),
                    'created_by' => $request->user()->id ?? null,
                    'source'     => 'upload',
                ]);

                $paper->files()->create([
                    'disk'          => $disk,
                    'path'          => $path,
                    'original_name' => $originalName,
                    'mime'          => $mime,
                    'size_bytes'    => $sizeBytes,
                    'checksum'      => $checksum,
                    'uploaded_by'   => $request->user()->id ?? null,
                ]);

                return $paper;
            }, 3);
        } catch (\Throwable $e) {
            if ($uploaded) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable $cleanupEx) {
                    Log::warning('Cleanup failed after uploaded paper DB transaction failure', [
                        'disk'  => $disk,
                        'path'  => $path,
                        'error' => $cleanupEx->getMessage(),
                    ]);
                }
            }

            throw $e;
        }
    }

    /* ---------------- URLs ---------------- */

    private function importFromUrl(Request $request, string $url): ?Paper
    {
        $remote = $this->fetchRemoteFile($url);
        if (!$remote) {
            return null;
        }

        $disk = $this->uploadDisk();
        $path = $this->buildStoragePath($remote['original_name']);

        $this->uploadBytesToStorage($path, $remote['bytes']);

        try {
            return DB::transaction(function () use ($request, $url, $disk, $path, $remote) {
                $paper = Paper::create([
                    'title'      => $this->titleFromFilename($remote['original_name']),
                    'doi'        => null,
                    'year'       => null,
                    'created_by' => $request->user()->id ?? null,
                    'source'     => 'url',
                    'url'        => $url,
                ]);

                $paper->files()->create([
                    'disk'          => $disk,
                    'path'          => $path,
                    'original_name' => $remote['original_name'],
                    'mime'          => $remote['mime'],
                    'size_bytes'    => $remote['size_bytes'],
                    'checksum'      => $remote['checksum'],
                    'uploaded_by'   => $request->user()->id ?? null,
                ]);

                return $paper;
            }, 3);
        } catch (\Throwable $e) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable $cleanupEx) {
                Log::warning('Cleanup failed after URL import DB transaction failure', [
                    'url'   => $url,
                    'disk'  => $disk,
                    'path'  => $path,
                    'error' => $cleanupEx->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    /* ---------------- BibTeX / RIS ---------------- */

    private function parseBibOrRis(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        if (
            Str::contains(Str::lower($text), '@article') ||
            Str::contains(Str::lower($text), '@inproceedings') ||
            Str::contains(Str::lower($text), '@book') ||
            Str::contains(Str::lower($text), '@misc')
        ) {
            return $this->parseBibtex($text);
        }

        return $this->parseRis($text);
    }

    private function parseBibtex(string $bib): array
    {
        $entries = [];

        foreach (preg_split('/\n@/i', "\n" . $bib) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $title   = $this->matchField($chunk, 'title');
            $author  = $this->matchField($chunk, 'author');
            $year    = $this->matchField($chunk, 'year');
            $doi     = $this->matchField($chunk, 'doi');
            $url     = $this->matchField($chunk, 'url');
            $pdfUrl  = $this->matchField($chunk, 'pdf');

            $entries[] = [
                'title'   => $this->cleanBraces($title),
                'authors' => $this->cleanBraces($author),
                'year'    => $this->cleanBraces($year),
                'doi'     => $this->cleanBraces($doi),
                'url'     => $this->cleanBraces($url),
                'pdf_url' => $this->cleanBraces($pdfUrl),
            ];
        }

        return $entries;
    }

    private function parseRis(string $ris): array
    {
        $entries = [];
        $current = [];

        foreach (preg_split('/\r\n|\r|\n/', $ris) as $line) {
            if (preg_match('/^TY\s*-\s*/', $line)) {
                $current = [];
            }

            if (preg_match('/^TI\s*-\s*(.*)$/', $line, $m)) {
                $current['title'] = trim($m[1]);
            }

            if (preg_match('/^AU\s*-\s*(.*)$/', $line, $m)) {
                $current['authors'] = trim(($current['authors'] ?? '') . '; ' . $m[1], '; ');
            }

            if (preg_match('/^PY\s*-\s*(\d{4})/', $line, $m)) {
                $current['year'] = $m[1];
            }

            if (preg_match('/^DO\s*-\s*(.*)$/', $line, $m)) {
                $current['doi'] = trim($m[1]);
            }

            if (preg_match('/^UR\s*-\s*(.*)$/', $line, $m)) {
                $current['url'] = trim($m[1]);
            }

            if (preg_match('/^ER\s*-\s*/', $line)) {
                $entries[] = $current;
                $current = [];
            }
        }

        if (!empty($current)) {
            $entries[] = $current;
        }

        return $entries;
    }

    private function matchField(string $chunk, string $field): string
    {
        if (preg_match('/' . preg_quote($field, '/') . '\s*=\s*[{"]([^}"]+)[}"]/i', $chunk, $m)) {
            return trim($m[1]);
        }

        return '';
    }

    private function cleanBraces(?string $s): string
    {
        return trim((string) preg_replace('/[{}"]/', '', (string) $s));
    }

    private function createPaperFromEntry(Request $request, array $entry): Paper
    {
        $pdfFile = null;
        $disk    = $this->uploadDisk();
        $path    = null;

        if (!empty($entry['pdf_url'])) {
            try {
                $pdfFile = $this->fetchRemoteFile((string) $entry['pdf_url'], [
                    'application/pdf',
                    'application/octet-stream',
                    '*/*',
                ]);

                if ($pdfFile) {
                    $path = $this->buildStoragePath($pdfFile['original_name']);
                    $this->uploadBytesToStorage($path, $pdfFile['bytes']);
                }
            } catch (\Throwable $e) {
                Log::warning('BibTeX/RIS PDF attachment fetch failed; paper will still be created', [
                    'pdf_url' => $entry['pdf_url'],
                    'error'   => $e->getMessage(),
                ]);

                $pdfFile = null;
                $path = null;
            }
        }

        try {
            return DB::transaction(function () use ($request, $entry, $pdfFile, $disk, $path) {
                $paper = Paper::create([
                    'title'      => !empty($entry['title']) ? $entry['title'] : 'Untitled',
                    'authors'    => $entry['authors'] ?? null,
                    'year'       => $entry['year'] ?? null,
                    'doi'        => $entry['doi'] ?? null,
                    'url'        => $entry['url'] ?? null,
                    'created_by' => $request->user()->id ?? null,
                    'source'     => 'bibtex/ris',
                ]);

                if ($pdfFile && $path) {
                    $paper->files()->create([
                        'disk'          => $disk,
                        'path'          => $path,
                        'original_name' => $pdfFile['original_name'],
                        'mime'          => $pdfFile['mime'],
                        'size_bytes'    => $pdfFile['size_bytes'],
                        'checksum'      => $pdfFile['checksum'],
                        'uploaded_by'   => $request->user()->id ?? null,
                    ]);
                }

                return $paper;
            }, 3);
        } catch (\Throwable $e) {
            if ($path) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable $cleanupEx) {
                    Log::warning('Cleanup failed after BibTeX/RIS transaction failure', [
                        'disk'  => $disk,
                        'path'  => $path,
                        'error' => $cleanupEx->getMessage(),
                    ]);
                }
            }

            throw $e;
        }
    }

    /* ---------------- CSV ---------------- */

    /**
     * Kept for backward compatibility.
     * Returns [$created, $skipped, $errors]
     */
    private function importFromCsv(Request $request, UploadedFile $csvFile): array
    {
        $reader = Reader::createFromPath($csvFile->getRealPath(), 'r');
        $reader->setHeaderOffset(0);

        $created = [];
        $skipped = [];
        $errors  = [];

        foreach ($reader->getRecords() as $row) {
            try {
                $title = trim((string) ($row['title'] ?? ''));

                if ($title === '') {
                    $skipped[] = [
                        'row'    => $row,
                        'reason' => 'Missing title',
                    ];
                    continue;
                }

                $paper = DB::transaction(function () use ($request, $row, $title) {
                    return Paper::create([
                        'title'      => $title,
                        'authors'    => $row['authors'] ?? null,
                        'year'       => $row['year'] ?? null,
                        'doi'        => $row['doi'] ?? null,
                        'url'        => $row['url'] ?? null,
                        'created_by' => $request->user()->id ?? null,
                        'source'     => 'csv',
                    ]);
                }, 3);

                if (!empty($row['pdf_url'])) {
                    try {
                        $pdfFile = $this->fetchRemoteFile((string) $row['pdf_url']);
                        if ($pdfFile) {
                            $disk = $this->uploadDisk();
                            $path = $this->buildStoragePath($pdfFile['original_name']);

                            $this->uploadBytesToStorage($path, $pdfFile['bytes']);

                            try {
                                DB::transaction(function () use ($paper, $request, $disk, $path, $pdfFile) {
                                    $paper->files()->create([
                                        'disk'          => $disk,
                                        'path'          => $path,
                                        'original_name' => $pdfFile['original_name'],
                                        'mime'          => $pdfFile['mime'],
                                        'size_bytes'    => $pdfFile['size_bytes'],
                                        'checksum'      => $pdfFile['checksum'],
                                        'uploaded_by'   => $request->user()->id ?? null,
                                    ]);
                                }, 3);
                            } catch (\Throwable $e) {
                                try {
                                    Storage::disk($disk)->delete($path);
                                } catch (\Throwable $cleanupEx) {
                                    Log::warning('Cleanup failed after CSV attachment DB failure', [
                                        'disk'  => $disk,
                                        'path'  => $path,
                                        'error' => $cleanupEx->getMessage(),
                                    ]);
                                }

                                throw $e;
                            }
                        }
                    } catch (\Throwable $e) {
                        $errors[] = [
                            'row'   => $row,
                            'error' => 'pdf_url fetch failed: ' . $e->getMessage(),
                        ];
                    }
                }

                $created[] = $paper->fresh('files');
            } catch (\Throwable $e) {
                $errors[] = [
                    'row'   => $row,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return [$created, $skipped, $errors];
    }

    /**
     * Returns [$created, $skipped, $errors]
     */
    private function importFromCsvWithQuota(Request $request, UploadedFile $csvFile, int &$remaining): array
    {
        $created = [];
        $skipped = [];
        $errors  = [];

        $handle = fopen($csvFile->getRealPath(), 'r');
        if ($handle === false) {
            return [[], [], [['error' => 'Unable to open CSV file']]];
        }

        try {
            $headers = fgetcsv($handle);

            if (!$headers || !is_array($headers)) {
                return [[], [], [['error' => 'Invalid or empty CSV header']]];
            }

            $headers = array_map(
                fn ($h) => strtolower(trim((string) $h)),
                $headers
            );

            $rowNumber = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;

                if ($remaining <= 0) {
                    $skipped[] = [
                        'row'    => $rowNumber,
                        'reason' => 'Upload quota exceeded for your plan',
                    ];
                    continue;
                }

                if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                    continue;
                }

                if (count($headers) !== count($row)) {
                    $errors[] = [
                        'row'   => $rowNumber,
                        'error' => 'Column count mismatch',
                    ];
                    continue;
                }

                $rowData = array_combine($headers, $row);
                if ($rowData === false) {
                    $errors[] = [
                        'row'   => $rowNumber,
                        'error' => 'Unable to read CSV row',
                    ];
                    continue;
                }

                try {
                    $data = [
                        'created_by' => $request->user()->id ?? null,
                        'source'     => 'csv',
                    ];

                    foreach (self::CSV_FIELD_MAP as $csvKey => $dbColumn) {
                        if (isset($rowData[$csvKey]) && trim((string) $rowData[$csvKey]) !== '') {
                            $data[$dbColumn] = trim((string) $rowData[$csvKey]);
                        }
                    }

                    if (empty($data['title'])) {
                        $skipped[] = [
                            'row'    => $rowNumber,
                            'reason' => 'Missing title',
                        ];
                        continue;
                    }

                    $pdfFile = null;
                    $disk    = $this->uploadDisk();
                    $path    = null;

                    if (!empty($rowData['pdf_url'])) {
                        try {
                            $pdfFile = $this->fetchRemoteFile(trim((string) $rowData['pdf_url']));
                            if ($pdfFile) {
                                $path = $this->buildStoragePath($pdfFile['original_name']);
                                $this->uploadBytesToStorage($path, $pdfFile['bytes']);
                            }
                        } catch (\Throwable $e) {
                            $errors[] = [
                                'row'   => $rowNumber,
                                'error' => 'pdf_url fetch failed: ' . $e->getMessage(),
                            ];
                        }
                    }

                    try {
                        $paper = DB::transaction(function () use ($data, $request, $pdfFile, $disk, $path) {
                            $paper = Paper::create($data);

                            if ($pdfFile && $path) {
                                $paper->files()->create([
                                    'disk'          => $disk,
                                    'path'          => $path,
                                    'original_name' => $pdfFile['original_name'],
                                    'mime'          => $pdfFile['mime'],
                                    'size_bytes'    => $pdfFile['size_bytes'],
                                    'checksum'      => $pdfFile['checksum'],
                                    'uploaded_by'   => $request->user()->id ?? null,
                                ]);
                            }

                            return $paper;
                        }, 3);
                    } catch (\Throwable $e) {
                        if ($path) {
                            try {
                                Storage::disk($disk)->delete($path);
                            } catch (\Throwable $cleanupEx) {
                                Log::warning('Cleanup failed after CSV row transaction failure', [
                                    'disk'  => $disk,
                                    'path'  => $path,
                                    'error' => $cleanupEx->getMessage(),
                                ]);
                            }
                        }

                        throw $e;
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
        } finally {
            fclose($handle);
        }

        return [$created, $skipped, $errors];
    }

    /* ---------------- Helpers ---------------- */

    private function titleFromFilename(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = preg_replace('/[_\-]+/', ' ', $base);
        $base = trim((string) $base);

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

            if ($handle === false) {
                throw new \RuntimeException('Unable to open output stream.');
            }

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