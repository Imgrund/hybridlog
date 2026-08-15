<?php

namespace Tests\Feature;

use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\QueryHealthDataTool;
use App\Models\ConnectorSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTestMirror;
use Tests\TestCase;

class ConnectorPermissionsTest extends TestCase
{
    use RefreshDatabase, UsesTestMirror;

    protected function setUp(): void
    {
        parent::setUp();

        // Every installation has an owner, and the console paths
        // these tests drive (scheduled senders, stdio MCP) act for
        // that owner. See Tests\TestCase::athlete().
        $this->athlete();
    }

    public function test_connect_page_renders_with_permission_toggles(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/connect')
            ->assertStatus(200)
            ->assertSee('Data and control: what may the AI do?')
            ->assertSee('Read health data');
    }

    public function test_permissions_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/connect/permissions', [
                'share_health_data' => '1',
                'allow_symptoms' => '1',
            ])
            ->assertRedirect('/connect');

        $settings = ConnectorSettings::for($user);
        $this->assertTrue($settings->share_health_data);
        $this->assertFalse($settings->share_body_metrics);
        $this->assertTrue($settings->allow_symptoms);
        $this->assertFalse($settings->allow_refresh);
    }

    private function withBodyMetricsHidden(): void
    {
        $this->useTestMirror();
        $this->mirror()->statement('create table days (date text primary key, steps integer)');
        $this->mirror()->statement('create table body_comp (date text primary key, weight_g integer)');
        $this->mirror()->table('days')->insert(['date' => '2026-07-01', 'steps' => 9000]);

        ConnectorSettings::for($this->athlete())->update(['share_body_metrics' => false]);
    }

    public function test_a_query_reaching_into_body_comp_is_refused_when_body_metrics_are_hidden(): void
    {
        $this->withBodyMetricsHidden();

        // Wrapped in a CTE and aliased, so nothing here would be caught by
        // matching the table name against the statement text.
        $response = GarminHealthServer::tool(QueryHealthDataTool::class, [
            'sql' => 'with w as (select weight_g from body_comp b) select count(*) from w',
        ]);

        $response->assertHasErrors();
    }

    public function test_a_query_that_only_borrows_the_name_as_an_alias_still_runs(): void
    {
        $this->withBodyMetricsHidden();

        $response = GarminHealthServer::tool(QueryHealthDataTool::class, [
            'sql' => 'select steps as body_comp from days',
        ]);

        $response->assertOk();
    }
}
