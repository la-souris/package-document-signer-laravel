<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Http\Controllers;

use LaSouris\DocumentSigner\Laravel\Events\DocumentSignerWebhookReceived;
use LaSouris\DocumentSigner\Laravel\Http\SignatureVerification\DocuSignSignatureVerifier;
use LaSouris\DocumentSigner\Laravel\Http\SignatureVerification\ValidSignSignatureVerifier;
use LaSouris\DocumentSigner\Laravel\Http\SignatureVerification\WebhookSignatureVerifier;
use LaSouris\DocumentSigner\Laravel\Http\Webhook\ProvidesWebhook;
use LaSouris\DocumentSigner\DocuSign\DocuSignProvider;
use LaSouris\DocumentSigner\DocuSign\Webhook\EventType as DocuSignEventType;
use LaSouris\DocumentSigner\Sdk\Webhook\WebhookEvent;
use LaSouris\DocumentSigner\ValidSign\ValidSignProvider;
use LaSouris\DocumentSigner\ValidSign\Webhook\EventType as ValidSignEventType;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives provider-side status callbacks, verifies the shared-secret signature,
 * and re-emits the payload as a {@see DocumentSignerWebhookReceived} event so
 * application code can update its own state without coupling to the SDK.
 *
 * The controller resolves each callback token against the provider's
 * {@see WebhookEvent} enum before dispatching, so listeners can use the semantic
 * predicates (`$event->event?->isCompleted()`, `->isDeclined()`, …) without doing
 * the enum look-up themselves. Both first-party providers ship an enum with an
 * `Unknown` case, so their events are always non-null; a provider with no enum
 * (or a custom provider that returns null) dispatches `event: null`.
 */
final class WebhookController
{
    public function __construct(
        private readonly Config     $config,
        private readonly Dispatcher $events,
    ) {}

    public function docusign(Request $request): JsonResponse
    {
        return $this->handle(
            $request,
            provider: DocuSignProvider::class,
            verifier: new DocuSignSignatureVerifier(
                $this->webhookSecret('docusign', 'hmac_secret'),
            ),
            resolveEvent: static fn (array $payload): ?WebhookEvent =>
                class_exists(DocuSignEventType::class)
                    ? DocuSignEventType::tryFromPayload($payload)
                    : null,
        );
    }

    public function validsign(Request $request): JsonResponse
    {
        return $this->handle(
            $request,
            provider: ValidSignProvider::class,
            verifier: new ValidSignSignatureVerifier(
                (string) ($this->webhookSecret('validsign', 'callback_secret') ?? ''),
            ),
            resolveEvent: static fn (array $payload): ?WebhookEvent =>
                class_exists(ValidSignEventType::class)
                    ? ValidSignEventType::tryFromPayload($payload)
                    : null,
        );
    }

    /**
     * Generic action for app-owned providers. The provider name comes from the
     * route (bound as a default when the route is registered). The matching
     * `document-signer.providers` entry must point at a class implementing
     * {@see ProvidesWebhook}, which supplies the verifier and event resolver.
     */
    public function custom(Request $request, string $provider): JsonResponse
    {
        $entry = $this->providerEntry($provider);
        $class = $entry['class'] ?? null;

        if (!is_string($class) || !is_a($class, ProvidesWebhook::class, true)) {
            return new JsonResponse(['error' => 'unknown_provider'], 404);
        }

        $webhookConfig = is_array($entry['webhook'] ?? null) ? $entry['webhook'] : [];

        return $this->handle(
            $request,
            provider: $class,
            verifier: $class::webhookVerifier($webhookConfig),
            resolveEvent: static fn (array $payload): ?WebhookEvent => $class::resolveWebhookEvent($payload),
        );
    }

    /**
     * The shared secret for a provider's webhook, read from its entry in
     * `document-signer.providers`. Returns `null` when the provider is absent
     * or the key is unset.
     */
    private function webhookSecret(string $providerName, string $key): ?string
    {
        $webhook = $this->providerEntry($providerName)['webhook'] ?? null;
        $value = is_array($webhook) ? ($webhook[$key] ?? null) : null;

        return is_string($value) ? $value : null;
    }

    /**
     * The `document-signer.providers` entry whose class exposes a `NAME`
     * constant equal to `$providerName`, or `null` when none match.
     *
     * @return array{class: string, config?: mixed, webhook?: mixed}|null
     */
    private function providerEntry(string $providerName): ?array
    {
        $providers = $this->config->get('document-signer.providers');
        if (!is_array($providers)) {
            return null;
        }

        foreach ($providers as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $class = $entry['class'] ?? null;
            if (!is_string($class) || !defined("{$class}::NAME") || constant("{$class}::NAME") !== $providerName) {
                continue;
            }

            return $entry;
        }

        return null;
    }

    /**
     * @param class-string                                   $provider     Originating provider, put on the dispatched event.
     * @param \Closure(array<string, mixed>): ?WebhookEvent  $resolveEvent
     */
    private function handle(
        Request $request,
        string $provider,
        WebhookSignatureVerifier $verifier,
        \Closure $resolveEvent,
    ): JsonResponse {
        if (!$verifier->verify($request)) {
            return new JsonResponse(['error' => 'invalid_signature'], 401);
        }

        $payload = [];
        $contentType = (string) ($request->header('Content-Type') ?? '');
        if (str_contains($contentType, 'json')) {
            try {
                $decoded = json_decode($request->getContent() ?: '[]', true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            } catch (\JsonException) {
                return new JsonResponse(['error' => 'invalid_json'], 400);
            }
        }

        $this->events->dispatch(new DocumentSignerWebhookReceived(
            provider: $provider,
            payload: $payload,
            request: $request,
            event: $resolveEvent($payload),
        ));

        return new JsonResponse(['ok' => true]);
    }
}
