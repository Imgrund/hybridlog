<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Prompts\WeeklyReportPrompt;
use App\Mcp\Servers\GarminHealthServer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class WeeklyReportPromptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every installation has an owner, and the console paths
        // these tests drive (scheduled senders, stdio MCP) act for
        // that owner. See Tests\TestCase::athlete().
        $this->athlete();
    }

    /**
     * The server looks the prompt up by name in its own registry, so every
     * call here also asserts that it is actually registered.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function render(array $arguments = []): TestResponse
    {
        return GarminHealthServer::prompt(WeeklyReportPrompt::class, $arguments)->assertOk();
    }

    public function test_it_resolves_the_week_that_just_ended(): void
    {
        // Wednesday, so the reported week is the Mon-Sun before it.
        CarbonImmutable::setTestNow('2026-07-22 08:00:00');

        $this->render()
            ->assertSee("between '2026-07-13' and '2026-07-19'")
            ->assertSee("between '2026-07-06' and '2026-07-12'");
    }

    public function test_a_sunday_run_reports_the_week_ending_that_day(): void
    {
        // The job is meant to fire Sunday 19:00. Treating that Sunday as
        // "not finished yet" would hand back a report a week out of date.
        CarbonImmutable::setTestNow('2026-07-19 19:00:00');

        $this->render()
            ->assertSee("between '2026-07-13' and '2026-07-19'")
            ->assertSee('Sunday is still incomplete');
    }

    public function test_a_midweek_run_carries_no_incomplete_day_warning(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 08:00:00');

        $this->render()->assertDontSee('Sunday is still incomplete');
    }

    public function test_the_chat_answer_is_the_report_and_nothing_is_saved(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 08:00:00');

        $this->render()
            ->assertSee('training load, sleep and HRV')
            ->assertSee('nothing is saved anywhere')
            ->assertDontSee('save-insight');
    }

    public function test_stepping_back_reaches_an_older_week(): void
    {
        CarbonImmutable::setTestNow('2026-07-22 08:00:00');

        $this->render(['weeks_back' => 2])->assertSee("between '2026-06-29' and '2026-07-05'");
    }
}
