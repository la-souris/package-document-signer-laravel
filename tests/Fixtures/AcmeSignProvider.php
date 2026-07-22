<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Tests\Fixtures;

use LaSouris\DocumentSigner\Laravel\Http\SignatureVerification\WebhookSignatureVerifier;
use LaSouris\DocumentSigner\Laravel\Http\Webhook\ProvidesWebhook;
use LaSouris\DocumentSigner\Sdk\Envelope\Envelope;
use LaSouris\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LaSouris\DocumentSigner\Sdk\Provider\EnvelopeReceipt;
use LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider;
use LaSouris\DocumentSigner\Sdk\Webhook\WebhookEvent;
use Illuminate\Http\Request;
use SplFileInfo;

/**
 * A self-contained, app-owned integration used to prove the custom-provider
 * path end to end: it is resolved purely from a `document-signer.providers`
 * entry (credentials injected as `$config`) and registers its own verified
 * webhook by implementing {@see ProvidesWebhook}.
 *
 * Its webhook is authenticated by a simple `X-Acme-Token` header compared, in
 * constant time, against the secret from the entry's `webhook` block.
 */
final class AcmeSignProvider implements SignatureProvider, ProvidesWebhook
{
    public const string NAME = 'acme';

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly array $config,
        public readonly PdfRenderer $pdfRenderer,
    ) {}

    public static function webhookVerifier(array $webhookConfig): WebhookSignatureVerifier
    {
        $secret = is_string($webhookConfig['secret'] ?? null) ? $webhookConfig['secret'] : '';

        return new class ($secret) implements WebhookSignatureVerifier {
            public function __construct(private readonly string $secret) {}

            public function verify(Request $request): bool
            {
                $given = (string) ($request->header('X-Acme-Token') ?? '');

                return $this->secret !== '' && hash_equals($this->secret, $given);
            }
        };
    }

    public static function resolveWebhookEvent(array $payload): ?WebhookEvent
    {
        return null;
    }

    public function send(Envelope $envelope): EnvelopeReceipt
    {
        return new EnvelopeReceipt(provider: self::NAME, providerEnvelopeId: 'x', status: EnvelopeStatus::Sent);
    }

    public function getStatus(string $providerEnvelopeId): EnvelopeStatus
    {
        return EnvelopeStatus::Sent;
    }

    public function downloadSigned(string $providerEnvelopeId): SplFileInfo
    {
        return new SplFileInfo('/dev/null');
    }

    public function downloadSignedDocument(string $providerEnvelopeId, string $documentId): SplFileInfo
    {
        return new SplFileInfo('/dev/null');
    }

    public function hasAuditTrail(): bool
    {
        return false;
    }

    public function downloadAudit(string $providerEnvelopeId): SplFileInfo
    {
        return new SplFileInfo('/dev/null');
    }

    public function getFieldValues(string $providerEnvelopeId): array
    {
        return [];
    }

    public function cancel(string $providerEnvelopeId, ?string $reason = null): void {}
}
