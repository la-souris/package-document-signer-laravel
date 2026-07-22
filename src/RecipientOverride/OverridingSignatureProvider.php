<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\RecipientOverride;

use LaSouris\DocumentSigner\Sdk\Envelope\Envelope;
use LaSouris\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LaSouris\DocumentSigner\Sdk\Provider\EnvelopeReceipt;
use LaSouris\DocumentSigner\Sdk\Provider\FieldValue;
use LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider;
use SplFileInfo;

/**
 * Wraps any {@see SignatureProvider} and runs the envelope through a
 * {@see RecipientRewriter} before {@see send()} hands it to the real provider.
 *
 * Wrapping at the provider level (rather than only in the manager's `send()`)
 * means the override applies no matter how the provider is reached —
 * `DocumentSigner::send()`, `DocumentSigner::driver('validsign')->send()`, or a
 * resolved instance passed around by hand. Every other call is delegated
 * verbatim, so the decorator is transparent for status, downloads and cancel.
 *
 * The manager only installs this wrapper when the override is enabled, so in
 * production the real provider is used directly with zero overhead.
 */
final class OverridingSignatureProvider implements SignatureProvider
{
    public function __construct(
        private readonly SignatureProvider $inner,
        private readonly RecipientRewriter $rewriter,
    ) {}

    /**
     * The wrapped provider, for callers that need the concrete type back
     * (e.g. `instanceof` checks) once the decorator is in place.
     */
    public function inner(): SignatureProvider
    {
        return $this->inner;
    }

    public function send(Envelope $envelope): EnvelopeReceipt
    {
        return $this->inner->send($this->rewriter->apply($envelope));
    }

    public function getStatus(string $providerEnvelopeId): EnvelopeStatus
    {
        return $this->inner->getStatus($providerEnvelopeId);
    }

    public function downloadSigned(string $providerEnvelopeId): SplFileInfo
    {
        return $this->inner->downloadSigned($providerEnvelopeId);
    }

    public function downloadSignedDocument(string $providerEnvelopeId, string $documentId): SplFileInfo
    {
        return $this->inner->downloadSignedDocument($providerEnvelopeId, $documentId);
    }

    public function hasAuditTrail(): bool
    {
        return $this->inner->hasAuditTrail();
    }

    public function downloadAudit(string $providerEnvelopeId): SplFileInfo
    {
        return $this->inner->downloadAudit($providerEnvelopeId);
    }

    /**
     * @return list<FieldValue>
     */
    public function getFieldValues(string $providerEnvelopeId): array
    {
        return $this->inner->getFieldValues($providerEnvelopeId);
    }

    public function cancel(string $providerEnvelopeId, ?string $reason = null): void
    {
        $this->inner->cancel($providerEnvelopeId, $reason);
    }
}
