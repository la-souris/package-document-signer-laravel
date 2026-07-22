<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Tests\Fixtures;

use LaSouris\DocumentSigner\Sdk\Envelope\Envelope;
use LaSouris\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LaSouris\DocumentSigner\Sdk\Provider\EnvelopeReceipt;
use LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider;
use SplFileInfo;

/**
 * A minimal app-owned {@see SignatureProvider} used to prove
 * DocumentSignerManager can resolve a custom provider from a
 * `document-signer.providers` entry, inject the managed {@see PdfRenderer},
 * and hand the entry's `config` array to the constructor.
 */
final class ConfigurableTestProvider implements SignatureProvider
{
    public const string NAME = 'internal';

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly PdfRenderer $pdfRenderer,
        public readonly array $config = [],
    ) {}

    public function send(Envelope $envelope): EnvelopeReceipt
    {
        return new EnvelopeReceipt(provider: 'configurable', providerEnvelopeId: 'x', status: EnvelopeStatus::Sent);
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
