<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\McpToolCall;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class McpUsageCommand extends Command
{
    protected $signature = 'mcp:usage {--days=30 : How far back to look} {--errors : Show the failed calls in full} {--calls : Show every call with its arguments}';

    protected $description = 'Show how the AI connectors actually use the MCP server';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        /** @var Collection<int, McpToolCall> $calls */
        $calls = McpToolCall::where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at')
            ->get();

        if ($calls->isEmpty()) {
            $this->components->warn("No MCP tool calls in the last {$days} days.");

            return self::SUCCESS;
        }

        $failed = $calls->where('ok', false);

        $this->components->twoColumnDetail('<fg=gray>Period</>', $days.' days');
        $this->components->twoColumnDetail('<fg=gray>Calls</>', (string) $calls->count());
        $this->components->twoColumnDetail(
            '<fg=gray>Failed</>',
            sprintf('%d (%.0f%%)', $failed->count(), $failed->count() / $calls->count() * 100),
        );
        // Not a conversation count: the web transport is stateless, so claude.ai
        // opens a fresh session per call. Say so rather than imply six chats.
        $sessions = $calls->pluck('session_id')->filter()->unique()->count();
        $this->components->twoColumnDetail(
            '<fg=gray>Sessions</>',
            $sessions.($sessions === $calls->count() ? ' <fg=gray>(one per call, not one per conversation)</>' : ''),
        );
        $this->components->twoColumnDetail(
            '<fg=gray>Transport</>',
            $calls->countBy('transport')->map(fn (int $n, string $t): string => "{$t} {$n}")->implode(', '),
        );

        $this->newLine();
        $this->components->info('Per tool');

        $calls->groupBy('tool')
            ->sortByDesc(fn (Collection $group): int => $group->count())
            ->each(function (Collection $group, string $tool): void {
                $errors = $group->where('ok', false)->count();
                $median = $this->median($group->pluck('duration_ms')->all());

                $this->components->twoColumnDetail(
                    $tool,
                    sprintf(
                        '%d call%s · %s · %d ms median',
                        $group->count(),
                        $group->count() === 1 ? '' : 's',
                        $errors === 0 ? '<fg=green>no errors</>' : "<fg=red>{$errors} failed</>",
                        $median,
                    ),
                );
            });

        if ($this->option('calls')) {
            $this->newLine();
            // The protocol never carries the question, so the subject has to be
            // read off the arguments the AI sent.
            $this->components->info('Every call, newest last');

            $calls->each(function (McpToolCall $call): void {
                $this->line(sprintf(
                    '<fg=gray>%s</> %s <fg=gray>%s</>',
                    $call->created_at->timezone('Europe/Berlin')->format('d.m. H:i'),
                    $call->ok ? "<fg=yellow>{$call->tool}</>" : "<fg=red>{$call->tool}</>",
                    $call->client ?? $call->transport,
                ));
                $this->line('  '.$this->summarise($call));
            });
        }

        if ($failed->isNotEmpty()) {
            $this->newLine();
            $this->components->info('What went wrong');

            $failed->countBy(fn (McpToolCall $call): string => $call->tool.' :: '.$this->shorten((string) $call->error))
                ->sortDesc()
                ->take(10)
                ->each(fn (int $n, string $key) => $this->components->twoColumnDetail($key, (string) $n));

            if ($this->option('errors')) {
                $this->newLine();
                $this->components->info('Failed calls in full');

                $failed->each(function (McpToolCall $call): void {
                    // Stored in UTC, read by a human in Berlin.
                    $at = $call->created_at->timezone('Europe/Berlin')->format('d.m. H:i');

                    $this->line("<fg=gray>{$at}</> <fg=yellow>{$call->tool}</>");
                    $this->line('  args: '.json_encode($call->arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    $this->line("  <fg=red>{$call->error}</>");
                });
            }
        }

        return self::SUCCESS;
    }

    /** @param array<int, int> $values */
    private function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? (int) round(($values[$middle - 1] + $values[$middle]) / 2)
            : $values[$middle];
    }

    /** One readable line per call: what the AI was after, not the raw payload. */
    private function summarise(McpToolCall $call): string
    {
        $arguments = $call->arguments ?? [];

        if ($arguments === []) {
            return '<fg=gray>no arguments</>';
        }

        // Titles and SQL carry the subject; everything else is plumbing.
        $lead = $arguments['title'] ?? $arguments['sql'] ?? $arguments['body'] ?? null;
        $text = is_string($lead)
            ? $lead
            : implode(', ', array_map(
                fn (mixed $v, string $k): string => $k.'='.(is_scalar($v) ? (string) $v : '…'),
                $arguments,
                array_keys($arguments),
            ));

        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > 150 ? mb_substr($text, 0, 149).'…' : $text;
    }

    private function shorten(string $error): string
    {
        // Collapse the variable parts so the same class of error groups together.
        $error = preg_replace('/\d+/', 'N', $error) ?? $error;

        return mb_substr(trim($error), 0, 80);
    }
}
