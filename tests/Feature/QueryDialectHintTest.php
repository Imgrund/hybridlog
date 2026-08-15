<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\DescribeSchemaTool;
use App\Mcp\Tools\QueryHealthDataTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTestMirror;
use Tests\TestCase;

/**
 * The two places that keep a model from tripping over Postgres.
 *
 * Nearly every measurement column is double precision, and Postgres has
 * no two-argument round() for that type, which SQLite (and the habit a
 * model brings along) happily allows. The schema notes warn ahead of
 * time, and the query error names the cast, because Postgres' own hint
 * ("add explicit type casts") does not say which one and a model that
 * has to guess burns a round trip on it.
 */
class QueryDialectHintTest extends TestCase
{
    use RefreshDatabase, UsesTestMirror;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete();
    }

    public function test_a_round_on_double_precision_fails_with_the_cast_named(): void
    {
        $this->useTestMirror();
        $this->mirror()->statement('create table acts (id integer primary key, duration_s double precision)');
        $this->mirror()->table('acts')->insert(['id' => 1, 'duration_s' => 3723.0]);

        $response = GarminHealthServer::tool(QueryHealthDataTool::class, [
            'sql' => 'select round(duration_s / 60, 1) as minutes from acts',
        ]);

        $response->assertHasErrors()
            ->assertSee('round((expr)::numeric, 1)');
    }

    public function test_the_schema_notes_warn_about_the_round_signature_up_front(): void
    {
        $response = GarminHealthServer::tool(DescribeSchemaTool::class, []);

        $response->assertOk()->assertSee('round((expr)::numeric, 1)');
    }
}
