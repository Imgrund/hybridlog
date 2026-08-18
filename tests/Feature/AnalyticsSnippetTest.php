<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The one script in these pages that is not this app's own.
 *
 * A public demo is worth counting visitors in. A dashboard holding one
 * person's health record is not, and that is the installation everybody
 * else is running, so silence has to be what an untouched configuration
 * produces: no tag, no third-party host in any page. The tag appears
 * only where an operator filled both lines, and half a pair counts as
 * unset, because a tracker with no site to report to is a download and
 * a 404 rather than a harmless no-op.
 */
class AnalyticsSnippetTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = 'https://analytics.example.com/script.js';

    private const SITE = '9d7c3f18-2b64-4a51-8f0d-5c1e7a2b6d90';

    public function test_an_untouched_configuration_ships_no_outside_script(): void
    {
        $this->umami('', '');

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertDontSee('data-website-id', false)
            ->assertDontSee('analytics.example.com', false);
    }

    public function test_both_lines_render_the_tag(): void
    {
        $this->umami(self::SCRIPT, self::SITE);

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('<script defer src="'.self::SCRIPT.'" data-website-id="'.self::SITE.'"></script>', false);
    }

    /** @return array<string, array{string, string}> */
    public static function halfPairs(): array
    {
        return [
            'script without a site' => [self::SCRIPT, ''],
            'site without a script' => ['', self::SITE],
        ];
    }

    #[DataProvider('halfPairs')]
    public function test_half_a_pair_renders_nothing(string $script, string $site): void
    {
        $this->umami($script, $site);

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertDontSee('<script defer src', false);
    }

    public function test_the_tag_reaches_the_pages_a_visitor_arrives_on(): void
    {
        // Every page builds its head from one partial, so counting the
        // dashboard alone would prove nothing about the page most first
        // visitors actually see: the sign-in they land on.
        $this->umami(self::SCRIPT, self::SITE);

        $this->get('/login')
            ->assertOk()
            ->assertSee('data-website-id="'.self::SITE.'"', false);
    }

    public function test_a_configured_value_cannot_break_out_of_the_attribute(): void
    {
        // These two come from the environment rather than from a visitor,
        // so this is not an injection route. It is the reason the values
        // go through Blade's escaping instead of being echoed raw: a
        // pasted URL with a stray quote in it should break the count, not
        // the page.
        $this->umami(self::SCRIPT.'" onload="alert(1)', self::SITE);

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertDontSee('onload="alert(1)"', false);
    }

    private function umami(string $script, string $site): void
    {
        config([
            'analytics.umami.script_url' => $script,
            'analytics.umami.website_id' => $site,
        ]);
    }
}
