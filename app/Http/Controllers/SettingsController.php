<?php
// app/Http/Controllers/SettingsController.php
namespace App\Http\Controllers;

use App\Models\UserSetting;
use App\Services\CitationFormatter;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    // Note formats and languages remain static
    private array $noteFormats = ['markdown+richtext', 'markdown', 'html'];
    private array $languages = ['en-US', 'en-GB', 'hi-IN'];

    /**
     * Get allowed citation styles dynamically from CitationFormatter
     */
    private function getAllowedCitationStyles(): array
    {
        return array_keys(CitationFormatter::getAvailableStyles());
    }

    /**
     * GET /settings
     * Returns user settings with camelCase keys
     */
    public function show(Request $req)
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');

        $settings = UserSetting::firstOrCreate(
            ['user_id' => $user->id],
            [
                'citation_style'     => 'mla', // Default to MLA
                'note_format'        => 'markdown+richtext',
                'language'           => 'en-US',
                'quick_copy_as_html' => false,
                'include_urls'       => false,
            ]
        );

        return response()->json([
            'settings' => [
                'citationStyle'   => $settings->citation_style,
                'noteFormat'      => $settings->note_format,
                'language'        => $settings->language,
                'quickCopyAsHtml' => (bool) $settings->quick_copy_as_html,
                'includeUrls'     => (bool) $settings->include_urls,
            ]
        ]);
    }

    /**
     * PUT /settings
     * Updates user settings with validation
     */
    public function update(Request $req)
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');

        // Get allowed citation styles dynamically
        $allowedStyles = $this->getAllowedCitationStyles();

        $data = $req->validate([
            'citationStyle'   => 'nullable|string|in:' . implode(',', $allowedStyles),
            'noteFormat'      => 'nullable|string|in:' . implode(',', $this->noteFormats),
            'language'        => 'nullable|string|in:' . implode(',', $this->languages),
            'quickCopyAsHtml' => 'nullable|boolean',
            'includeUrls'     => 'nullable|boolean',
        ]);

        $settings = UserSetting::firstOrCreate(['user_id' => $user->id]);

        // Map camelCase → snake_case columns; only update provided keys
        $map = [
            'citationStyle'   => 'citation_style',
            'noteFormat'      => 'note_format',
            'language'        => 'language',
            'quickCopyAsHtml' => 'quick_copy_as_html',
            'includeUrls'     => 'include_urls',
        ];

        foreach ($map as $in => $col) {
            if (array_key_exists($in, $data)) {
                $settings->{$col} = $data[$in];
            }
        }

        $settings->save();

        return response()->json([
            'settings' => [
                'citationStyle'   => $settings->citation_style,
                'noteFormat'      => $settings->note_format,
                'language'        => $settings->language,
                'quickCopyAsHtml' => (bool) $settings->quick_copy_as_html,
                'includeUrls'     => (bool) $settings->include_urls,
            ],
            'message' => 'Settings saved successfully'
        ]);
    }

    /**
     * GET /settings/citation-styles
     * Returns available citation styles (optional helper endpoint)
     */
    public function citationStyles()
    {
        return response()->json([
            'styles' => CitationFormatter::getAvailableStyles()
        ]);
    }
}