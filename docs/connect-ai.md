# Connecting an AI

hybridlog is an MCP server on your own Garmin mirror, in two transports:
stdio for a client on this machine, streamable HTTP with OAuth for one
that reaches it over the network. The [README](../README.md) has the
address and the shortest path per client. This is the long version: every
client's steps, what the server exposes, and what to watch on a host that
is reachable from outside.

## Hosted: claude.ai, ChatGPT, anything that speaks HTTP

One address, the same for every client, on whatever domain you deployed to:

```
https://<your-domain>/mcp/garmin
```

Clients that take a config file instead of an address want this:

```json
{
  "mcpServers": {
    "garmin-health": {
      "type": "http",
      "url": "https://<your-domain>/mcp/garmin"
    }
  }
}
```

Signing in is OAuth against the dashboard's own login, so the connector
never sees a password of yours and you can cut it off again from
`/connect`. The first connection opens a browser window, you sign in with
the account you created above and approve; there is nothing to paste and
no token to generate.

<details>
<summary><b>Claude (web and phone app)</b></summary>

**Customize → Connectors → Add custom connector**, paste the address,
then sign in and approve. On a Team or Enterprise plan an owner adds it
once under **Organization settings → Connectors → Add → Custom**, and
everybody else then finds it under **Customize → Connectors** with a
*Custom* label.

Leave the OAuth fields under *Advanced settings* empty. They are the
thing people fill in because a form asks: this server registers the
client itself, so a value there is what breaks the connection rather
than what completes it.

`/connect` renders a one-click link that fills that dialog in for you,
because it is the one place that knows this installation's own address.
</details>

<details>
<summary><b>ChatGPT</b></summary>

Turn on **Developer mode** in the settings, add a connector with the same
address, confirm the OAuth sign-in. OpenAI has renamed this path more
than once (Connectors, Apps, Plugins), so go by the name in your own
settings rather than by ours.
</details>

<details>
<summary><b>Claude Code and Claude Desktop, against a deployed instance</b></summary>

```bash
claude mcp add --transport http garmin-health https://<your-domain>/mcp/garmin
```

Claude Desktop takes the JSON block above. Both run the same OAuth flow
as the web app.
</details>

<details>
<summary><b>Langdock</b></summary>

**Integrations → Add Integration → Start from scratch → Connect remote
MCP**, paste the address, pick **OAuth 2.0** (not *Advanced OAuth 2.0*,
which is the path for servers that cannot register a client themselves).
Then **+ Add connection**, sign in, and **Test connection** lists the
tools; select the ones you want and save.

Creating an integration is an Editor or Admin right. On a Team or
Enterprise workspace that means an admin does it once and shares it;
Langdock's callback is one URL per integration under
`app.langdock.com/api/integrations/`, which is why the allowlist pins the
host rather than a path.
</details>

<details>
<summary><b>Le Chat / Vibe (Mistral)</b></summary>

Mistral renamed Le Chat to **Vibe**. The interface says Vibe, the
documentation still says Le Chat, and both mean the same product.

**Konnektoren → + Add Connector**, then the **Custom MCP Connector** tab:
name it, paste the address, **Connect**, sign in. It reads the
authentication off the server rather than asking, and settles on OAuth
2.1 with dynamic client registration, which is what this server speaks.

Mistral Studio (`console.mistral.ai`) shows the same connectors under
**Connectors**, and a connector added in either place is connected in
both. Either route works; the Studio one additionally lists the tools it
received, which makes it the better place to check that a connection is
actually carrying something.

Adding a connector is an administrator right. On the Free, Pro and
Student plans the account owner is the administrator, so on a personal
account that is you.

Custom connectors do not carry prompt templates or resources yet, so the
`weekly-report` prompt is missing there. All twelve tools arrive, sorted
into interactive and read-only, each with a switch of Mistral's own. That
switch and the one at `/connect` are separate: either can withhold a
tool, and the server's is the one that still applies when the client's
says yes.

The callback lands on `callback.mistral.ai`, not on the `chat.mistral.ai`
in the address bar, and Mistral documents neither. The entry in
`config/mcp.php` comes from observed registrations rather than from a
published document, so if a future version is refused, read the
`redirect_uri` it actually sent instead of guessing at the other host.
</details>

<details>
<summary><b>LM Studio, against a deployed instance</b></summary>

Right sidebar → **Program** → **Install → Edit mcp.json**, then the
JSON block above with `url` and nothing else. LM Studio catches the
OAuth callback on `127.0.0.1:33389`, which the loopback entry in
`config/mcp.php` already covers.

If the browser window never opens and you get a transport error instead,
that is a known LM Studio issue with servers outside its own directory:
it can read the 401 that starts the sign-in as a connection failure. The
local transport below sidesteps it entirely.
</details>

## Local: at the repo, no deployment and no OAuth

No deployment, no OAuth, no HTTP: the server runs as a subprocess and
talks over stdin.

```bash
claude mcp add --scope user garmin-health -- php "$(pwd)/artisan" mcp:start garmin
```

Clients that take a config file want the same thing spelled out. LM
Studio is one (right sidebar → **Program** → **Install → Edit
mcp.json**):

```json
{
  "mcpServers": {
    "garmin-health": {
      "command": "/absolute/path/to/php",
      "args": ["/absolute/path/to/hybridlog/artisan", "mcp:start", "garmin"]
    }
  }
}
```

Spell the PHP binary out in full (`which php` tells you where it is)
rather than writing `php`. LM Studio works out its PATH from the
account's login shell and does not always find what a terminal finds.

This transport acts for the installation owner, always. It is one person
at a keyboard with no way to say which account they are, where a token
answers that question by itself. A running stdio server also keeps its
code in memory, so restart the client after changing a tool.

## What it can do

Twelve tools and one prompt, each gated by a switch at `/connect`: the
table is on the [README](../README.md#the-tools).

Two details that belong here rather than there. The summary and the
muscle map carry open symptoms along with their numbers, and *Log how you
feel* is what decides whether they do: switched off, the tools still work
and simply stop mentioning them. Free-form SQL refuses outright when the
connection did not switch into the athlete's read-only role, rather than
falling back on a search path.

## Running the MCP server in public

Connecting one is up at the top of this page. This is the
other half: what the server does once it is reachable from the internet,
and what it writes down about itself.

### Usage log (what the AI actually does)

`laravel/mcp` logs nothing on its own, so every tool call is recorded by
`app/Mcp/LoggedTool.php`, the base class every tool extends: tool name,
arguments, transport (stdio/web), OAuth client, session id, duration,
success, and the error message on failure. Validation errors and thrown
exceptions are captured too. Logging never breaks a call: a failing
write is swallowed.

```bash
php artisan mcp:usage --days=30      # per-tool counts, error rate, median duration
php artisan mcp:usage --calls        # every call with the subject it was after
php artisan mcp:usage --errors       # the failed calls including arguments
```

What this can and cannot answer: the MCP protocol never transmits the
user's question, only tool names and arguments. So "which tools work" is
measurable and "how was it phrased" is not: the subject can only be
reconstructed from the SQL the AI sent, which is what
`--calls` prints. The session count is not a conversation count either:
the web transport is stateless, so claude.ai opens a fresh session per
call.

Note that a running stdio server keeps its code in memory: after changing
a tool, restart the MCP client before expecting new rows.

### OAuth and discovery (hosted transport)

Both transports stand side by side and are the same server class with the
same tools: stdio for a client on the machine that holds this repo, HTTP
at `/mcp/garmin` for everything else. The hosted one is protected by
OAuth 2.1 (Laravel Passport, authorization code with PKCE `S256`, scope
`mcp:use`) against the dashboard's own login.

A client finds all of that by itself. A tokenless request gets 401 with

```
WWW-Authenticate: Bearer realm="mcp",
  resource_metadata="https://<host>/.well-known/oauth-protected-resource/mcp/garmin"
```

which is the route the MCP specification calls the reliable one, and both
the path-scoped and the root form of that document answer. Clients that
probe instead of reading the header find it either way.

Client registration is dynamic (`POST /oauth/register`, RFC 7591). Note
that the specification moved on: since revision 2025-11-25, OAuth Client
ID Metadata Documents are the SHOULD and dynamic registration is the MAY,
kept for backwards compatibility. Everything in the field still speaks
DCR, so this works today; supporting CIMD means advertising
`client_id_metadata_document_supported` and `none` in
`token_endpoint_auth_methods_supported`, and it only becomes necessary if
this server is ever submitted to a connector directory.

Hardening that comes with public exposure (all in place):

- `TrustHosts` pins the accepted Host headers, so a forged host cannot
  poison the OAuth discovery metadata. Hosts are matched as anchored
  regexes.
- `TrustProxies` honours `X-Forwarded-Proto` only from the addresses
  `TRUSTED_PROXIES` names, so OAuth redirects stay HTTPS.
- Login is rate limited (5/min per credential pair, 20/h per IP).
- `POST /oauth/register` (dynamic client registration, unauthenticated by
  spec) is capped at 10/h per IP; `laravel/mcp` ships it without
  middleware.
- Access tokens live 1 hour instead of Passport's 1-year default; the
  connector stays signed in via 30-day refresh tokens, which Passport
  rotates on every use (a replayed refresh token gets `invalid_grant`).
  Lifetimes apply to newly issued tokens, so reconnect the connector once
  after changing them.
