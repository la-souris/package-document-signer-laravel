<?php

declare(strict_types=1);

use LaSouris\DocumentSigner\DocuSign\DocuSignProvider;
use LaSouris\DocumentSigner\ValidSign\ValidSignProvider;

return [

    /*
    |--------------------------------------------------------------------------
    | Default provider
    |--------------------------------------------------------------------------
    |
    | Which provider `DocumentSigner::send()` resolves to when no explicit
    | `driver(...)` call is made. The value is a provider's short name — the
    | `NAME` constant on its class (`validsign`, `docusign`, ...).
    |
    | When left `null` (the default), the manager auto-selects the sole
    | configured provider — the one whose primary credential is set. If
    | multiple providers are configured, the manager throws on the first
    | implicit `send()` call and asks you to set `DOCUMENT_SIGNER_DRIVER`
    | explicitly.
    |
    */

    'default' => env('DOCUMENT_SIGNER_DRIVER'),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Each entry wires one signature provider. Everything a provider needs
    | lives in a single place:
    |
    |  - `class`   the SignatureProvider implementation. Its `NAME` constant
    |              is the short name used by `default`, `driver('...')` and the
    |              webhook route (`/{prefix}/{name}`).
    |  - `config`  the credentials/options passed to the provider.
    |  - `webhook` the shared secret that enables (and verifies) its webhook.
    |
    | To add an app-owned provider, append an entry pointing `class` at your
    | own SignatureProvider — no code change here is required. It is built
    | through the container, so its dependencies auto-wire and the
    | integration-managed PdfRenderer is injected as `$pdfRenderer`.
    |
    | Referencing a provider class here is safe even when its package is not
    | installed: `::class` is a compile-time string and never loads the class.
    | The manager surfaces a `composer require ...` hint if you select one
    | whose package is missing.
    |
    */

    'providers' => [

        [
            'class'  => ValidSignProvider::class,
            'config' => [
                'api_key'           => env('VALIDSIGN_API_KEY'),
                'base_url'          => env('VALIDSIGN_BASE_URL', 'https://my.validsign.nl/api'),
                'default_language'  => env('VALIDSIGN_DEFAULT_LANGUAGE', 'nl'),
                'timeout'           => (int) env('VALIDSIGN_TIMEOUT', 15),
                'upload_timeout'    => (int) env('VALIDSIGN_UPLOAD_TIMEOUT', 60),
            ],
            'webhook' => [
                'callback_secret' => env('VALIDSIGN_CALLBACK_SECRET'),
            ],
        ],

        [
            'class'  => DocuSignProvider::class,
            'config' => [
                'integration_key'    => env('DOCUSIGN_INTEGRATION_KEY'),
                'user_id'            => env('DOCUSIGN_USER_ID'),
                'account_id'         => env('DOCUSIGN_ACCOUNT_ID'),

                // Provide one of these. `private_key_path` takes precedence when both are set.
                'private_key'        => env('DOCUSIGN_PRIVATE_KEY'),
                'private_key_path'   => env('DOCUSIGN_PRIVATE_KEY_PATH'),

                'oauth_base_url'     => env('DOCUSIGN_OAUTH_BASE_URL', 'account-d.docusign.com'),
                'api_base_url'       => env('DOCUSIGN_API_BASE_URL', 'https://demo.docusign.net/restapi'),
                'scopes'             => env('DOCUSIGN_SCOPES', 'signature impersonation'),

                'access_token_ttl'   => (int) env('DOCUSIGN_ACCESS_TOKEN_TTL', 3600),
                'timeout'            => (int) env('DOCUSIGN_TIMEOUT', 15),
                'upload_timeout'     => (int) env('DOCUSIGN_UPLOAD_TIMEOUT', 60),

                // Vertical fine-tune (pixels) for signature/field placement. The
                // driver already lands each field's top edge on its placeholder;
                // set this only if a whole document still sits a little high (use a
                // positive value to nudge every field DOWN) or low (negative).
                'anchor_y_offset_pixels' => (int) env('DOCUSIGN_ANCHOR_Y_OFFSET_PIXELS', 0),
            ],
            'webhook' => [
                'hmac_secret' => env('DOCUSIGN_CONNECT_HMAC_SECRET'),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook routing
    |--------------------------------------------------------------------------
    |
    | The service provider auto-registers a webhook route for every provider
    | whose `webhook` secret is set. Setting a secret is what enables the
    | webhook — there's no separate on/off flag, because a webhook with no
    | secret would 401 every request anyway. Set:
    |  - `DOCUSIGN_CONNECT_HMAC_SECRET` to enable the DocuSign webhook.
    |  - `VALIDSIGN_CALLBACK_SECRET` to enable the ValidSign webhook.
    |
    | If no provider has a secret set, no webhook routes are registered at all
    | — useful when callbacks are handled by a separate service (queue worker,
    | edge function, external ingest) or in local development.
    |
    | Both providers use a shared-secret model:
    |  - DocuSign Connect signs the request body with HMAC-SHA256 and sends
    |    the base64 result in `X-DocuSign-Signature-1`.
    |  - ValidSign callbacks send the configured secret in the
    |    `Authorization: Basic <credentials>` header. The verifier accepts
    |    `base64("username:secret")`, `base64(secret)`, or the raw string
    |    after `Basic `, to cover the encodings different tenants use.
    |
    | Unverified requests are rejected with HTTP 401.
    |
    */

    'routing' => [
        'prefix'     => env('DOCUMENT_SIGNER_WEBHOOK_PREFIX', 'document-signer/webhooks'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF renderer
    |--------------------------------------------------------------------------
    |
    | Selects which PdfRenderer the manager wires into every provider.
    |
    |  - `browsershot` (default) uses the SDK's BrowsershotPdfRenderer; needs
    |    Node.js + Puppeteer.
    |  - `laravel-pdf` uses spatie/laravel-pdf, which itself wraps Browsershot
    |    but respects any laravel-pdf bindings/macros your application has
    |    configured. Requires `composer require spatie/laravel-pdf`.
    |
    | To fully replace the renderer (different engine, custom configuration),
    | bind your own implementation in a service provider — the manager picks
    | up the container binding first and ignores this config value when found:
    |
    |   $this->app->bind(
    |       \LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer::class,
    |       MyPdfRenderer::class,
    |   );
    |
    */

    'pdf' => [
        'renderer' => env('DOCUMENT_SIGNER_PDF_RENDERER', 'browsershot'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recipient override (dev / staging)
    |--------------------------------------------------------------------------
    |
    | Rewrites every signer's email address before an envelope is sent. Seeded
    | and development data is full of reserved domains (`example.com`, `*.test`,
    | ...) that providers like ValidSign reject outright. Turning this on in
    | non-production redirects those envelopes to a real inbox you control
    | instead of failing at the provider — real customer mail is never sent.
    |
    | KEEP `enabled` FALSE IN PRODUCTION. When disabled there is zero overhead:
    | the real provider is used directly and no wrapper is installed.
    |
    | `strategy` — how each address is rewritten (you pick what fits your setup):
    |
    |   'catch_all' (default)
    |       Everyone goes to `to`, with the original address folded into a
    |       `+tag` so each signer stays a distinct recipient (ValidSign rejects
    |       duplicate recipients on one package) while all mail lands in one box.
    |         alice@example.com -> dev+alice=example.com@you.test
    |
    |   'domain'
    |       Keep the local part, swap only the domain for `domain`. Recipients
    |       stay unique; needs a catch-all inbox on that domain to receive them.
    |         alice@example.com -> alice@you.test
    |
    |   'redirect'
    |       Send everyone to `to` verbatim. Simplest, but multiple signers on one
    |       envelope collapse to the same address (a provider may reject that).
    |         alice@example.com -> dev@you.test
    |
    | `only_domains` — optional scope narrowing. Empty (the default) rewrites
    | EVERY signer address once the override is enabled — the `enabled` flag
    | above is the only guard, so keep it off in production. Add entries only
    | when you want to restrict the rewrite to specific domains and let all
    | others pass through untouched (e.g. redirect just seeded `*.test` data
    | while real addresses go out for real):
    |
    |   'only_domains' => ['example.com', '*.test', '*.local'],
    |
    | Entries match case-insensitively; a `*.` prefix does a suffix match
    | (`*.test` matches `foo.test`).
    |
    */

    'recipient_override' => [
        'enabled'  => env('DOCUMENT_SIGNER_OVERRIDE_ENABLED', false),
        'strategy' => env('DOCUMENT_SIGNER_OVERRIDE_STRATEGY', 'catch_all'),
        'to'       => env('DOCUMENT_SIGNER_OVERRIDE_TO'),
        'domain'   => env('DOCUMENT_SIGNER_OVERRIDE_DOMAIN'),
        'only_domains' => [],
    ],

];
