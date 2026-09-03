<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Prompts\WeeklyReportPrompt;
use App\Mcp\Tools\DeleteSymptomTool;
use App\Mcp\Tools\DescribeSchemaTool;
use App\Mcp\Tools\GetActivityDetailTool;
use App\Mcp\Tools\GetHealthSummaryTool;
use App\Mcp\Tools\GetInsightsTool;
use App\Mcp\Tools\GetMuscleMapTool;
use App\Mcp\Tools\GetRaceSplitsTool;
use App\Mcp\Tools\GetStrengthProgressTool;
use App\Mcp\Tools\GetTrainingLoadTool;
use App\Mcp\Tools\GiveFeedbackTool;
use App\Mcp\Tools\LogSymptomTool;
use App\Mcp\Tools\QueryHealthDataTool;
use App\Mcp\Tools\RefreshDataTool;
use App\Models\ConnectorGuideline;
use App\Tenancy\ActingUser;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\ServerContext;
use Throwable;

#[Name('Garmin Health')]
#[Version('1.0.0')]
class GarminHealthServer extends Server
{
    /**
     * The static half of the instructions. Deliberately a constant and not
     * the #[Instructions] attribute: the attribute would win over the
     * property, and the property is where createContext() appends the
     * guidelines the athlete taught the connector via give-feedback-tool.
     */
    private const BASE_INSTRUCTIONS = 'Personal training and recovery dashboard of one athlete per connection: whoever '.
        'connected this chat is the athlete every answer is about. Their sport focus and goals are '.
        'not fixed here; they show in the data itself and in the standing guidelines appended '.
        'below once the athlete has taught the connector any. Data source is a PostgreSQL mirror '.
        'of Garmin Connect (~90+ days of history, growing daily). The dashboard shows the body '.
        "map and the training load; every analysis beyond that lives in this chat.\n\n".
        'Domain notes: activity type_key `hiit` is whatever the athlete does at high intensity in '.
        'circuits, a CrossFit WOD as often as anything else; `running`, `walking`, `pilates`, '.
        '`strength_training` as expected. Garmin logs almost no per-set weights for circuit work, so '.
        'strength volume must be reasoned via training_load, not tonnage. Weights are stored in grams '.
        "(weight_g), durations in seconds, dates as text in 'YYYY-MM-DD'.\n\n".
        'Workflow: call describe-schema-tool once per conversation before writing SQL; use '.
        'get-health-summary-tool for a quick current picture; use get-muscle-map-tool for per-muscle '.
        'freshness, weekly volume per zone and "what should I train today"; use get-training-load-tool '.
        'for CTL/ATL/TSB, the acute:chronic ratio and the weekly stimulus split. Those two return the '.
        'exact numbers the dashboard shows, so never re-derive muscle freshness or the load model in '.
        'SQL. Use get-insights-tool when the question is "how am I doing" rather than a number: it '.
        'returns the app\'s own verdict per body system with the recommendation the athlete already '.
        'read this morning, plus the early illness pattern, so never invent your own thresholds for '.
        'resting heart rate, breathing rate or HRV. Use get-strength-progress-tool for "am I getting '.
        'stronger": reps, tonnage where weights were really recorded, top weights and a stagnation '.
        'reading per exercise category. Use get-race-splits-tool for one session lap by lap: it '.
        'separates running from station work, gives pace per running lap and says how far the pace '.
        'drifted from first lap to last. A race that alternates the two is what it was built for (a '.
        'HYROX race or simulation is eight 1 km runs with a station between each), and it reads any '.
        'lapped session just as well. Use get-activity-detail-tool for one session in depth: its '.
        'heart-rate zones as minutes and shares next to the zone floors of the athlete\'s profile, '.
        'laps, sets, the heart-rate curve and how it compares with earlier sessions of the same '.
        'type, so never rebuild zones from hr_zones_json in SQL. Use query-health-data-tool (single SELECT) for '.
        'everything else. When a question falls outside every column, it is often still answerable: '.
        'the raw_payload table holds Garmin\'s untouched answer per day and endpoint as jsonb, so '.
        'reach for it rather than reporting that the mirror does not track something. Write '.
        'jsonb_exists(payload, \'key\') and never payload ? \'key\', which dies with a syntax error '.
        'about $1. When the user asks about "now" and last_fetch predates their latest '.
        'workout, call refresh-data-tool first: it waits until the sync is done and answers '.
        'still_running only when the run needs longer than one call may take, then call it again '.
        'to keep waiting. Afterwards re-query and answer with the fresh numbers; never end your '.
        "turn by asking the user to report back once data is syncing.\n\n".
        'Data trust: get-health-summary-tool returns data_status. When its state is not_connected, '.
        'auth_broken or fetch_stale, tell the user (use its hint verbatim or paraphrase it) BEFORE '.
        'reasoning over current values. not_connected means nobody has signed this installation in '.
        'to Garmin yet, auth_broken means the stored Garmin session expired; both are fixed by the '.
        'user signing in to Garmin on the dashboard, so give them the sign_in_url from data_status '.
        'as a link and say that is the fix (refresh-data-tool will refuse until then, and you can neither '.
        'sign in for them nor accept their password here). fetch_stale means '.
        'the scheduled fetch job has stopped (refresh-data-tool is the right first step). watch_stale '.
        'matters only for now-questions: mention that the watch has not uploaded to Garmin lately '.
        'and suggest syncing it via the Garmin Connect app, because a refresh alone cannot surface '.
        "what the watch never uploaded.\n\n".
        'Symptoms: log a symptom or complaint (log-symptom-tool) ONLY when the user mentions it '.
        'unprompted: capture it in passing, confirm in one sentence, move on. NEVER proactively '.
        'ask how the user feels or whether they have symptoms; complaints are volunteered, not '.
        'solicited. severity is optional (1 mild, 2 moderate, 3 severe). Corrections go through '.
        'delete-symptom-tool (ids from the log response or get-health-summary-tool, which carries the '.
        'last 3 days). On the dashboard the entries appear only as context on the illness '.
        "early-warning banner, nowhere else.\n\n".
        'Feedback: when the user gives feedback on how this connector should behave (tone, format, '.
        'defaults), confirm in one sentence what you will store, then call give-feedback-tool. It '.
        'becomes a standing guideline appended to these instructions from the next conversation '.
        'on; retire one the user takes back via retire_guideline_id (the [gN] ids below, when '.
        'present). Only store feedback the user actually voiced.';

    /**
     * Every tool on the first tools/list page. The package paginates at 15
     * by default, and a client that never follows nextCursor would see the
     * later tools simply not exist.
     */
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        DescribeSchemaTool::class,
        GetHealthSummaryTool::class,
        GetInsightsTool::class,
        GetMuscleMapTool::class,
        GetTrainingLoadTool::class,
        GetStrengthProgressTool::class,
        GetRaceSplitsTool::class,
        GetActivityDetailTool::class,
        QueryHealthDataTool::class,
        RefreshDataTool::class,
        LogSymptomTool::class,
        DeleteSymptomTool::class,
        GiveFeedbackTool::class,
    ];

    protected array $prompts = [
        WeeklyReportPrompt::class,
    ];

    /**
     * Instructions are assembled per handshake, so a guideline saved in one
     * conversation shapes the next without a deploy. They are a nice-to-have
     * on top of working tools, which is why a mirror or app database that
     * cannot answer right now costs only the guideline block, never the
     * handshake itself.
     */
    public function createContext(): ServerContext
    {
        try {
            $this->instructions = self::BASE_INSTRUCTIONS
                .ConnectorGuideline::instructionsBlock(ActingUser::get());
        } catch (Throwable) {
            $this->instructions = self::BASE_INSTRUCTIONS;
        }

        return parent::createContext();
    }
}
