<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\DeleteSymptomTool;
use App\Mcp\Tools\GetHealthSummaryTool;
use App\Mcp\Tools\LogSymptomTool;
use App\Models\ConnectorSettings;
use App\Models\McpToolCall;
use App\Models\SymptomLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesGarminMirror;
use Tests\TestCase;

class SymptomToolsTest extends TestCase
{
    use FakesGarminMirror;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every installation has an owner, and the console paths
        // these tests drive (scheduled senders, stdio MCP) act for
        // that owner. See Tests\TestCase::athlete().
        $this->athlete();
    }

    public function test_log_creates_an_entry_with_todays_date(): void
    {
        GarminHealthServer::tool(LogSymptomTool::class, [
            'symptom' => 'scratchy throat',
            'severity' => 1,
            'note' => 'since waking up',
        ]);

        $entry = SymptomLog::sole();
        $this->assertSame(now()->toDateString(), $entry->date);
        $this->assertSame('scratchy throat', $entry->symptom);
        $this->assertSame(1, $entry->severity);
        $this->assertTrue(McpToolCall::sole()->ok);
    }

    public function test_a_late_mention_lands_on_the_named_day_at_noon(): void
    {
        $yesterday = now()->subDay()->toDateString();

        GarminHealthServer::tool(LogSymptomTool::class, [
            'symptom' => 'Kopfschmerzen',
            'date' => $yesterday,
        ]);

        $entry = SymptomLog::sole();
        $this->assertSame($yesterday, $entry->date);
        $this->assertSame('12:00', $entry->logged_at->format('H:i'));
    }

    public function test_log_is_denied_when_symptoms_are_disabled(): void
    {
        ConnectorSettings::for($this->athlete())->update(['allow_symptoms' => false]);

        GarminHealthServer::tool(LogSymptomTool::class, ['symptom' => 'Husten']);

        $this->assertSame(0, SymptomLog::count());
        $this->assertFalse(McpToolCall::sole()->ok);
    }

    public function test_log_rejects_a_severity_outside_the_scale(): void
    {
        GarminHealthServer::tool(LogSymptomTool::class, [
            'symptom' => 'Husten',
            'severity' => 4,
        ]);

        $this->assertSame(0, SymptomLog::count());
        $this->assertStringStartsWith('validation:', (string) McpToolCall::sole()->error);
    }

    public function test_delete_removes_the_named_entry(): void
    {
        $entry = SymptomLog::create([
            'user_id' => $this->athlete()->id,
            'date' => now()->toDateString(),
            'logged_at' => now(),
            'symptom' => 'falsch erfasst',
        ]);

        GarminHealthServer::tool(DeleteSymptomTool::class, ['id' => $entry->id]);

        $this->assertSame(0, SymptomLog::count());
        $this->assertTrue(McpToolCall::sole()->ok);
    }

    public function test_summary_carries_the_last_three_days_with_ids(): void
    {
        $entry = SymptomLog::create([
            'user_id' => $this->athlete()->id,
            'date' => now()->subDays(2)->toDateString(),
            'logged_at' => now()->subDays(2),
            'symptom' => 'Gliederschmerzen',
            'severity' => 2,
        ]);
        SymptomLog::create([
            'user_id' => $this->athlete()->id,
            'date' => now()->subDays(3)->toDateString(),
            'logged_at' => now()->subDays(3),
            'symptom' => 'too old for the window',
        ]);

        $response = GarminHealthServer::tool(GetHealthSummaryTool::class, ['days' => 7]);

        $response->assertSee('Gliederschmerzen');
        $response->assertSee('"id":'.$entry->id);
        $response->assertDontSee('too old for the window');
    }

    public function test_summary_hides_symptoms_when_the_toggle_is_off(): void
    {
        ConnectorSettings::for($this->athlete())->update(['allow_symptoms' => false]);
        SymptomLog::create([
            'user_id' => $this->athlete()->id,
            'date' => now()->toDateString(),
            'logged_at' => now(),
            'symptom' => 'scratchy throat',
        ]);

        $response = GarminHealthServer::tool(GetHealthSummaryTool::class, ['days' => 7]);

        $response->assertDontSee('scratchy throat');
        $response->assertDontSee('symptoms');
    }

    public function test_the_connect_page_offers_the_symptom_toggle(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/connect')
            ->assertStatus(200)
            ->assertSee('Log how you feel');
    }

    public function test_the_banner_carries_recent_symptoms_as_context(): void
    {
        // Firing pattern plus a symptom volunteered yesterday.
        $this->mockGarmin(['rhrToday' => 56.0, 'respToday' => 16.5, 'hrvLastNight' => 40.0]);
        SymptomLog::create([
            'user_id' => $this->athlete()->id,
            'date' => now()->subDay()->toDateString(),
            'logged_at' => now()->subDay(),
            'symptom' => 'scratchy throat',
        ]);

        $this->actingAs($this->athlete())
            ->get('/')
            ->assertStatus(200)
            ->assertSee('Unusual pattern')
            ->assertSee('Also reported: scratchy throat, yesterday');
    }

    public function test_without_the_banner_symptoms_appear_nowhere(): void
    {
        // Quiet data: no banner, and the volunteered symptom must not
        // surface anywhere else on the page.
        $this->mockGarmin(['rhrToday' => 51.0, 'respToday' => 14.2, 'hrvLastNight' => 55.0]);
        SymptomLog::create([
            'user_id' => $this->athlete()->id,
            'date' => now()->toDateString(),
            'logged_at' => now(),
            'symptom' => 'scratchy throat',
        ]);

        $this->actingAs($this->athlete())
            ->get('/')
            ->assertStatus(200)
            ->assertDontSee('Dazu gemeldet')
            ->assertDontSee('scratchy throat');
    }
}
