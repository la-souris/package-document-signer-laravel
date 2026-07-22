<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Tests\RecipientOverride;

use LaSouris\DocumentSigner\Laravel\DocumentSignerManager;
use LaSouris\DocumentSigner\Laravel\RecipientOverride\OverridingSignatureProvider;
use LaSouris\DocumentSigner\Sdk\Document\Document;
use LaSouris\DocumentSigner\Sdk\Envelope\Envelope;
use LaSouris\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LaSouris\DocumentSigner\Sdk\Provider\EnvelopeReceipt;
use LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider;
use LaSouris\DocumentSigner\Sdk\Signer\Signer;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ManagerRecipientOverrideTest extends TestCase
{
    #[Test]
    public function it_does_not_wrap_the_provider_when_the_override_is_disabled(): void
    {
        $capturing = $this->capturingProvider();
        $manager = $this->manager(['enabled' => false], $capturing);

        self::assertSame($capturing, $manager->driver('cap'));
    }

    #[Test]
    public function it_wraps_the_provider_and_rewrites_the_envelope_on_send(): void
    {
        $capturing = $this->capturingProvider();
        $manager = $this->manager([
            'enabled'  => true,
            'strategy' => 'domain',
            'domain'   => 'you.test',
        ], $capturing);

        $driver = $manager->driver('cap');
        self::assertInstanceOf(OverridingSignatureProvider::class, $driver);
        self::assertSame($capturing, $driver->inner());

        $manager->send($this->envelope('alice@example.com'));

        // The inner provider only ever saw the rewritten address.
        self::assertSame('alice@you.test', $capturing->lastEnvelope->signerByKey('a')->email);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function manager(array $override, SignatureProvider $provider): DocumentSignerManager
    {
        $container = new Container();
        $container->instance('config', new Repository([
            'document-signer' => [
                'default'            => 'cap',
                'providers'          => [],
                'recipient_override' => $override,
            ],
        ]));

        $manager = new DocumentSignerManager($container);
        $manager->extend('cap', static fn () => $provider);

        return $manager;
    }

    private function envelope(string $email): Envelope
    {
        return new Envelope(
            name:         'Contract',
            documents:    [new Document(id: 'd1', name: 'Doc', html: '<p>hi</p>')],
            signers:      [new Signer(key: 'a', name: 'Alice', email: $email)],
            emailSubject: 'Please sign',
        );
    }

    private function capturingProvider(): SignatureProvider
    {
        return new class implements SignatureProvider {
            public ?Envelope $lastEnvelope = null;

            public function send(Envelope $envelope): EnvelopeReceipt
            {
                $this->lastEnvelope = $envelope;

                return new EnvelopeReceipt(provider: 'cap', providerEnvelopeId: 'x', status: EnvelopeStatus::Sent);
            }

            public function getStatus(string $providerEnvelopeId): EnvelopeStatus { return EnvelopeStatus::Sent; }
            public function downloadSigned(string $providerEnvelopeId): \SplFileInfo { return new \SplFileInfo('/dev/null'); }
            public function downloadSignedDocument(string $providerEnvelopeId, string $documentId): \SplFileInfo { return new \SplFileInfo('/dev/null'); }
            public function hasAuditTrail(): bool { return false; }
            public function downloadAudit(string $providerEnvelopeId): \SplFileInfo { return new \SplFileInfo('/dev/null'); }
            public function getFieldValues(string $providerEnvelopeId): array { return []; }
            public function cancel(string $providerEnvelopeId, ?string $reason = null): void {}
        };
    }
}
