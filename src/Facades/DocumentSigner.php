<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Facades;

use LaSouris\DocumentSigner\Laravel\DocumentSignerManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider driver(?string $name = null)
 * @method static \LaSouris\DocumentSigner\Sdk\Provider\EnvelopeReceipt   send(\LaSouris\DocumentSigner\Sdk\Envelope\Envelope $envelope)
 * @method static \LaSouris\DocumentSigner\Sdk\Envelope\EnvelopeStatus    getStatus(string $providerEnvelopeId)
 * @method static string                                          downloadSigned(string $providerEnvelopeId)
 * @method static \SplFileInfo                                     downloadSignedDocument(string $providerEnvelopeId, string $documentId)
 * @method static bool                                            hasAuditTrail()
 * @method static void                                            cancel(string $providerEnvelopeId, ?string $reason = null)
 * @method static \LaSouris\DocumentSigner\Laravel\DocumentSignerManager   extend(string $name, \Closure $factory)
 * @method static \LaSouris\DocumentSigner\Laravel\DocumentSignerManager   set(string $name, \LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider $provider)
 * @method static string                                          getDefaultDriver()
 *
 * @see \LaSouris\DocumentSigner\Laravel\DocumentSignerManager
 */
final class DocumentSigner extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DocumentSignerManager::class;
    }
}
