<?php

namespace App\Support;

class PaperFieldMap
{
    public const MAP = [
        'Paper ID' => 'paper_id',
        'Litracture Review' => 'litracture_review',
        'Category of Paper' => 'category_of_paper',
        'DOI' => 'doi',
        'Author(s)' => 'authors',
        'Year' => 'year',
        'Title' => 'title',
        'Name of Journal/Conference' => 'name_of_journal_conference',
        'ISSN / ISBN' => 'issn_isbn',
        'Name of Publisher / Organization' => 'name_of_publisher_organization',
        'Place of Conference' => 'place_of_conference',
        'Volume' => 'volume',
        'Issue' => 'issue',
        'Page No' => 'page_no',
        'Area / Sub Area' => 'area_sub_area',
        'Key Issue' => 'key_issue',
        'Solution Approach / Methodology used' => 'solution_approach_methodology_used',
        'Related Work' => 'related_work',
        'Input Parameters used' => 'input_parameters_used',
        'Hardware / Software / Technology Used' => 'hardware_software_technology_used',
        'Results' => 'results',
        'Key advantages' => 'key_advantages',
        'Limitations' => 'limitations',
        'Remarks' => 'remarks',
    ];

    public static function toDb(array $input): array
    {
        $out = [];
        foreach (self::MAP as $front => $db) {
            if (array_key_exists($front, $input)) $out[$db] = $input[$front];
        }
        if (isset($input['id']) && !isset($out['paper_id'])) $out['paper_id'] = $input['id'];
        return $out;
    }
}
