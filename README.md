# Laravel integration for the document signer SDK

Laravel integration for the [Document Signer SDK](https://github.com/la-souris/package-document-signer-sdk): config, service
provider, driver manager, facade, and verified webhook routes for both
ValidSign and DocuSign.

## Contents

- [Install](#install)
- [Configure](#configure)
  - [Default driver](#default-driver)
- [Sending an envelope](#sending-an-envelope)
- [Recipient override (dev / staging)](#recipient-override-dev--staging)
  - [Strategy](#strategy)
  - [Scope](#scope)
- [Blade components](#blade-components)
- [PDF renderer](#pdf-renderer)
  - [Default: install spatie/browsershot](#default-install-spatiebrowsershot)
  - [Use spatie/laravel-pdf](#use-spatielaravel-pdf)
  - [Bind a fully custom renderer](#bind-a-fully-custom-renderer)
- [Webhooks](#webhooks)
  - [Translated event labels](#translated-event-labels)
- [Custom providers](#custom-providers)
  - [Custom webhooks](#custom-webhooks)
- [Testing](#testing)
- [Requirements](#requirements)
- [Documentation](#documentation)

## Install

```bash
composer require la-souris/document-signer-laravel
```

Then add at least one provider package — the laravel package treats both as
optional:

```bash
composer require la-souris/document-signer-validsign   # for the validsign driver
composer require la-souris/document-signer-docusign    # for the docusign driver
```

Publish the config:

```bash
php artisan vendor:publish --tag=document-signer-config
```

## Configure

`config/document-signer.php` reads from `.env`. Minimum for ValidSign:

```dotenv
VALIDSIGN_API_KEY=your-base64-key
```

Minimum for DocuSign:

```dotenv
DOCUSIGN_INTEGRATION_KEY=...
DOCUSIGN_USER_ID=...
DOCUSIGN_ACCOUNT_ID=...
DOCUSIGN_PRIVATE_KEY_PATH=/path/to/private.pem
```

DocuSign fields are anchored to their `{[type:signer:name]}` placeholders and
land on the placeholder's top edge (matching ValidSign). If a whole document
still sits slightly high or low on your account, nudge every field without a
code change — positive moves fields down, negative up:

```dotenv
DOCUSIGN_ANCHOR_Y_OFFSET_PIXELS=6
```

### Default driver

`DOCUMENT_SIGNER_DRIVER` is optional. If left unset the manager auto-selects
the sole configured driver — i.e. the one whose primary credential
(`VALIDSIGN_API_KEY` or `DOCUSIGN_INTEGRATION_KEY`) is present. Set the
variable explicitly only when you configure both providers in the same app:

```dotenv
# Both configured — pick the implicit default:
DOCUMENT_SIGNER_DRIVER=validsign
```

Without an explicit choice in the "both configured" case, the first implicit
`DocumentSigner::send()` call throws with the list of configured drivers.

## Sending an envelope

```php
use LaSouris\DocumentSigner\Laravel\Facades\DocumentSigner;
use LaSouris\DocumentSigner\Sdk\Document\Document;
use LaSouris\DocumentSigner\Sdk\Envelope\Envelope;
use LaSouris\DocumentSigner\Sdk\Signer\Signer;

$receipt = DocumentSigner::send(new Envelope(
    name:         'NDA',
    documents:    [new Document(
        id:   'nda',
        name: 'NDA',
        html: '<p>{[signature:counterparty:sig]} on {[date:counterparty:signdate]}</p>',
    )],
    signers:      [new Signer(key: 'counterparty', name: 'Jane Doe', email: 'jane@example.com')],
    emailSubject: 'Please sign the NDA',
));

// Switch driver at runtime:
$receipt = DocumentSigner::driver('docusign')->send($envelope);
```

You can also type-hint the manager directly:

```php
use LaSouris\DocumentSigner\Laravel\DocumentSignerManager;

public function __construct(private DocumentSignerManager $signer) {}
```

## Recipient override (dev / staging)

Seeded and development data is full of reserved domains — `example.com`,
`*.test`, and friends — that providers like ValidSign reject outright, so an
otherwise-valid envelope fails the moment you try to send it. The recipient
override rewrites every signer's email **before** the envelope reaches the
provider, redirecting those addresses to a real inbox you control instead.

It is off by default and adds zero overhead when disabled — the real provider
is used directly, no wrapper installed. **Keep it off in production.**

```dotenv
DOCUMENT_SIGNER_OVERRIDE_ENABLED=true
DOCUMENT_SIGNER_OVERRIDE_TO=you@yourdomain.test
```

That's the whole setup for the default (`catch_all`) strategy. It applies on
every send path — `DocumentSigner::send()`, `DocumentSigner::driver('validsign')->send()`,
and a manually resolved instance alike.

### Strategy

`DOCUMENT_SIGNER_OVERRIDE_STRATEGY` chooses *how* each address is rewritten.
Given `DOCUMENT_SIGNER_OVERRIDE_TO=dev@you.test` (or
`DOCUMENT_SIGNER_OVERRIDE_DOMAIN=you.test`):

| Strategy | `alice@example.com` becomes | Notes |
| --- | --- | --- |
| `catch_all` *(default)* | `dev+alice=example.com@you.test` | Everyone lands in one inbox, but the original address is folded into a `+tag` so each signer stays a **distinct** recipient. ValidSign rejects duplicate recipients on one package, so this is the safe default for multi-signer envelopes. Requires `…_TO`. |
| `domain` | `alice@you.test` | Keeps the local part, swaps only the domain. Recipients stay unique; you need a catch-all inbox on that domain to actually receive the mail. Requires `…_DOMAIN`. |
| `redirect` | `dev@you.test` | Sends everyone to `…_TO` verbatim. Simplest, but multiple signers on one envelope collapse to the same address (a provider may reject that) — use for single-signer flows only. Requires `…_TO`. |

A misconfigured-but-enabled override (e.g. `catch_all` with no `…_TO`) throws a
clear `InvalidArgumentException` on the first send, rather than silently
misrouting mail.

### Scope

By default (`only_domains` empty) **every** signer address is rewritten once
the override is enabled — the `enabled` flag is the only guard, so keep it off
in production. Add entries only to *narrow* the rewrite to specific domains and
let all others pass through untouched:

```php
// config/document-signer.php — rewrite only seeded/test data, leave the rest
'only_domains' => ['example.com', '*.test', '*.local'],
```

Entries match case-insensitively; a `*.` prefix does a suffix match (`*.test`
matches `foo.test`).

## Blade components

The raw `{[type:signer:name]}` syntax is safe to type inside `.blade.php`
files — `{[` / `]}` doesn't collide with Blade's `{{ }}` echo tags. For
ergonomics, though, the package ships five anonymous components that compile
to the raw placeholder token so contracts read like normal HTML:

```blade
<h1>Mutual NDA</h1>

<p>I, <x-document-signer::text signer="counterparty" name="fullname" />, agree.</p>

<p>Signed: <x-document-signer::signature signer="counterparty" name="sig" />
   on <x-document-signer::date signer="counterparty" name="signdate" /></p>

<p><x-document-signer::checkbox signer="counterparty" name="opt_in" />
   I would like to receive updates.</p>
```

| Component | Compiles to |
| --- | --- |
| `<x-document-signer::signature signer="…" name="…" />` | `{[signature:…:…]}` |
| `<x-document-signer::initials signer="…" name="…" />` | `{[initials:…:…]}` |
| `<x-document-signer::text signer="…" name="…" />` | `{[text:…:…]}` |
| `<x-document-signer::date signer="…" name="…" />` | `{[date:…:…]}` |
| `<x-document-signer::checkbox signer="…" name="…" />` | `{[checkbox:…:…]}` |

The components are registered under the `document-signer::` namespace by the
service provider. Both `signer` and `name` are required attributes. After the
view renders, the resulting HTML is what you pass to `Document::$html` — the
SDK parser sees the literal `{[type:signer:name]}` tokens and proceeds as
usual.

## PDF renderer

The manager wires a [`PdfRenderer`](https://github.com/la-souris/package-document-signer-sdk/blob/main/src/Pdf/PdfRenderer.php)
into every driver it resolves. By default it uses the SDK's
`BrowsershotPdfRenderer`. Two other options are built in.

### Default: install spatie/browsershot

The SDK bundles the `BrowsershotPdfRenderer` class but not the Composer
dependency — you need to install it explicitly if you want to keep the
default:

```bash
composer require spatie/browsershot
```

Without it the manager throws an `InvalidArgumentException` pointing at the
install command the first time it tries to build the renderer.

### Use spatie/laravel-pdf

If your application already configures
[spatie/laravel-pdf](https://github.com/spatie/laravel-pdf) — custom Node
binary, default paper size, headers/footers, Browsershot tweaks — switch the
SDK over so it picks up that configuration:

```bash
composer require spatie/laravel-pdf
```

```dotenv
DOCUMENT_SIGNER_PDF_RENDERER=laravel-pdf
```

The SDK then renders every envelope document through the `Pdf` facade. If
`laravel-pdf` isn't installed when this option is selected, the manager
throws an `InvalidArgumentException` pointing at the install command.

### Bind a fully custom renderer

For any other engine (wkhtmltopdf, Gotenberg, an external service, a tuned
Browsershot setup), implement the SDK's `PdfRenderer` interface and bind it
in a service provider — the manager picks up the container binding first and
ignores the config value:

```php
use LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer;
use App\Pdf\GotenbergRenderer;

$this->app->bind(PdfRenderer::class, GotenbergRenderer::class);
```

See [Writing a custom renderer](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/pdf-rendering.md)
for the interface and an example.

## Webhooks

The signing secret **is** the on/off switch. A webhook route is registered
for each driver whose secret is set:

- `DOCUSIGN_CONNECT_HMAC_SECRET` set → `POST /document-signer/webhooks/docusign`
- `VALIDSIGN_CALLBACK_SECRET` set → `POST /document-signer/webhooks/validsign`

If neither is set, no webhook routes are registered at all — a webhook with
no secret would 401 every request anyway, so exposing it wouldn't be useful.
This is also the switch to use when callbacks are handled by a separate
service (queue worker, edge function, external ingest): just leave the
secret unset.

DocuSign only:

```dotenv
DOCUSIGN_INTEGRATION_KEY=...
DOCUSIGN_CONNECT_HMAC_SECRET=...
```

ValidSign only:

```dotenv
VALIDSIGN_API_KEY=...
VALIDSIGN_CALLBACK_SECRET=...
```

The common prefix (default `document-signer/webhooks`) and middleware
(default `['api']`) live under `document-signer.routing` in the config file.
Each provider's secret lives in its own entry, under `webhook`.

| Provider | Registered when | Route name | URL | Signature mechanism |
| --- | --- | --- | --- | --- |
| DocuSign | `DOCUSIGN_CONNECT_HMAC_SECRET` is set | `document-signer.webhooks.docusign` | `POST /document-signer/webhooks/docusign` | HMAC-SHA256 of raw body in `X-DocuSign-Signature-1..N` |
| ValidSign | `VALIDSIGN_CALLBACK_SECRET` is set | `document-signer.webhooks.validsign` | `POST /document-signer/webhooks/validsign` | Shared secret in `Authorization: Basic <credentials>` — accepted as `base64("user:secret")`, `base64(secret)`, or the raw string |

Both verifiers use `hash_equals` for constant-time comparison and reject
unverified requests with HTTP 401.

Listen to the event in `app/Providers/EventServiceProvider.php`:

```php
use LaSouris\DocumentSigner\Laravel\Events\DocumentSignerWebhookReceived;

protected $listen = [
    DocumentSignerWebhookReceived::class => [
        \App\Listeners\HandleSignerWebhook::class,
    ],
];
```

Every event carries the originating provider on the envelope as
`$event->provider` (a class-string) — always set, regardless of the event
below. The controller also resolves the payload's event token against the
provider's `WebhookEvent` enum before dispatching, so listeners can classify
events without doing the enum look-up themselves. `WebhookEvent` is a
`BackedEnum`; both first-party providers (ValidSign and DocuSign) ship an enum
with an `Unknown` case, so unmatched tokens resolve to a non-null,
semantically-inert value rather than `null`. Only a custom provider that
returns `null` (or ships no enum) yields `$event->event === null`, so keep the
null-safe operator:

```php
use LaSouris\DocumentSigner\Laravel\Events\DocumentSignerWebhookReceived;

final class HandleSignerWebhook
{
    public function handle(DocumentSignerWebhookReceived $event): void
    {
        // Provider-agnostic — the same code serves DocuSign and ValidSign.
        // Null-safe: a null event / Unknown case falls through to `default`.
        match (true) {
            $event->event?->isCompleted() => $this->onCompleted($event->payload),
            $event->event?->isDeclined()  => $this->onDeclined($event->payload),
            $event->event?->isFailure()   => $this->pageOncall($event->payload),
            default                       => null,
        };
    }
}
```

Need the originating provider? It's on the envelope as `$event->provider`
(always present, even when `$event->event` is `null`), so you can branch on it
unambiguously:

```php
use LaSouris\DocumentSigner\ValidSign\ValidSignProvider;

if ($event->provider === ValidSignProvider::class) {
    // ValidSign-specific handling; short name is $event->provider::NAME
}
```

The raw payload is still available on `$event->payload` (and the original
`Illuminate\Http\Request` on `$event->request`) if you need vendor-specific
fields.

### Translated event labels

Each `WebhookEvent` can be rendered as a human-readable string via
`EventTranslator`, backed by the package's translation files:

```php
use LaSouris\DocumentSigner\Laravel\Webhook\EventTranslator;

public function handle(
    DocumentSignerWebhookReceived $event,
    EventTranslator               $labels,
): void {
    // Nothing to label when the provider ships no enum.
    if ($event->event === null) {
        return;
    }

    // "Package complete" in en, "Pakket voltooid" in nl.
    Log::info($labels->label($event->event, $event->provider));

    // Or force a locale:
    $subject = $labels->label($event->event, $event->provider, locale: 'nl');
}
```

The package ships English (`en`) and Dutch (`nl`) translations for every
ValidSign and DocuSign event out of the box. To add a locale or override the
wording, publish the translations and edit the copies:

```bash
php artisan vendor:publish --tag=document-signer-translations
# → lang/vendor/document-signer/{en,nl}/{validsign,docusign}-events.php
```

Translation keys mirror the enum's raw provider tokens verbatim
(`document-signer::validsign-events.PACKAGE_COMPLETE`,
`document-signer::docusign-events.envelope-completed`), so you can also
reach them via `trans()` directly.

## Custom providers

Providers are configured as a list under `document-signer.providers`, each
entry naming a `class`. Nothing about the built-in providers is privileged —
to add your own signing integration, point an entry at your class:

```php
// config/document-signer.php
'providers' => [
    // ...validsign / docusign entries...
    [
        'class'  => \App\Signing\AcmeSignProvider::class,
        'config' => [
            'api_key'  => env('ACME_API_KEY'),
            'base_url' => env('ACME_BASE_URL', 'https://api.acme.example'),
        ],
        'webhook' => [
            'secret' => env('ACME_WEBHOOK_SECRET'),
        ],
    ],
],
```

Your class needs two things:

1. **Implement the SDK's `SignatureProvider`** — the manager type-guarantees
   every resolved driver against it. (An entry whose class doesn't implement
   it is ignored.)
2. **Declare a `NAME` constant** — this short name is how the provider is
   selected (`DOCUMENT_SIGNER_DRIVER=acme`, `DocumentSigner::driver('acme')`)
   and the last segment of its webhook route. A listed class without it throws
   a clear error.

The manager builds your provider **through the container**, so ordinary
dependencies auto-wire. Two arguments are supplied by name when you declare
them — take either, both, or neither:

- `array $config` — the entry's `config` block, so credentials come straight
  from configuration (no need to read `env()` yourself).
- `PdfRenderer $pdfRenderer` — the integration-managed renderer (§ *PDF
  renderer*), for providers that render envelope documents.

```php
namespace App\Signing;

use LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider;

final class AcmeSignProvider implements SignatureProvider
{
    public const string NAME = 'acme';

    public function __construct(
        private readonly array $config,        // ['api_key' => ..., 'base_url' => ...]
        private readonly PdfRenderer $pdfRenderer,
    ) {}

    // send(), getStatus(), downloadSigned(), cancel(), ...
}
```

If it's the only provider with a credential set, it's auto-selected as the
default; otherwise select it with `DOCUMENT_SIGNER_DRIVER=acme`. For wiring
that's easier to express in code than in config (closures, conditional
construction), register a factory instead:

```php
use LaSouris\DocumentSigner\Laravel\DocumentSignerManager;
use LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer;

$this->app->afterResolving(DocumentSignerManager::class, function (DocumentSignerManager $manager) {
    $manager->extend('acme', fn ($app, array $config) => new AcmeSignProvider(
        $config,
        $app->make(PdfRenderer::class),
    ));
});
```

The closure receives the container and the entry's `config` block, and owns
the whole construction — so it also has to provide the `PdfRenderer` itself
(bind one, or drop the argument if your provider doesn't render).

### Custom webhooks

To have the package register and verify a webhook route for your provider,
implement `ProvidesWebhook`. As soon as the entry's `webhook` block holds a
secret, `POST /{prefix}/{NAME}` is registered — identically to the built-ins,
including `route:cache` support — and every request is verified before your
listeners run.

```php
use LaSouris\DocumentSigner\Laravel\Http\SignatureVerification\WebhookSignatureVerifier;
use LaSouris\DocumentSigner\Laravel\Http\Webhook\ProvidesWebhook;
use LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider;
use LaSouris\DocumentSigner\Sdk\Webhook\WebhookEvent;
use Illuminate\Http\Request;

final class AcmeSignProvider implements SignatureProvider, ProvidesWebhook
{
    public const string NAME = 'acme';

    // Built without constructing the provider — no credentials needed just to
    // authenticate a callback. Receives the entry's `webhook` config block.
    public static function webhookVerifier(array $webhookConfig): WebhookSignatureVerifier
    {
        return new AcmeWebhookVerifier($webhookConfig['secret'] ?? '');
    }

    // Map the payload to a WebhookEvent so listeners can use the semantic
    // predicates; return null if you have no event enum.
    public static function resolveWebhookEvent(array $payload): ?WebhookEvent
    {
        return null;
    }
}
```

Your `WebhookSignatureVerifier::verify(Request $request): bool` should compare
secrets with `hash_equals` for constant-time safety and return `false` for
anything unproven — the controller turns that into an HTTP 401. Verified
callbacks are dispatched as the same `DocumentSignerWebhookReceived` event
(with `driver` set to your `NAME`), so existing listeners handle them
unchanged.

## Testing

Swap the live provider for a fake in tests:

```php
use LaSouris\DocumentSigner\Laravel\Facades\DocumentSigner;
use LaSouris\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LaSouris\DocumentSigner\Sdk\Provider\EnvelopeReceipt;
use LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider;

DocumentSigner::set('validsign', new class implements SignatureProvider {
    public function send($envelope): EnvelopeReceipt {
        return new EnvelopeReceipt(
            provider: 'validsign',
            providerEnvelopeId: 'test-id',
            status: EnvelopeStatus::Sent,
        );
    }
    public function getStatus(string $id): EnvelopeStatus { return EnvelopeStatus::Completed; }
    public function downloadSigned(string $id): \SplFileInfo { return new \SplFileInfo('/dev/null'); }
    public function downloadSignedDocument(string $id, string $documentId): \SplFileInfo { return new \SplFileInfo('/dev/null'); }
    public function hasAuditTrail(): bool { return true; }
    public function downloadAudit(string $id): \SplFileInfo { return new \SplFileInfo('/dev/null'); }
    public function getFieldValues(string $id): array { return []; }
    public function cancel(string $id, ?string $reason = null): void {}
});
```

For full end-to-end provider mocking, see
[Extending the SDK](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/extending.md).

## Requirements

- PHP 8.5
- Laravel 13
- `la-souris/document-signer-sdk` ^1.0
- `la-souris/document-signer-validsign` *or* `la-souris/document-signer-docusign` (each is optional;
  installed only for the drivers you actually use — the manager throws a clear
  `composer require` hint if a missing driver is requested)
- Node.js + Puppeteer (for the default Browsershot renderer)

## Documentation

- [SDK README](https://github.com/la-souris/package-document-signer-sdk)
- [Getting started](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/getting-started.md)
- [ValidSign provider guide](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/providers/validsign.md)
- [DocuSign provider guide](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/providers/docusign.md)
- [Placeholder syntax](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/placeholders.md)
- [Extending the SDK](https://github.com/la-souris/package-document-signer-sdk/blob/main/docs/extending.md)
