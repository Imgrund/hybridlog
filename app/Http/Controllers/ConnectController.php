<?php

namespace App\Http\Controllers;

use App\Garmin\GarminData;
use App\Mcp\ConnectorAddress;
use App\Models\ConnectorGuideline;
use App\Models\ConnectorSettings;
use App\Models\McpToolCall;
use App\View\Dashboard\SurfacePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The AI connector page: what the assistant may read and do, and the
 * disconnect that revokes it.
 */
class ConnectController extends Controller
{
    public function __construct(private GarminData $garmin) {}

    public function connect(Request $request): View
    {
        $user = $request->user();
        $mcpUrl = ConnectorAddress::url();

        return view('connect', [
            'lastFetch' => $this->garmin->latestFetch(),
            'settings' => ConnectorSettings::for($user),
            'guidelines' => ConnectorGuideline::for($user)->active()->orderBy('id')->get(),
            'mcpUrl' => $mcpUrl,
            'claudeAddUrl' => ConnectorAddress::claudeAddUrl($mcpUrl),
            'connected' => SurfacePage::aiConnected($user),
            'lastUsed' => McpToolCall::for($user)->where('transport', 'web')->max('created_at'),
        ]);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        // Revoking both token kinds is the whole disconnect: the
        // connector cannot call (access) and cannot come back on its
        // own (refresh). Only this user's tokens: a disconnect is a
        // personal decision, not an installation-wide kill switch. The
        // OAuth client rows stay; reconnecting re-registers or reuses
        // them. Refresh tokens carry no user_id of their own, so they
        // are reached through the access tokens they belong to.
        $tokens = DB::table('oauth_access_tokens')->where('user_id', $request->user()->id);

        DB::table('oauth_refresh_tokens')
            ->whereIn('access_token_id', $tokens->clone()->select('id'))
            ->update(['revoked' => true]);
        $tokens->update(['revoked' => true]);

        return redirect()->route('connect')->with('disconnected', true);
    }

    public function updatePermissions(Request $request): RedirectResponse
    {
        ConnectorSettings::for($request->user())->update([
            'share_health_data' => $request->boolean('share_health_data'),
            'share_body_metrics' => $request->boolean('share_body_metrics'),
            'allow_symptoms' => $request->boolean('allow_symptoms'),
            'allow_refresh' => $request->boolean('allow_refresh'),
            'allow_feedback' => $request->boolean('allow_feedback'),
        ]);

        return redirect()->route('connect')->with('permissions_saved', true);
    }

    public function deleteGuideline(Request $request, ConnectorGuideline $guideline): RedirectResponse
    {
        // Somebody else's guideline answers 404, not 403: its very
        // existence is nobody else's business.
        abort_unless($guideline->user_id === $request->user()->id, 404);

        // Hard delete, unlike the soft retire the chat uses: on this page
        // the user is the author of record and the row list is the archive.
        $guideline->delete();

        return redirect()->route('connect')->with('guideline_deleted', true);
    }
}
