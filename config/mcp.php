<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | Dynamic client registration is open to the internet (RFC 7591 has no
    | auth), so this list is what keeps a stranger from registering a client
    | that redirects an authorization code to a host of their choosing. The
    | package default is '*'; on a public host that must not survive.
    |
    | The entries mirror the clients this installation actually serves:
    | claude.ai (hosted connector, callback /api/mcp/auth_callback), its
    | claude.com successor, ChatGPT's connector platform, Langdock (one
    | callback per integration under /api/integrations/<id>/callback, which
    | is why the host is pinned and not a single path), and the loopback
    | entry, which the package expands to localhost, 127.0.0.1 and [::1] on
    | any port, which is how Claude Code, Claude Desktop and LM Studio catch
    | their callback (RFC 8252 native flow; LM Studio's is fixed at
    | 127.0.0.1:33389).
    |
    | A client that is not listed cannot register, and the refusal is a 400
    | with error=invalid_redirect_uri. Adding one means adding its callback
    | host here, nothing else.
    |
    */

    'redirect_domains' => [
        'https://claude.ai',
        'https://claude.com',
        'https://chatgpt.com',
        'https://app.langdock.com',
        'http://localhost',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Private-use URI schemes (RFC 8252) for native clients. Every client
    | this installation serves uses loopback HTTP instead, so none are
    | allowed until one actually shows up needing it.
    |
    */

    'custom_schemes' => [],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Issuer identifier per RFC 8414. Null means url('/'), which is correct
    | here: the app is the authorization server for its own MCP endpoint.
    |
    */

    'authorization_server' => null,

];
