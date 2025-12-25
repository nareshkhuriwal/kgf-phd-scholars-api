<?php
// app/Services/CitationFormatter.php
namespace App\Services;

use App\Models\Citation;

class CitationFormatter
{
    public static function ieee(Citation $c, int $index)
    {
        return sprintf(
            '[%d] %s, "%s", %s, %s.',
            $index,
            $c->authors,
            $c->title,
            $c->journal ?? $c->publisher,
            $c->year
        );
    }
}
