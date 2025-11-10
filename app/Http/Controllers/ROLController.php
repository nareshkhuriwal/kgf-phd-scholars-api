<?php

namespace App\Http\Controllers;

use App\Models\Paper;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\PhpWord;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ROLController extends Controller
{
    /** Return normalized rows for export based on filters (e.g., collection_id) */
    protected function rows(Request $req): array {
        $q = Paper::query();

        if ($cid = $req->get('collection_id')) {
            $q->whereHas('collectionItems', fn($w) => $w->where('collection_id', $cid));
        }
        if ($s = $req->get('search')) {
            $q->where(function($w) use ($s) {
                $w->where('title','like',"%$s%")
                  ->orWhere('authors','like',"%$s%")
                  ->orWhere('doi','like',"%$s%");
            });
        }

        $papers = $q->orderBy('id','desc')->get();

        $map = fn(Paper $p) => [
            'Paper ID'                                      => $p->paper_code,
            'Litracture Review'                             => $p->review_html,
            'Category of Paper'                             => $p->category,
            'DOI'                                           => $p->doi,
            'Author(s)'                                     => $p->authors,
            'Year'                                          => $p->year,
            'Title'                                         => $p->title,
            'Name of Journal/Conference'                    => $p->journal,
            'ISSN / ISBN'                                   => $p->issn_isbn,
            'Name of Publisher / Organization'              => $p->publisher,
            'Place of Conference'                           => $p->place,
            'Volume'                                        => $p->volume,
            'Issue'                                         => $p->issue,
            'Page No'                                       => $p->page_no,
            'Area / Sub Area'                               => $p->area,
            'Key Issue'                                     => $p->key_issue,
            'Solution Approach / Methodology used'          => $p->solution_method_html,
            'Related Work'                                  => $p->related_work_html,
            'Input Parameters used'                         => $p->input_params_html,
            'Hardware / Software / Technology Used'         => $p->hw_sw_html,
            'Results'                                       => $p->results_html,
            'Key advantages'                                => $p->advantages_html,
            'Limitations'                                   => $p->limitations_html,
            'Remarks'                                       => $p->remarks_html,
        ];

        return $papers->map($map)->all();
    }

    /** XLSX export: strip HTML tags for spreadsheet readability */
    public function exportXlsx(Request $req): StreamedResponse {
        $rows = $this->rows($req);

        $headers = array_keys($rows[0] ?? [
            'Paper ID'=>null,'Litracture Review'=>null,'Category of Paper'=>null,'DOI'=>null,'Author(s)'=>null,
            'Year'=>null,'Title'=>null,'Name of Journal/Conference'=>null,'ISSN / ISBN'=>null,
            'Name of Publisher / Organization'=>null,'Place of Conference'=>null,'Volume'=>null,'Issue'=>null,
            'Page No'=>null,'Area / Sub Area'=>null,'Key Issue'=>null,'Solution Approach / Methodology used'=>null,
            'Related Work'=>null,'Input Parameters used'=>null,'Hardware / Software / Technology Used'=>null,
            'Results'=>null,'Key advantages'=>null,'Limitations'=>null,'Remarks'=>null,
        ]);

        $ss = new Spreadsheet();
        $sheet = $ss->getActiveSheet();
        // Header
        foreach ($headers as $i => $h) {
            $sheet->setCellValueByColumnAndRow($i+1, 1, $h);
        }
        // Data
        $r = 2;
        foreach ($rows as $row) {
            foreach (array_values($headers) as $i => $h) {
                $val = $row[$h] ?? '';
                $sheet->setCellValueByColumnAndRow($i+1, $r, strip_tags((string)$val));
            }
            $r++;
        }

        $writer = new Xlsx($ss);
        return new StreamedResponse(function() use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="ROL.xlsx"',
        ]);
    }

    /** DOCX export: preserve HTML (basic) as text; for rich HTML parsing you could add Html::addHtml */
    public function exportDocx(Request $req): StreamedResponse {
        $rows = $this->rows($req);

        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $section->addText('Review of Literature', ['bold'=>true, 'size'=>16]);
        $section->addTextBreak(1);

        foreach ($rows as $row) {
            $head = trim(($row['Author(s)'] ?? '').(($row['Year'] ?? '') ? ', '.$row['Year'] : ''));
            if ($head) {
                $section->addText("[$head]", ['bold'=>true, 'size'=>12]);
            }
            // We keep the main review first; then other fields if present:
            $fields = [
                'Litracture Review','Key Issue','Solution Approach / Methodology used','Related Work',
                'Input Parameters used','Hardware / Software / Technology Used','Results','Key advantages','Limitations','Remarks'
            ];
            foreach ($fields as $f) {
                $txt = trim(strip_tags((string)($row[$f] ?? '')));
                if ($txt !== '') {
                    $section->addText($txt, ['size'=>11]);
                    $section->addTextBreak(1);
                }
            }
            $section->addTextBreak(1);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'rol_').'.docx';
        \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($tmp);

        return response()->download($tmp, 'Review_of_Literature.docx')->deleteFileAfterSend(true);
    }
}
