<?php

namespace App\Services;

use App\Models\Citation;
use InvalidArgumentException;

class CitationFormatter
{
    /* =========================
     * STYLE IMPLEMENTATIONS
     * ========================= */

    // MLA (9th Edition) – order accepted but not displayed
    public static function mla(Citation $c, int $order): string
    {
        $parts = [];

        if ($c->authors) $parts[] = $c->authors . '.';
        if ($c->title)   $parts[] = '"' . $c->title . '."';

        if ($c->journal)      $parts[] = $c->journal . ',';
        elseif ($c->conference) $parts[] = $c->conference . ',';

        if ($c->volume) $parts[] = 'vol. ' . $c->volume . ',';
        if ($c->issue)  $parts[] = 'no. ' . $c->issue . ',';
        if ($c->year)   $parts[] = $c->year . ',';
        if ($c->pages)  $parts[] = 'pp. ' . $c->pages . '.';
        if ($c->doi)    $parts[] = 'doi:' . $c->doi;

        return trim(implode(' ', $parts));
    }

    // IEEE
    public static function ieee(Citation $c, int $order): string
    {
        $parts = ["[{$order}]"];

        if ($c->authors) $parts[] = $c->authors . ',';
        if ($c->title)   $parts[] = '"' . $c->title . '",';

        if ($c->journal)      $parts[] = $c->journal . ',';
        elseif ($c->conference) $parts[] = 'in ' . $c->conference . ',';
        elseif ($c->publisher)  $parts[] = $c->publisher . ',';

        if ($c->volume) $parts[] = 'vol. ' . $c->volume . ',';
        if ($c->issue)  $parts[] = 'no. ' . $c->issue . ',';
        if ($c->pages)  $parts[] = 'pp. ' . $c->pages . ',';
        if ($c->year)   $parts[] = $c->year . '.';

        return trim(implode(' ', $parts));
    }

    // APA (7th)
    public static function apa(Citation $c, int $order): string
    {
        $parts = [];

        if ($c->authors) $parts[] = $c->authors . '.';
        if ($c->year)    $parts[] = '(' . $c->year . ').';
        if ($c->title)   $parts[] = $c->title . '.';

        if ($c->journal) {
            $j = $c->journal;
            if ($c->volume) $j .= ', ' . $c->volume;
            if ($c->issue)  $j .= '(' . $c->issue . ')';
            $parts[] = $j . ',';
        }

        if ($c->pages) $parts[] = $c->pages . '.';
        if ($c->doi)   $parts[] = 'https://doi.org/' . $c->doi;

        return trim(implode(' ', $parts));
    }

    // Chicago (Author–Date)
    public static function chicago(Citation $c, int $order): string
    {
        $parts = [];

        if ($c->authors) $parts[] = $c->authors . '.';
        if ($c->year)    $parts[] = $c->year . '.';
        if ($c->title)   $parts[] = '"' . $c->title . '."';

        if ($c->journal) {
            $j = $c->journal;
            if ($c->volume) $j .= ' ' . $c->volume;
            if ($c->issue)  $j .= ' (' . $c->issue . ')';
            $parts[] = $j . ':';
        }

        if ($c->pages) $parts[] = $c->pages . '.';

        return trim(implode(' ', $parts));
    }

    // Harvard
    public static function harvard(Citation $c, int $order): string
    {
        $parts = [];

        if ($c->authors) $parts[] = $c->authors;
        if ($c->year)    $parts[] = '(' . $c->year . ')';
        if ($c->title)   $parts[] = "'" . $c->title . "',";

        if ($c->journal) {
            $j = $c->journal;
            if ($c->volume) $j .= ', ' . $c->volume;
            if ($c->issue)  $j .= '(' . $c->issue . ')';
            $parts[] = $j . ',';
        }

        if ($c->pages) $parts[] = 'pp. ' . $c->pages . '.';

        return trim(implode(' ', $parts));
    }

    // Vancouver
    public static function vancouver(Citation $c, int $order): string
    {
        $parts = [$order . '.'];

        if ($c->authors) $parts[] = $c->authors . '.';
        if ($c->title)   $parts[] = $c->title . '.';
        if ($c->journal) $parts[] = $c->journal . '.';

        $pub = [];
        if ($c->year)   $pub[] = $c->year;
        if ($c->volume) $pub[] = $c->volume . ($c->issue ? "({$c->issue})" : '');

        if ($pub) {
            $parts[] = implode(';', $pub) . ':' . ($c->pages ?? '') . '.';
        }

        return trim(implode(' ', $parts));
    }

    // ACM
    public static function acm(Citation $c, int $order): string
    {
        $parts = ["[{$order}]"];

        if ($c->authors) $parts[] = $c->authors . '.';
        if ($c->year)    $parts[] = $c->year . '.';
        if ($c->title)   $parts[] = $c->title . '.';

        if ($c->journal) {
            $j = $c->journal;
            if ($c->volume) $j .= ' ' . $c->volume;
            if ($c->issue)  $j .= ', ' . $c->issue;
            if ($c->year)   $j .= ' (' . $c->year . ')';
            $parts[] = $j . ',';
        }

        if ($c->pages) $parts[] = $c->pages . '.';
        if ($c->doi)   $parts[] = 'DOI: https://doi.org/' . $c->doi;

        return trim(implode(' ', $parts));
    }

    // Springer
    public static function springer(Citation $c, int $order): string
    {
        $parts = [];

        if ($c->authors) $parts[] = $c->authors;
        if ($c->year)    $parts[] = '(' . $c->year . ')';
        if ($c->title)   $parts[] = $c->title . '.';

        if ($c->journal) {
            $j = $c->journal;
            if ($c->volume) $j .= ' ' . $c->volume;
            $parts[] = $j . ':' . ($c->pages ?? '');
        }

        if ($c->doi) $parts[] = 'https://doi.org/' . $c->doi;

        return trim(implode(' ', $parts));
    }

    /* =========================
     * DISPATCH
     * ========================= */

    public static function format(Citation $citation, string $style, int $order): string
    {
        $style = strtolower($style);

        if (!method_exists(self::class, $style)) {
            throw new InvalidArgumentException("Unsupported citation style: {$style}");
        }

        return self::$style($citation, $order);
    }

    public static function getAvailableStyles(): array
    {
        return [
            'mla'       => 'MLA (9th Edition)',
            'ieee'      => 'IEEE',
            'apa'       => 'APA (7th Edition)',
            'chicago'   => 'Chicago (Author–Date)',
            'harvard'   => 'Harvard',
            'vancouver' => 'Vancouver',
            'acm'       => 'ACM',
            'springer'  => 'Springer',
        ];
    }
}
