<?php
// app/Http/Controllers/SettingsController.php
namespace App\Http\Controllers;

use App\Models\UserSetting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    // Allowed sets for validation (keep in sync with UI)
    private array $styles = [
        'chicago-note-bibliography-short','ieee','apa-7','mla-9','harvard-with-titles'
    ];
    private array $noteFormats = ['markdown+richtext','markdown','html'];
    private array $languages = ['en-US','en-GB','hi-IN'];

    /** GET /settings */
    public function show(Request $req)
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');

        $settings = UserSetting::firstOrCreate(
            ['user_id' => $user->id],
            [
                'citation_style'     => 'chicago-note-bibliography-short',
                'note_format'        => 'markdown+richtext',
                'language'           => 'en-US',
                'quick_copy_as_html' => false,
                'include_urls'       => false,
            ]
        );

        return response()->json(['settings' => [
            'citationStyle'   => $settings->citation_style,
            'noteFormat'      => $settings->note_format,
            'language'        => $settings->language,
            'quickCopyAsHtml' => (bool) $settings->quick_copy_as_html,
            'includeUrls'     => (bool) $settings->include_urls,
        ]]);
    }

    /** PUT /settings */
    public function update(Request $req)
    {
        $user = $req->user() ?? abort(401, 'Unauthenticated');

        $data = $req->validate([
            'citationStyle'   => 'nullable|string|in:'.implode(',', $this->styles),
            'noteFormat'      => 'nullable|string|in:'.implode(',', $this->noteFormats),
            'language'        => 'nullable|string|in:'.implode(',', $this->languages),
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

        return response()->json(['settings' => [
            'citationStyle'   => $settings->citation_style,
            'noteFormat'      => $settings->note_format,
            'language'        => $settings->language,
            'quickCopyAsHtml' => (bool) $settings->quick_copy_as_html,
            'includeUrls'     => (bool) $settings->include_urls,
        ]]);
    }
}
