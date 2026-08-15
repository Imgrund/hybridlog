<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Models\ConnectorSettings;
use App\Models\User;
use App\Tenancy\ActingUser;
use Laravel\Mcp\Response;

trait ChecksConnectorPermissions
{
    /**
     * The user this tool call acts for. Fails closed when there is none:
     * a tool must never read or write anybody's data by default.
     */
    protected function actingUser(): User
    {
        return ActingUser::require();
    }

    protected function settings(): ConnectorSettings
    {
        return ConnectorSettings::for($this->actingUser());
    }

    /**
     * Returns an error response when the feature is switched off, null otherwise.
     *
     * $field is the settings column, not the wording: the refusal names the
     * switch exactly as /connect labels it, so the athlete can go and find
     * the thing the AI is complaining about.
     */
    protected function denyUnless(bool $allowed, string $field): ?Response
    {
        if ($allowed) {
            return null;
        }

        return Response::error(__(
            'The user has disabled ":feature" for AI connectors. It can be re-enabled on the dashboard under /connect (:section).',
            ['feature' => ConnectorSettings::label($field), 'section' => __('Data and control')]
        ));
    }
}
