<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\Mirror;
use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\DeleteSymptomTool;
use App\Mcp\Tools\DescribeSchemaTool;
use App\Mcp\Tools\GetHealthSummaryTool;
use App\Mcp\Tools\GetMuscleMapTool;
use App\Mcp\Tools\GetTrainingLoadTool;
use App\Mcp\Tools\LogSymptomTool;
use App\Mcp\Tools\QueryHealthDataTool;
use App\Models\SymptomLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The promise the mirror has to keep: it is
 * per athlete, and the sharpest flank in this application is closed.
 *
 * That flank is free SQL. Two MCP tools let a language model write its own
 * statement, and a statement may always name a schema in full, so the
 * question these tests ask is not whether search_path points somewhere
 * else. It is whether Postgres refuses. Every denial below has to come
 * from the server, and the assertions say so by matching on its wording
 * rather than on the application's.
 */
class MirrorTenancyTest extends TestCase
{
    use RefreshDatabase;

    private User $a;

    private User $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = User::factory()->admin()->create();
        $this->b = User::factory()->create();

        // One day each, told apart by their step count: whichever number
        // comes back names the athlete it came from.
        $this->seedMirror('days', [[
            'date' => now()->subDay()->toDateString(),
            'steps' => 11111,
            'fetched_at' => now()->format('Y-m-d\TH:i:s'),
        ]], $this->a);

        $this->seedMirror('days', [[
            'date' => now()->subDay()->toDateString(),
            'steps' => 22222,
            'fetched_at' => now()->format('Y-m-d\TH:i:s'),
        ]], $this->b);
    }

    public function test_each_athlete_has_a_mirror_schema_of_their_own(): void
    {
        $this->assertSame('garmin_t'.$this->a->id, Mirror::schema($this->a->id));
        $this->assertNotSame(Mirror::schema($this->a->id), Mirror::schema($this->b->id));

        foreach ([$this->a, $this->b] as $user) {
            $this->assertNotNull(
                DB::selectOne('select 1 as found from pg_namespace where nspname = ?', [Mirror::schema($user->id)]),
                'no mirror schema was provisioned for user '.$user->id
            );
        }
    }

    public function test_the_same_query_returns_a_different_athletes_numbers(): void
    {
        // The search_path half, and the reason no query in this repository
        // had to change: one statement, no schema named, two answers.
        $steps = fn (User $user) => GarminHealthServer::actingAs($user)
            ->tool(QueryHealthDataTool::class, ['sql' => 'select steps from days']);

        $steps($this->a)->assertSee('11111')->assertDontSee('22222');
        $steps($this->b)->assertSee('22222')->assertDontSee('11111');
    }

    public function test_a_summary_reaches_the_asking_athletes_mirror(): void
    {
        // Not free SQL but the app's own queries, which name no schema
        // either. A is connected as far as the mirror is concerned, B
        // has a mirror of their own and no fetch in it.
        $this->seedMirror('fetch_log', [[
            'date' => now()->toDateString(),
            'kind' => 'daily',
            'ok' => 1,
            'fetched_at' => now()->format('Y-m-d\TH:i:s'),
        ]], $this->a);

        GarminHealthServer::actingAs($this->a)
            ->tool(GetHealthSummaryTool::class, ['days' => 7])
            ->assertSee('"last_fetch"');

        GarminHealthServer::actingAs($this->b)
            ->tool(GetHealthSummaryTool::class, ['days' => 7])
            ->assertSee('"last_fetch":null');
    }

    public function test_naming_the_other_mirrors_schema_is_denied_by_postgres(): void
    {
        // The attack the plan names: B writes the SQL, and writes it in
        // full rather than relying on the search_path they were given.
        $response = GarminHealthServer::actingAs($this->b)->tool(QueryHealthDataTool::class, [
            'sql' => 'select steps from '.Mirror::schema($this->a->id).'.days',
        ]);

        $response->assertSee('permission denied')->assertDontSee('11111');
    }

    public function test_the_other_athletes_garmin_session_is_denied_too(): void
    {
        // The tokens are one schema over from every mirror and belong to
        // nobody's reader. Worth its own case: they are full access to a
        // Garmin account, which is more than the health data they guard.
        GarminHealthServer::actingAs($this->b)
            ->tool(QueryHealthDataTool::class, ['sql' => 'select tokens from garmin_private.garmin_session'])
            ->assertSee('permission denied');
    }

    public function test_the_application_tables_stay_out_of_reach(): void
    {
        GarminHealthServer::actingAs($this->b)
            ->tool(QueryHealthDataTool::class, ['sql' => 'select email from public.users'])
            ->assertSee('permission denied');
    }

    public function test_describe_schema_shows_one_mirror_and_it_is_the_askers(): void
    {
        // Both mirrors hold a table named days, so the tables cannot tell
        // them apart. What can is the row count the description carries:
        // asked by B it must count B's day, not A's.
        GarminHealthServer::actingAs($this->b)
            ->tool(DescribeSchemaTool::class, [])
            ->assertSee('CREATE TABLE days')
            ->assertDontSee(Mirror::schema($this->a->id));
    }

    public function test_a_connection_that_names_no_tenant_reaches_nothing(): void
    {
        // The fail-closed half. This is the mirror connection as it comes
        // out of the configuration, before App\Garmin\Mirror has pointed it
        // anywhere, which is the state a forgotten bootstrap leaves it in.
        DB::purge('garmin');
        Mirror::forget();

        $this->expectExceptionMessageMatches('/relation "days" does not exist/');

        DB::connection('garmin')->select('select * from days');
    }

    public function test_the_derived_models_are_derived_from_the_asking_athletes_mirror(): void
    {
        // The two tools that do not read the mirror directly: a muscle map
        // and a load model are computed over an athlete's activities by
        // several classes in turn, none of which takes a user. They are
        // right for the same reason the plain queries are: every one of
        // them reaches the mirror through App\Garmin\Mirror, and that is
        // worth an assertion rather than an assumption.
        $this->seedMirror('activities', [[
            'id' => 9101,
            'date' => now()->subDay()->toDateString(),
            'start_local' => now()->subDay()->format('Y-m-d').'T18:00:00.0',
            'type_key' => 'strength_training',
            'duration_s' => 3600.0,
            'training_load' => 240.0,
        ]], $this->a);

        // A trained yesterday, B has an empty mirror. Both ask the same
        // two tools and must get their own answer.
        GarminHealthServer::actingAs($this->a)
            ->tool(GetTrainingLoadTool::class, [])
            ->assertSee('240');

        GarminHealthServer::actingAs($this->b)
            ->tool(GetTrainingLoadTool::class, [])
            ->assertDontSee('240');

        GarminHealthServer::actingAs($this->b)
            ->tool(GetMuscleMapTool::class, [])
            ->assertDontSee('9101');
    }

    public function test_a_symptom_belongs_to_the_athlete_who_logged_it(): void
    {
        // Not the mirror but the application's own tables, which carry a
        // user_id. Checked here because the MCP tools are the one door
        // where the asking athlete is a token rather than a session, and
        // every tool walks through it.
        $logged = GarminHealthServer::actingAs($this->a)
            ->tool(LogSymptomTool::class, ['symptom' => 'sore throat', 'severity' => 2]);

        $logged->assertOk();

        $id = SymptomLog::query()->sole()->id;

        // B may neither see it nor reach it by naming its id.
        GarminHealthServer::actingAs($this->b)
            ->tool(DeleteSymptomTool::class, ['id' => $id])
            ->assertHasErrors();

        $this->assertDatabaseHas('symptom_log', ['id' => $id, 'user_id' => $this->a->id]);
    }

    public function test_free_sql_refuses_when_the_installation_cannot_confine_it(): void
    {
        // An installation whose per-tenant roles are missing would run
        // model-written SQL with the privileges of the login role, which on
        // a single-connection-string platform is every athlete at once. The
        // tool has to notice rather than serve the query.
        //
        // Taking the role away from a process that has already seen it is
        // how that state is reached without pretending to be a different
        // installation: this is a long-running worker whose roles were
        // dropped underneath it, and it is the reason the check asks the
        // server on every call instead of once.
        Mirror::forTenant($this->b->id);
        Mirror::unpin();
        DB::connection('garmin')->statement('drop owned by '.Mirror::reader($this->b->id));
        DB::connection('garmin')->statement('drop role '.Mirror::reader($this->b->id));

        GarminHealthServer::actingAs($this->b)
            ->tool(QueryHealthDataTool::class, ['sql' => 'select steps from days'])
            ->assertHasErrors();

        // Left as it was found: the role belongs to the database, which no
        // transaction rolls back for us.
        Mirror::grant($this->b->id);
    }
}
