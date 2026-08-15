<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\LogSymptomTool;
use App\Mcp\Tools\QueryHealthDataTool;
use App\Models\ConnectorSettings;
use App\Models\McpToolCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpToolCallLogTest extends TestCase
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

    public function test_a_successful_call_is_recorded(): void
    {
        ConnectorSettings::for($this->athlete())->update(['allow_symptoms' => true]);

        GarminHealthServer::tool(LogSymptomTool::class, [
            'symptom' => 'kratziger Hals',
        ]);

        $call = McpToolCall::sole();

        $this->assertSame('log-symptom-tool', $call->tool);
        $this->assertTrue($call->ok);
        $this->assertNull($call->error);
        $this->assertContains($call->transport, ['stdio', 'web']);
    }

    public function test_a_denied_permission_is_recorded_as_a_failed_call(): void
    {
        ConnectorSettings::for($this->athlete())->update(['allow_symptoms' => false]);

        GarminHealthServer::tool(LogSymptomTool::class, [
            'symptom' => 'kratziger Hals',
        ]);

        $call = McpToolCall::sole();

        $this->assertSame('log-symptom-tool', $call->tool);
        $this->assertFalse($call->ok);
        $this->assertStringContainsString('disabled', (string) $call->error);
    }

    public function test_a_validation_failure_is_recorded_with_its_message(): void
    {
        ConnectorSettings::for($this->athlete())->update(['allow_symptoms' => true]);

        GarminHealthServer::tool(LogSymptomTool::class, [
            'symptom' => 'kratziger Hals',
            'severity' => 9,
        ]);

        $call = McpToolCall::sole();

        $this->assertFalse($call->ok);
        $this->assertStringStartsWith('validation:', (string) $call->error);
    }

    public function test_arguments_are_recorded_so_usage_can_be_analysed(): void
    {
        ConnectorSettings::for($this->athlete())->update(['allow_symptoms' => true]);

        GarminHealthServer::tool(LogSymptomTool::class, [
            'symptom' => 'Kopfschmerzen seit dem Aufstehen',
        ]);

        $this->assertSame('Kopfschmerzen seit dem Aufstehen', McpToolCall::sole()->arguments['symptom']);
    }

    /**
     * The base class calls execute() through the container, which is what keeps
     * method injection alive. This tool takes a ReadOnlyGarminQuery on top of the
     * request, so an unresolved dependency would blow up instead of being logged.
     */
    public function test_method_injection_still_resolves_for_tools_that_need_it(): void
    {
        ConnectorSettings::for($this->athlete())->update(['share_health_data' => true]);

        GarminHealthServer::tool(QueryHealthDataTool::class, [
            'sql' => 'SELECT 1 AS one',
        ]);

        $call = McpToolCall::sole();

        $this->assertSame('query-health-data-tool', $call->tool);
        $this->assertSame('SELECT 1 AS one', $call->arguments['sql']);
    }
}
