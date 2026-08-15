<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

/**
 * English is the source language: every user-facing string sits inline in
 * `__()`, `trans_choice()` or the JS helper `T()`, and `lang/de.json` is
 * the German translation of exactly those strings.
 *
 * This test keeps the two sides honest in both directions. A new string
 * without its German line would silently ship English text to a German
 * reader, and a line left behind after its string was reworded would rot
 * unnoticed, because Laravel falls back to the source string instead of
 * failing.
 */
class TranslationCoverageTest extends TestCase
{
    /** Where user-facing strings may live. */
    private const ROOTS = ['app', 'resources/views', 'resources/js', 'routes', 'config', 'database'];

    /**
     * Strings the scanner cannot see because they are not written inside
     * a call: two lookup tables in `resources/js/app.js` hold their
     * labels as plain literals and pass them through `T()` at draw time
     * (`OVERLAY_SERIES`, the chart overlay labels and axis titles, and
     * `TE_STEPS`, the six Firstbeat training-effect steps). They are
     * translated like any other string, so the German file must carry
     * them; they just cannot be found by reading the call sites.
     */
    private const RUNTIME_ONLY = [
        'ATL',
        'CTL',
        'HRV, 7-day mean',
        'HRV, ms',
        'Intensity minutes',
        'Minutes',
        'Resting heart rate, bpm',
        'no effect',
        'minor',
        'maintaining',
        'improving',
        'highly improving',
        'overreaching',
    ];

    /**
     * Every source string, mapped to the files it appears in.
     *
     * @return array<string, array<int, string>>
     */
    private function sourceStrings(): array
    {
        // __('…') / trans_choice('…') in PHP and Blade, T('…') in JS.
        // The lookbehind keeps `$this->__(` and identifiers ending in
        // T out of the match.
        $pattern = '/(?<![\w$>])(?:__|T|trans_choice)\(\s*'
            ."(?:'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\")/s";

        $found = [];

        foreach (self::ROOTS as $root) {
            $directory = new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS);

            foreach (new \RecursiveIteratorIterator($directory) as $file) {
                /** @var \SplFileInfo $file */
                if (! in_array($file->getExtension(), ['php', 'js'], true)) {
                    continue;
                }

                preg_match_all($pattern, (string) file_get_contents($file->getPathname()), $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $key = ($match[1] ?? '') !== ''
                        ? stripcslashes($match[1])
                        : stripcslashes($match[2] ?? '');

                    $found[$key][] = $root.'/'.$file->getBasename();
                }
            }
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private function german(): array
    {
        $json = json_decode((string) file_get_contents(base_path('lang/de.json')), true);

        $this->assertIsArray($json, 'lang/de.json must be a JSON object.');

        return $json;
    }

    public function test_every_source_string_has_a_german_line(): void
    {
        $german = $this->german();

        $missing = [];
        foreach ($this->sourceStrings() as $key => $files) {
            if (! array_key_exists($key, $german)) {
                $missing[] = $key.'   ('.implode(', ', array_unique($files)).')';
            }
        }

        $this->assertSame([], $missing, "Untranslated source strings:\n".implode("\n", $missing));
    }

    public function test_no_german_line_outlives_its_source_string(): void
    {
        $found = $this->sourceStrings();

        $stale = [];
        foreach (array_keys($this->german()) as $key) {
            if (! isset($found[$key]) && ! in_array($key, self::RUNTIME_ONLY, true)) {
                $stale[] = $key;
            }
        }

        $this->assertSame([], $stale, "German lines without a source string:\n".implode("\n", $stale));
    }

    public function test_the_runtime_only_strings_are_still_translated(): void
    {
        $german = $this->german();

        foreach (self::RUNTIME_ONLY as $key) {
            $this->assertArrayHasKey($key, $german, "Runtime-only string missing from lang/de.json: {$key}");
        }
    }

    public function test_no_german_line_is_empty(): void
    {
        foreach ($this->german() as $key => $value) {
            $this->assertIsString($value, "Translation of \"{$key}\" must be a string.");
            $this->assertNotSame('', trim($value), "Translation of \"{$key}\" is empty.");
        }
    }
}
