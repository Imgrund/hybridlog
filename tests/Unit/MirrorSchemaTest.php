<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Garmin\MirrorSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTestMirror;
use Tests\TestCase;

class MirrorSchemaTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestMirror;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestMirror();

        $this->mirror()->statement(
            'create table days (date text primary key, steps integer, sweat_loss_ml double precision)'
        );
        $this->mirror()->statement("comment on column days.date is 'YYYY-MM-DD'");
        $this->mirror()->statement("comment on column days.sweat_loss_ml is 'Garmin''s per-day estimate'");

        $this->mirror()->statement(
            'create table strength_sets (activity_id bigint, set_index integer, reps integer, '
            .'primary key (activity_id, set_index))'
        );
        $this->mirror()->statement("comment on table strength_sets is 'One row per set.'");
        $this->mirror()->statement(
            "comment on column strength_sets.reps is 'Counted by the watch.\nZero for a rest set.'"
        );
    }

    public function test_every_table_is_rendered_as_a_create_statement(): void
    {
        $schema = app(MirrorSchema::class)->all();

        $this->assertSame(['days', 'strength_sets'], array_keys($schema));
        $this->assertStringStartsWith('CREATE TABLE days (', $schema['days']);
        $this->assertStringEndsWith(');', $schema['days']);
    }

    public function test_a_column_comment_stays_beside_its_column(): void
    {
        $schema = app(MirrorSchema::class)->all();

        // The comment is what carries the unit and the format; a bare
        // "sweat_loss_ml double precision" would tell a model nothing.
        $this->assertMatchesRegularExpression(
            '/^ {4}date text, +-- YYYY-MM-DD$/m',
            $schema['days']
        );
        $this->assertStringContainsString("-- Garmin's per-day estimate", $schema['days']);
        $this->assertStringContainsString('    steps integer,'."\n", $schema['days']);
    }

    public function test_a_comment_that_needs_several_lines_goes_above_the_column(): void
    {
        $schema = app(MirrorSchema::class)->all();

        $this->assertStringContainsString(
            "    -- Counted by the watch.\n    -- Zero for a rest set.\n    reps integer,",
            $schema['strength_sets']
        );
    }

    public function test_a_table_comment_is_rendered_above_the_statement(): void
    {
        $schema = app(MirrorSchema::class)->all();

        $this->assertStringStartsWith(
            "-- One row per set.\nCREATE TABLE strength_sets (",
            $schema['strength_sets']
        );
    }

    public function test_a_composite_primary_key_is_named_in_order(): void
    {
        $schema = app(MirrorSchema::class)->all();

        $this->assertStringContainsString('    PRIMARY KEY (activity_id, set_index)', $schema['strength_sets']);
        $this->assertStringContainsString('    PRIMARY KEY (date)', $schema['days']);
    }
}
