<?php

namespace App\Mcp;

/**
 * The one address this installation hands to an AI, and the shortcut
 * that fills it into Claude's dialog.
 *
 * Two pages need both: /connect, where a signed-in athlete manages the
 * connection, and the public setup page, which walks somebody through
 * it before they have an account. Deriving them twice is how the two
 * would eventually disagree.
 */
class ConnectorAddress
{
    /** The hosted MCP endpoint, as this request reaches it. */
    public static function url(): string
    {
        return url('/mcp/garmin');
    }

    /**
     * A link that opens claude.ai with the add-connector dialog already
     * filled in, or null where such a link would only disappoint.
     *
     * Anthropic documents the parameters; almost nobody uses them, which
     * is why the step is usually three instructions and a copied string.
     * It only prefills, so the athlete still reads the address and
     * approves the grant: the same decision, minus the typing.
     *
     * Null for an address claude.ai cannot reach. A laptop's `localhost`
     * is the address of the machine claude.ai runs its fetch from, not of
     * this dashboard, so the button would hand over a dialog that fails
     * on submit. Better to show the address and let the reader see for
     * themselves that it is a local one.
     */
    public static function claudeAddUrl(?string $mcpUrl = null): ?string
    {
        $mcpUrl ??= self::url();
        $host = parse_url($mcpUrl, PHP_URL_HOST) ?: '';

        if ($host === 'localhost' || $host === '::1' || str_starts_with($host, '127.')) {
            return null;
        }

        // The name the athlete will see in their connector list, so it
        // is the one on every other surface. Not config('app.name'):
        // that still reads "Garmin Dashboard" from before this was
        // called hybridlog, and nothing shows it to anybody.
        return 'https://claude.ai/customize/connectors?'.http_build_query([
            'modal' => 'add-custom-connector',
            'connectorName' => 'hybridlog',
            'connectorUrl' => $mcpUrl,
        ]);
    }
}
