<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Names the callback host a refused client registration asked for.
 *
 * The allowlist in config/mcp.php only grows when a new chat client shows
 * up, and the client itself shows nothing but "not a permitted redirect
 * domain". Without this line the host it wanted has to be guessed. Only
 * scheme and host are logged: the path can carry a per-integration id,
 * and the query string is the client's own business.
 */
class LogRefusedRedirect
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse
            || $response->getStatusCode() !== 400
            || ($response->getData(true)['error'] ?? null) !== 'invalid_redirect_uri') {
            return $response;
        }

        $hosts = collect((array) $request->input('redirect_uris', []))
            ->filter(fn ($uri): bool => is_string($uri))
            ->map(fn (string $uri): ?string => ($scheme = parse_url($uri, PHP_URL_SCHEME)) && ($host = parse_url($uri, PHP_URL_HOST))
                ? "{$scheme}://{$host}"
                : null)
            ->filter()
            ->unique()
            ->take(10)
            ->values()
            ->all();

        Log::warning('OAuth client registration refused a redirect host', [
            'client_name' => is_string($name = $request->input('client_name')) ? mb_substr($name, 0, 100) : null,
            'redirect_hosts' => $hosts,
        ]);

        return $response;
    }
}
