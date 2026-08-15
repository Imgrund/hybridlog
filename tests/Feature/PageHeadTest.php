<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every page's head comes from one partial.
 *
 * It used to be copied into each view, and the copies drifted: two pages
 * carried no icon and no manifest at all, so the same app was installable
 * from one page and not from the next. Nothing failed, nothing looked
 * broken, and that is exactly why it went unnoticed for as long as it did.
 */
class PageHeadTest extends TestCase
{
    use RefreshDatabase;

    /** Every page that serves a full document. */
    public static function pages(): array
    {
        return [
            'dashboard' => ['/', true],
            'profile' => ['/profile', true],
            'connect AI' => ['/connect', true],
            'connect Garmin' => ['/connect/garmin', true],
            'login' => ['/login', false],
        ];
    }

    #[DataProvider('pages')]
    public function test_a_page_carries_the_icons_and_the_manifest(string $path, bool $needsUser): void
    {
        $request = $needsUser ? $this->actingAs(User::factory()->create()) : $this;

        $request->get($path)
            ->assertStatus(200)
            ->assertSee('rel="icon" href="/favicon.ico"', false)
            ->assertSee('rel="icon" href="/favicon.svg"', false)
            ->assertSee('rel="apple-touch-icon"', false)
            ->assertSee('rel="manifest"', false)
            ->assertSee('name="theme-color"', false);
    }

    public function test_no_view_writes_its_own_head(): void
    {
        // The check that survives a new page being added: a view that
        // opens a head and fills it by hand is how the drift started, and
        // it would pass every test above by simply not being routed yet.
        $offenders = [];

        foreach ($this->viewFiles() as $file) {
            $source = file_get_contents($file);

            if (str_contains($source, '<head>') && ! str_contains($source, "@include('partials.head'")) {
                $offenders[] = str_replace(base_path().'/', '', $file);
            }
        }

        $this->assertSame([], $offenders, 'These views build their own head instead of including partials.head.');
    }

    /** @return list<string> */
    private function viewFiles(): array
    {
        $files = [];

        $tree = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
