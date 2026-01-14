<?php
// app/Services/CitationFormatter.php
namespace App\Services;

use App\Models\Citation;

class CitationFormatter
{
    /**
     * MLA Citation Style (9th Edition) - DEFAULT
     * Smith, John. "Article Title." Journal Name, vol. 10, no. 2, 2020, pp. 123-145.
     */
    public static function mla(Citation $c, int $index = null): string
    {
        $parts = [];
        
        // Authors (Last, First)
        if ($c->authors) {
            $parts[] = $c->authors . '.';
        }
        
        // Title (in quotes)
        if ($c->title) {
            $parts[] = '"' . $c->title . '."';
        }
        
        // Journal or Conference (italicized)
        if ($c->journal) {
            $parts[] = $c->journal . ',';
        } elseif ($c->conference) {
            $parts[] = $c->conference . ',';
        }
        
        // Volume
        if ($c->volume) {
            $parts[] = 'vol. ' . $c->volume . ',';
        }
        
        // Issue
        if ($c->issue) {
            $parts[] = 'no. ' . $c->issue . ',';
        }
        
        // Year
        if ($c->year) {
            $parts[] = $c->year . ',';
        }
        
        // Pages
        if ($c->pages) {
            $parts[] = 'pp. ' . $c->pages . '.';
        }
        
        // DOI (optional)
        if ($c->doi) {
            $parts[] = 'doi:' . $c->doi;
        }
        
        return implode(' ', $parts);
    }

    /**
     * IEEE Citation Style
     * [1] J. Smith, "Article Title", Journal Name, vol. 10, no. 2, pp. 123-145, 2020.
     */
    public static function ieee(Citation $c, int $index): string
    {
        $parts = [];
        
        // Number
        $parts[] = "[{$index}]";
        
        // Authors
        if ($c->authors) {
            $parts[] = $c->authors . ',';
        }
        
        // Title
        if ($c->title) {
            $parts[] = '"' . $c->title . '",';
        }
        
        // Journal/Conference/Publisher
        if ($c->journal) {
            $parts[] = $c->journal . ',';
        } elseif ($c->conference) {
            $parts[] = 'in ' . $c->conference . ',';
        } elseif ($c->publisher) {
            $parts[] = $c->publisher . ',';
        }
        
        // Volume and Issue
        if ($c->volume) {
            $parts[] = 'vol. ' . $c->volume . ',';
        }
        if ($c->issue) {
            $parts[] = 'no. ' . $c->issue . ',';
        }
        
        // Pages
        if ($c->pages) {
            $parts[] = 'pp. ' . $c->pages . ',';
        }
        
        // Year
        if ($c->year) {
            $parts[] = $c->year . '.';
        }
        
        return implode(' ', $parts);
    }

    /**
     * APA Citation Style (7th Edition)
     * Smith, J. (2020). Article title. Journal Name, 10(2), 123-145. https://doi.org/10.xxxx
     */
    public static function apa(Citation $c, int $index = null): string
    {
        $parts = [];
        
        // Authors (Last, F. M.)
        if ($c->authors) {
            $parts[] = $c->authors . '.';
        }
        
        // Year
        if ($c->year) {
            $parts[] = '(' . $c->year . ').';
        }
        
        // Title (sentence case, no quotes)
        if ($c->title) {
            $parts[] = $c->title . '.';
        }
        
        // Journal (italicized in output)
        if ($c->journal) {
            $journalPart = $c->journal;
            
            // Volume (italicized)
            if ($c->volume) {
                $journalPart .= ', ' . $c->volume;
            }
            
            // Issue (not italicized)
            if ($c->issue) {
                $journalPart .= '(' . $c->issue . ')';
            }
            
            $parts[] = $journalPart . ',';
        } elseif ($c->conference) {
            $parts[] = $c->conference . ',';
        }
        
        // Pages
        if ($c->pages) {
            $parts[] = $c->pages . '.';
        }
        
        // DOI
        if ($c->doi) {
            $parts[] = 'https://doi.org/' . $c->doi;
        }
        
        return implode(' ', $parts);
    }

    /**
     * Chicago Citation Style (Author-Date)
     * Smith, John. 2020. "Article Title." Journal Name 10 (2): 123-145.
     */
    public static function chicago(Citation $c, int $index = null): string
    {
        $parts = [];
        
        // Authors
        if ($c->authors) {
            $parts[] = $c->authors . '.';
        }
        
        // Year
        if ($c->year) {
            $parts[] = $c->year . '.';
        }
        
        // Title (in quotes)
        if ($c->title) {
            $parts[] = '"' . $c->title . '."';
        }
        
        // Journal or Conference
        if ($c->journal) {
            $journalPart = $c->journal;
            
            if ($c->volume) {
                $journalPart .= ' ' . $c->volume;
            }
            
            if ($c->issue) {
                $journalPart .= ' (' . $c->issue . ')';
            }
            
            $parts[] = $journalPart . ':';
        } elseif ($c->conference) {
            $parts[] = $c->conference . ':';
        }
        
        // Pages
        if ($c->pages) {
            $parts[] = $c->pages . '.';
        }
        
        return implode(' ', $parts);
    }

    /**
     * Harvard Citation Style
     * Smith, J. (2020) 'Article title', Journal Name, 10(2), pp. 123-145.
     */
    public static function harvard(Citation $c, int $index = null): string
    {
        $parts = [];
        
        // Authors
        if ($c->authors) {
            $parts[] = $c->authors;
        }
        
        // Year
        if ($c->year) {
            $parts[] = '(' . $c->year . ')';
        }
        
        // Title (single quotes)
        if ($c->title) {
            $parts[] = "'" . $c->title . "',";
        }
        
        // Journal or Conference
        if ($c->journal) {
            $journalPart = $c->journal;
            
            if ($c->volume) {
                $journalPart .= ', ' . $c->volume;
            }
            
            if ($c->issue) {
                $journalPart .= '(' . $c->issue . ')';
            }
            
            $parts[] = $journalPart . ',';
        } elseif ($c->conference) {
            $parts[] = $c->conference . ',';
        }
        
        // Pages
        if ($c->pages) {
            $parts[] = 'pp. ' . $c->pages . '.';
        }
        
        return implode(' ', $parts);
    }

    /**
     * Vancouver Citation Style
     * 1. Smith J. Article title. Journal Name. 2020;10(2):123-45.
     */
    public static function vancouver(Citation $c, int $index): string
    {
        $parts = [];
        
        // Number
        $parts[] = $index . '.';
        
        // Authors (abbreviated)
        if ($c->authors) {
            $parts[] = $c->authors . '.';
        }
        
        // Title
        if ($c->title) {
            $parts[] = $c->title . '.';
        }
        
        // Journal or Conference (abbreviated)
        if ($c->journal) {
            $parts[] = $c->journal . '.';
        } elseif ($c->conference) {
            $parts[] = $c->conference . '.';
        }
        
        // Year;Volume(Issue):Pages
        $publicationInfo = [];
        if ($c->year) {
            $publicationInfo[] = $c->year;
        }
        
        $volIssue = '';
        if ($c->volume) {
            $volIssue .= $c->volume;
        }
        if ($c->issue) {
            $volIssue .= '(' . $c->issue . ')';
        }
        if ($volIssue) {
            $publicationInfo[] = $volIssue;
        }
        
        if (!empty($publicationInfo)) {
            $parts[] = implode(';', $publicationInfo) . ':' . ($c->pages ?? '') . '.';
        }
        
        return implode(' ', $parts);
    }

    /**
     * ACM Citation Style
     * [1] John Smith. 2020. Article Title. Journal Name 10, 2 (2020), 123-145.
     */
    public static function acm(Citation $c, int $index): string
    {
        $parts = [];
        
        // Number
        $parts[] = "[{$index}]";
        
        // Authors
        if ($c->authors) {
            $parts[] = $c->authors . '.';
        }
        
        // Year
        if ($c->year) {
            $parts[] = $c->year . '.';
        }
        
        // Title
        if ($c->title) {
            $parts[] = $c->title . '.';
        }
        
        // Journal or Conference
        if ($c->journal) {
            $journalPart = $c->journal;
            
            if ($c->volume) {
                $journalPart .= ' ' . $c->volume;
            }
            
            if ($c->issue) {
                $journalPart .= ', ' . $c->issue;
            }
            
            if ($c->year) {
                $journalPart .= ' (' . $c->year . ')';
            }
            
            $parts[] = $journalPart . ',';
        } elseif ($c->conference) {
            $parts[] = 'In ' . $c->conference . ',';
        }
        
        // Pages
        if ($c->pages) {
            $parts[] = $c->pages . '.';
        }
        
        // DOI
        if ($c->doi) {
            $parts[] = 'DOI: https://doi.org/' . $c->doi;
        }
        
        return implode(' ', $parts);
    }

    /**
     * Springer Citation Style
     * Smith J (2020) Article title. Journal Name 10:123-145
     */
    public static function springer(Citation $c, int $index = null): string
    {
        $parts = [];
        
        // Authors
        if ($c->authors) {
            $parts[] = $c->authors;
        }
        
        // Year
        if ($c->year) {
            $parts[] = '(' . $c->year . ')';
        }
        
        // Title
        if ($c->title) {
            $parts[] = $c->title . '.';
        }
        
        // Journal or Conference
        if ($c->journal) {
            $journalPart = $c->journal;
            
            if ($c->volume) {
                $journalPart .= ' ' . $c->volume;
            }
            
            $parts[] = $journalPart . ':' . ($c->pages ?? '');
        } elseif ($c->conference) {
            $parts[] = $c->conference . ':' . ($c->pages ?? '');
        }
        
        // DOI
        if ($c->doi) {
            $parts[] = 'https://doi.org/' . $c->doi;
        }
        
        return implode(' ', $parts);
    }

    /**
     * Get formatted citation by style
     * Default style is MLA
     */
    public static function format(Citation $citation, string $style = 'mla', int $index = 1): string
    {
        $method = strtolower($style);
        
        if (method_exists(self::class, $method)) {
            return self::$method($citation, $index);
        }
        
        // Default to MLA if style not found
        return self::mla($citation, $index);
    }

    /**
     * Get all available citation styles
     * MLA is listed first as it's the default
     */
    public static function getAvailableStyles(): array
    {
        return [
            'mla' => 'MLA (9th Edition)',
            'ieee' => 'IEEE',
            'apa' => 'APA (7th Edition)',
            'chicago' => 'Chicago (Author-Date)',
            'harvard' => 'Harvard',
            'vancouver' => 'Vancouver',
            'acm' => 'ACM',
            'springer' => 'Springer',
        ];
    }
}