<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\GiveFeedbackTool;
use App\Models\ConnectorGuideline;
use App\Models\ConnectorSettings;
use App\Models\McpToolCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Contracts\Transport;
use ReflectionClass;
use Tests\TestCase;

class FeedbackToolTest extends TestCase
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
     * The feedback is kept verbatim in the athlete's own words while the
     * guideline distilled from it is what ends up in the server
     * instructions. Hence the two columns: a standing rule can always be
     * traced back to what was actually said.
     */
    public function test_behaviour_feedback_becomes_a_standing_guideline(): void
    {
        $response = GarminHealthServer::tool(GiveFeedbackTool::class, [
            'feedback' => 'Please always state the protein next to the calories.',
            'guideline' => 'Always state protein next to calories.',
        ]);

        $response->assertOk()->assertSee('"saved":true');

        $guideline = ConnectorGuideline::sole();
        $this->assertSame('Always state protein next to calories.', $guideline->guideline);
        $this->assertSame('Please always state the protein next to the calories.', $guideline->source_feedback);
    }

    public function test_active_guidelines_travel_with_the_server_instructions(): void
    {
        $active = ConnectorGuideline::create(['user_id' => $this->athlete()->id, 'guideline' => 'Always state protein next to calories.']);
        ConnectorGuideline::create(['user_id' => $this->athlete()->id, 'guideline' => 'Old rule nobody wants.', 'retired_at' => now()]);

        $context = (new GarminHealthServer($this->createStub(Transport::class)))->createContext();

        $this->assertStringContainsString(
            '[g'.$active->id.'] Always state protein next to calories.',
            $context->instructions
        );
        $this->assertStringNotContainsString('Old rule nobody wants.', $context->instructions);
        $this->assertStringContainsString('Personal training and recovery dashboard', $context->instructions);
    }

    public function test_the_server_must_not_pin_instructions_in_the_attribute(): void
    {
        // With the attribute back, Laravel MCP would prefer it over the
        // property and the guideline block would silently stop shipping.
        $this->assertEmpty(
            (new ReflectionClass(GarminHealthServer::class))->getAttributes(Instructions::class)
        );
    }

    public function test_retiring_a_guideline_removes_it_from_the_block(): void
    {
        $guideline = ConnectorGuideline::create(['user_id' => $this->athlete()->id, 'guideline' => 'Answer in bullet points only.']);

        $response = GarminHealthServer::tool(GiveFeedbackTool::class, [
            'feedback' => 'Forget the bullet-point rule.',
            'retire_guideline_id' => $guideline->id,
        ]);

        $response->assertOk()->assertSee('"retired":true');
        $this->assertNotNull($guideline->fresh()->retired_at);
        $this->assertSame('', ConnectorGuideline::instructionsBlock($this->athlete()));
    }

    public function test_behaviour_feedback_without_a_rule_is_rejected(): void
    {
        $response = GarminHealthServer::tool(GiveFeedbackTool::class, [
            'feedback' => 'Do that better.',
        ]);

        $response->assertHasErrors();
        $this->assertSame(0, ConnectorGuideline::count());
    }

    public function test_the_feedback_tool_obeys_its_permission_toggle(): void
    {
        ConnectorSettings::for($this->athlete())->update(['allow_feedback' => false]);

        $response = GarminHealthServer::tool(GiveFeedbackTool::class, [
            'feedback' => 'Shorter, please.',
            'guideline' => 'Keep answers short.',
        ]);

        $response->assertHasErrors();
        $this->assertSame(0, ConnectorGuideline::count());
        $this->assertFalse(McpToolCall::where('tool', 'give-feedback-tool')->sole()->ok);
    }

    public function test_the_connect_page_offers_the_feedback_toggle(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/connect')
            ->assertStatus(200)
            ->assertSee('Process feedback');
    }

    public function test_the_connect_page_lists_guidelines_with_a_confirm_step(): void
    {
        ConnectorGuideline::create(['user_id' => $this->athlete()->id, 'guideline' => 'Always state protein next to calories.']);

        $this->actingAs($this->athlete())
            ->get('/connect')
            ->assertStatus(200)
            ->assertSee('Guidelines from your feedback')
            ->assertSee('Always state protein next to calories.')
            ->assertSee('Delete for good?');
    }

    public function test_a_guideline_can_be_deleted_from_the_connect_page(): void
    {
        $guideline = ConnectorGuideline::create(['user_id' => $this->athlete()->id, 'guideline' => 'Answer in haiku.']);

        $this->actingAs($this->athlete())
            ->post(route('connect.guidelines.delete', $guideline))
            ->assertRedirect(route('connect'));

        $this->assertSame(0, ConnectorGuideline::count());
    }
}
