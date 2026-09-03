<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\McpToolCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The usage report tells the athletes of one installation apart.
 *
 * It used to add every call up per tool, so an error rate was a number
 * without an owner: whether the failing SQL came from the athlete who
 * tunes the connector or from a guest who had never seen the schema notes
 * was a question the report could not answer.
 */
class McpUsageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_report_names_each_athlete_with_their_calls(): void
    {
        $owner = $this->athlete();
        $guest = User::factory()->create(['name' => 'Second Athlete']);

        $this->record($owner, 'get-health-summary-tool');
        $this->record($owner, 'get-health-summary-tool');
        $this->record($guest, 'query-health-data-tool', ok: false);

        $this->artisan('mcp:usage')
            ->expectsOutputToContain('Per athlete')
            ->expectsOutputToContain(sprintf('#%d %s', $owner->id, $owner->name))
            ->expectsOutputToContain(sprintf('#%d Second Athlete', $guest->id))
            ->expectsOutputToContain('mostly query-health-data-tool')
            ->assertSuccessful();
    }

    public function test_the_report_can_be_narrowed_to_one_athlete(): void
    {
        $owner = $this->athlete();
        $guest = User::factory()->create(['name' => 'Second Athlete']);

        $this->record($owner, 'get-health-summary-tool');
        $this->record($guest, 'query-health-data-tool', ok: false);

        $this->artisan('mcp:usage', ['--athlete' => $guest->id])
            ->expectsOutputToContain('query-health-data-tool')
            ->doesntExpectOutputToContain('get-health-summary-tool')
            ->assertSuccessful();
    }

    private function record(User $user, string $tool, bool $ok = true): void
    {
        McpToolCall::create([
            'user_id' => $user->id,
            'tool' => $tool,
            'arguments' => [],
            'transport' => 'web',
            'duration_ms' => 12,
            'ok' => $ok,
            'error' => $ok ? null : 'Query failed',
        ]);
    }
}
