<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use App\Models\Paper;

class PaperExtractController extends Controller
{
    public function extract(Request $request, Paper $paper)
    {
        // 1️⃣ Locate PDF
        $file = $paper->files()->first();
        if (!$file) {
            return response()->json(['message' => 'PDF not found'], 404);
        }

        $pdfPath = $file->path; // storage path
        $pdfContent = Storage::get($pdfPath);

        // 2️⃣ Convert PDF → Text
        $text = $this->extractTextFromPdf($pdfContent);

        // 3️⃣ Call ChatGPT
        $response = $this->callChatGPT($text);

        // 4️⃣ Validate + normalize
        $data = $this->normalize($response);

        return response()->json($data);
    }

    private function extractTextFromPdf(string $binary): string
    {
        // Recommended: smalot/pdfparser
        // $parser = new \Smalot\PdfParser\Parser();
        $parser = null;
        $pdf = $parser->parseContent($binary);

        // Limit tokens (metadata always in first pages)
        return substr($pdf->getText(), 0, 15000);
    }

    private function callChatGPT(string $text): array
    {
        $payload = [
            'model' => 'gpt-4.1-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' =>
                        "You are an academic metadata extraction engine.
                         Return valid JSON only.
                         Follow the schema strictly."
                ],
                [
                    'role' => 'user',
                    'content' =>
                        "extract_paper_metadata\n\n" . $text
                ]
            ],
            'temperature' => 0,
        ];

        $res = Http::withToken(config('services.openai.key'))
            ->post('https://api.openai.com/v1/chat/completions', $payload)
            ->throw()
            ->json();

        return json_decode(
            $res['choices'][0]['message']['content'],
            true
        );
    }

    private function normalize(array $data): array
    {
        return [
            'paper_code' => $data['paper_code'] ?? '',
            'title'      => $data['title'] ?? '',
            'authors'    => $data['authors'] ?? '',
            'doi'        => $data['doi'] ?? '',
            'year'       => $data['year'] ?? '',
            'category'   => $data['category'] ?? '',
            'journal'    => $data['journal'] ?? '',
            'issn_isbn'  => $data['issn_isbn'] ?? '',
            'publisher'  => $data['publisher'] ?? '',
            'place'      => $data['place'] ?: 'N/A',
            'volume'     => $data['volume'] ?? '',
            'issue'      => $data['issue'] ?? '',
            'page_no'    => $data['page_no'] ?? '',
            'area'       => $data['area'] ?? '',
        ];
    }
}
