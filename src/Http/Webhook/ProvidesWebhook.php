<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Http\Webhook;

use LaSouris\DocumentSigner\Laravel\Http\SignatureVerification\WebhookSignatureVerifier;
use LaSouris\DocumentSigner\Sdk\Webhook\WebhookEvent;

/**
 * Opt-in webhook support for app-owned providers.
 *
 * A {@see \LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider} listed in
 * `document-signer.providers` that also implements this interface gets a
 * verified webhook route registered automatically — at
 * `POST /{prefix}/{NAME}` — as soon as its `webhook` config block holds a
 * secret. The built-in ValidSign and DocuSign providers are handled by the
 * package directly and do not need to implement this.
 *
 * Both methods are static: the framework verifies and routes a callback
 * without constructing the provider (no credentials or PdfRenderer required
 * just to authenticate an incoming request).
 */
interface ProvidesWebhook
{
    /**
     * Build the verifier that authenticates an incoming callback, from this
     * provider's `webhook` config block (the `webhook` array of its entry in
     * `document-signer.providers`). Unverified requests are rejected with 401.
     *
     * @param array<string, mixed> $webhookConfig
     */
    public static function webhookVerifier(array $webhookConfig): WebhookSignatureVerifier;

    /**
     * Resolve a provider-native {@see WebhookEvent} from the decoded JSON
     * payload, so listeners can use the semantic predicates
     * (`$event->event?->isCompleted()`, ...) without re-parsing the body.
     *
     * `WebhookEvent` is a {@see \BackedEnum}, so returning a non-null value
     * means returning one of your provider's enum cases. Give that enum an
     * `Unknown` case and map unmatched tokens to it to keep the dispatched
     * event non-null; return `null` if your provider has no event enum. Either
     * way the raw payload stays available on the dispatched event.
     *
     * @param array<string, mixed> $payload
     */
    public static function resolveWebhookEvent(array $payload): ?WebhookEvent;
}
