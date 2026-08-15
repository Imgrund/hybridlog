<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pages nobody means to see.
 *
 * Laravel ships its own, and they are white. On an app that is graphite
 * everywhere else a mistyped address arrived looking like a different
 * site, or like an outage rather than a typo. What is pinned here is
 * that ours are reached at all, that they carry the dark shell, and
 * that each code says its own thing rather than sharing one shrug.
 */
class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    /** Every code that has a view, and the line that proves it is that one. */
    public static function codes(): array
    {
        return [
            '403' => ['403', 'No entry.'],
            '404' => ['404', 'Nothing at this address.'],
            '419' => ['419', 'This page sat open too long.'],
            '429' => ['429', 'Too many tries.'],
            '500' => ['500', 'Something broke.'],
            '503' => ['503', 'Back shortly.'],
        ];
    }

    #[DataProvider('codes')]
    public function test_each_code_renders_its_own_page(string $code, string $headline): void
    {
        $html = view("errors.{$code}")->render();

        $this->assertStringContainsString($headline, $html);
        $this->assertStringContainsString($code, $html);
        // The complaint that started this: a white page in a dark app.
        $this->assertStringContainsString('viz-root', $html);
        $this->assertStringContainsString('brand-lockup', $html);
    }

    public function test_a_missing_address_reaches_ours_rather_than_laravels(): void
    {
        // Rendering the view proves it compiles; only a real request
        // proves the framework picks it up for an actual 404.
        $this->get('/a-page-that-was-never-here')
            ->assertStatus(404)
            ->assertSee(__('Nothing at this address.'))
            ->assertSee('viz-root', false);
    }

    public function test_an_error_page_offers_the_way_back(): void
    {
        // The one action on it, and the reason it is not a dead end.
        $html = view('errors.404')->render();

        $this->assertStringContainsString('href="'.route('dashboard').'"', $html);
    }

    public function test_an_error_page_reads_nothing(): void
    {
        // These render at the moment least worth trusting, so they must
        // not reach for a session, an athlete or a mirror. Asserted as
        // the absence of the header that does all three.
        $html = view('errors.500')->render();

        $this->assertStringNotContainsString('panel-koerperkarte', $html);
        $this->assertStringNotContainsString(route('logout'), $html);
    }
}
