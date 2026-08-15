<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Demo\DemoMode;
use App\Garmin\Mirror;
use App\Models\AthleteProfile;
use App\Models\ConnectorGuideline;
use App\Models\McpToolCall;
use App\Models\PushSubscription;
use App\Models\SymptomLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Puts the public demo back the way it ships.
 *
 * A demo is a room a hundred strangers walk through in a day. They log
 * symptoms, switch the language, leave a guideline in the chat, and the
 * next visitor should find none of it: partly so the shop window keeps
 * showing the same thing, mostly so nobody's afternoon is spent reading
 * what somebody else typed into a shared account.
 *
 * Idempotent by construction. Everything here is a delete, an upsert or
 * a truncating seed, so running it twice is running it once, and running
 * it on an installation that has never been reset is the same as running
 * it on one that is reset nightly. That matters because the scheduler
 * runs it unattended (routes/console.php) and nobody reads its log.
 *
 * It refuses to run where DEMO_MODE is off, because everything it does
 * is destructive and the one place it must never be pointed at is a real
 * athlete's dashboard: the password reset alone would lock them out of
 * their own installation. --force is for the operator setting a demo up
 * before the switch is thrown, and for nobody else.
 */
class DemoResetCommand extends Command
{
    protected $signature = 'demo:reset
        {--force : Run even where DEMO_MODE is off, which is how a demo is first set up}';

    protected $description = 'Reset the public demo: the shared account, no visitor traces, a freshly seeded mirror';

    public function handle(): int
    {
        if (! DemoMode::enabled() && ! $this->option('force')) {
            $this->components->error(
                'DEMO_MODE is off, so this installation is not a demo and this command would delete real data. '
                .'Set DEMO_MODE=true, or pass --force if you are setting a demo up.'
            );

            return self::FAILURE;
        }

        $user = $this->theSharedAccount();
        $cleared = $this->clearWhatVisitorsLeave($user);
        $seeded = $this->refillTheMirror($user);

        $this->report($user, $cleared, $seeded);

        return $seeded ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The account everybody signs in to, created or put back.
     *
     * The password is written on every run rather than only on creation:
     * that is the whole point of a scheduled reset, and it costs one
     * hash a night. Marked as the installation owner because a demo has
     * exactly one athlete, and the owner is who the local MCP transport
     * and a bare `garmin:fetch` mean.
     */
    private function theSharedAccount(): User
    {
        $email = (string) config('demo.account.email');

        $user = User::firstOrNew(['email' => $email]);
        $created = ! $user->exists;

        if ($created) {
            $user->name = Str::before($email, '@');
        }

        $user->is_admin = true;
        $user->password = Hash::make((string) config('demo.account.password'));
        $user->save();

        // Their schema, if this is the first run. Lazily provisioned
        // elsewhere, but the seed below writes into it through the
        // fetcher rather than through the app, so it has to exist first.
        if ($created) {
            try {
                Mirror::ensure($user->id);
            } catch (Throwable $exception) {
                $this->components->warn('The demo account exists, but its mirror could not be created: '.$exception->getMessage());
            }
        }

        // The interface language goes back to the installation's own, so
        // a visitor who switched to German does not hand the next one a
        // dashboard they cannot read.
        AthleteProfile::for($user)->update(['locale' => null]);

        return $user;
    }

    /**
     * Everything a visitor can leave behind, gone.
     *
     * Only this account's rows, even though a demo has only one: the
     * command is a delete loop pointed at a database, and scoping it is
     * what keeps a second account (an operator's own, on the same
     * installation) out of its way.
     *
     * @return array<string, int> what was cleared, by name, for the report
     */
    private function clearWhatVisitorsLeave(User $user): array
    {
        // Both token kinds and the codes that mint them, then the clients
        // themselves: on a demo every client row is a stranger's chat app
        // that registered itself, and none of them may outlive the night.
        // Refresh tokens carry no user of their own, so they are reached
        // through the access tokens they belong to.
        $tokens = DB::table('oauth_access_tokens')->where('user_id', $user->id);
        $refresh = DB::table('oauth_refresh_tokens')
            ->whereIn('access_token_id', $tokens->clone()->select('id'))
            ->delete();
        $access = $tokens->clone()->delete();
        DB::table('oauth_auth_codes')->where('user_id', $user->id)->delete();
        $clients = DB::table('oauth_clients')->delete();

        return [
            'symptom entries' => SymptomLog::query()->for($user)->delete(),
            'connector guidelines' => ConnectorGuideline::query()->for($user)->delete(),
            'MCP call log' => McpToolCall::query()->for($user)->delete(),
            'push subscriptions' => PushSubscription::query()->where('user_id', $user->id)->delete(),
            'OAuth tokens' => $access + $refresh,
            'OAuth clients' => $clients,
        ];
    }

    /**
     * Runs fetcher/seed_demo.py over this account's mirror.
     *
     * Through the same interpreter as the fetch, one script over, which
     * is how the Garmin sign-in finds fetcher/login.py too: an
     * installation that has configured how Python runs has configured
     * this as well. --force because the mirror it is replacing is the
     * previous demo's, and the seed's own refusal is there to protect
     * real data, which a demo by definition has none of.
     */
    private function refillTheMirror(User $user): bool
    {
        $command = $this->seedCommand();

        if ($command === '') {
            $this->components->error('No seed command configured; set GARMIN_FETCH_COMMAND, or DEMO_SEED_COMMAND for the seed alone.');

            return false;
        }

        // A shell string rather than an argument list, for the same
        // reason FetchGarminCommand uses one: the command is configured
        // as "python3 /app/fetcher/seed_demo.py" and cannot be split
        // into program and arguments without guessing. The only value
        // that reaches it is a user id from the database.
        $result = Process::path(base_path())
            ->timeout((int) config('garmin.fetch.timeout'))
            ->run(
                $command.' --tenant='.$user->id.' --force',
                fn (string $type, string $output) => $this->output->write($output),
            );

        if (! $result->successful()) {
            $this->components->error('The demo seed failed; the mirror is whatever the seed left behind.');

            return false;
        }

        return true;
    }

    /** How to run the seed: configured, or derived from the fetch command. */
    private function seedCommand(): string
    {
        $configured = trim((string) config('demo.seed_command'));

        if ($configured !== '') {
            return $configured;
        }

        return trim(str_replace('fetch.py', 'seed_demo.py', (string) config('garmin.fetch.command')));
    }

    /**
     * What this run did, in the few lines an operator scrolling a cron
     * log will actually read.
     *
     * @param  array<string, int>  $cleared
     */
    private function report(User $user, array $cleared, bool $seeded): void
    {
        $this->components->info('Demo account '.$user->email.' reset (password from DEMO_PASSWORD).');

        $gone = array_filter($cleared);
        $this->components->info($gone === []
            ? 'Nothing left behind since the last reset.'
            : 'Cleared: '.implode(', ', array_map(
                fn (string $what, int $count): string => $count.' '.$what,
                array_keys($gone), $gone
            )).'.');

        if ($seeded) {
            $this->components->info('Mirror '.Mirror::schema($user->id).' reseeded from fetcher/seed_demo.py.');
        }
    }
}
