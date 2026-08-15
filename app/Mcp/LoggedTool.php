<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Models\McpToolCall;
use App\Tenancy\ActingUser;
use Illuminate\Container\Container;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Content\Text;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Base class for this app's MCP tools; records every invocation in mcp_tool_calls.
 *
 * Subclasses implement `execute(Request $request, ...$dependencies): Response`
 * instead of handle(). It is deliberately not declared abstract here: PHP forbids
 * adding required parameters when overriding, which would kill the method
 * injection several tools rely on (ReadOnlyGarminQuery, GarminData). The call
 * goes through the container, so those keep resolving exactly as before.
 *
 * Nothing here may break a tool call: recording runs in its own try/catch, and a
 * failing log write is swallowed. A dashboard that stops answering because its
 * telemetry broke would be worse than no telemetry.
 */
abstract class LoggedTool extends Tool
{
    /** Argument keys that are never written to the log, per tool. */
    protected array $redact = [];

    public function handle(Request $request): Response
    {
        $startedAt = hrtime(true);

        // Fail closed before the tool runs: without a resolvable tenant
        // there is nobody whose data this would be. One choke point for
        // every tool, so no tool can forget it, and an error the
        // model can read rather than an exception it cannot.
        if (ActingUser::get() === null) {
            // The log line stays English and stable, whatever language
            // the refusal is read in: it is grepped, not read aloud.
            $this->record($request, $startedAt, false, 'no tenant resolved for this call');

            return Response::error(__('This connector is not tied to a dashboard account.'));
        }

        try {
            /** @var Response $response */
            $response = Container::getInstance()->call([$this, 'execute'], ['request' => $request]);
        } catch (Throwable $e) {
            $this->record($request, $startedAt, false, $this->describe($e));

            throw $e;
        }

        // A tool can also fail by returning Response::error(), e.g. a disabled
        // connector permission or a rejected SQL query. That is a failed call too.
        $this->record(
            $request,
            $startedAt,
            ! $response->isError(),
            $response->isError() ? $this->responseText($response) : null,
        );

        return $response;
    }

    protected function record(Request $request, float $startedAt, bool $ok, ?string $error): void
    {
        try {
            McpToolCall::create([
                // Whose telemetry this is. Null for a call that resolved
                // no tenant, and that row is deliberately kept: a
                // connector reaching this installation without an
                // account behind it is the call worth reading later.
                'user_id' => ActingUser::get()?->id,
                'tool' => $this->name(),
                'arguments' => $this->arguments($request),
                'transport' => app()->runningInConsole() ? 'stdio' : 'web',
                'client' => $this->client($request),
                'session_id' => $request->sessionId(),
                'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'ok' => $ok,
                'error' => $error === null ? null : mb_substr($error, 0, 1000),
            ]);
        } catch (Throwable) {
            // Telemetry is never worth a failed tool call.
        }
    }

    /** @return array<string, mixed> */
    protected function arguments(Request $request): array
    {
        $arguments = $request->all();

        foreach ($this->redact as $key) {
            if (array_key_exists($key, $arguments)) {
                $arguments[$key] = '[redacted]';
            }
        }

        return array_map(
            fn (mixed $value): mixed => is_string($value) ? mb_substr($value, 0, 2000) : $value,
            $arguments,
        );
    }

    /** Name of the OAuth client behind a connector request, if there is one. */
    protected function client(Request $request): ?string
    {
        try {
            $token = $request->user('api')?->token();

            return $token?->client?->name;
        } catch (Throwable) {
            return null;
        }
    }

    protected function describe(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return 'validation: '.implode(' ', $e->validator->errors()->all());
        }

        return class_basename($e).': '.$e->getMessage();
    }

    protected function responseText(Response $response): string
    {
        try {
            $content = $response->content();

            // Error responses always carry Text; anything else is not worth decoding.
            return $content instanceof Text
                ? (string) $content
                : 'tool returned a non-text error response';
        } catch (Throwable) {
            return 'tool returned an error response';
        }
    }
}
