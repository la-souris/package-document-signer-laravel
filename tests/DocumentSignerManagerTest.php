<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Tests;

use LaSouris\DocumentSigner\DocuSign\DocuSignProvider;
use LaSouris\DocumentSigner\Laravel\DocumentSignerManager;
use LaSouris\DocumentSigner\Laravel\Tests\Fixtures\ConfigurableTestProvider;
use LaSouris\DocumentSigner\Sdk\Envelope\EnvelopeStatus;
use LaSouris\DocumentSigner\Sdk\Pdf\PageDecoration;
use LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LaSouris\DocumentSigner\Sdk\Provider\EnvelopeReceipt;
use LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider;
use LaSouris\DocumentSigner\ValidSign\ValidSignProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentSignerManagerTest extends TestCase
{
    private static ?string $rsaPem = null;

    public static function setUpBeforeClass(): void
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($resource !== false) {
            openssl_pkey_export($resource, $pem);
            self::$rsaPem = $pem;
        }
    }

    #[Test]
    public function it_resolves_the_default_driver(): void
    {
        $manager = $this->makeManager([
            'default' => 'validsign',
            'providers' => [
                $this->validsign(['api_key' => 'k']),
            ],
        ]);

        self::assertInstanceOf(ValidSignProvider::class, $manager->driver());
    }

    #[Test]
    public function it_resolves_named_drivers(): void
    {
        if (self::$rsaPem === null) {
            self::markTestSkipped('openssl not available');
        }

        $manager = $this->makeManager([
            'default' => 'validsign',
            'providers' => [
                $this->validsign(['api_key' => 'k']),
                $this->docusign([
                    'integration_key' => 'i', 'user_id' => 'u', 'account_id' => 'a',
                    'private_key' => self::$rsaPem,
                ]),
            ],
        ]);

        self::assertInstanceOf(ValidSignProvider::class, $manager->driver('validsign'));
        self::assertInstanceOf(DocuSignProvider::class, $manager->driver('docusign'));
    }

    #[Test]
    public function it_caches_resolved_drivers(): void
    {
        $manager = $this->makeManager([
            'default' => 'validsign',
            'providers' => [$this->validsign(['api_key' => 'k'])],
        ]);

        self::assertSame($manager->driver(), $manager->driver());
    }

    #[Test]
    public function it_lets_callers_seed_drivers_for_tests(): void
    {
        $fake = $this->fakeProvider();
        $manager = $this->makeManager([
            'default' => 'validsign',
            'providers' => [$this->validsign(['api_key' => 'k'])],
        ]);

        $manager->set('validsign', $fake);

        self::assertSame($fake, $manager->driver('validsign'));
    }

    #[Test]
    public function it_supports_extending_with_a_custom_driver_factory(): void
    {
        $fake = $this->fakeProvider();
        $manager = $this->makeManager([
            'default' => 'hellosign',
            'providers' => [],
        ]);

        $manager->extend('hellosign', static fn () => $fake);

        self::assertSame($fake, $manager->driver('hellosign'));
    }

    #[Test]
    public function it_throws_a_clear_error_when_validsign_api_key_is_missing(): void
    {
        $manager = $this->makeManager([
            'default' => 'validsign',
            'providers' => [$this->validsign(['api_key' => ''])],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ValidSign API key missing');

        $manager->driver('validsign');
    }

    #[Test]
    public function it_throws_when_no_drivers_are_configured_and_no_default_is_set(): void
    {
        $manager = $this->makeManager(['providers' => []]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No document-signer driver is configured');

        $manager->driver();
    }

    #[Test]
    public function it_auto_selects_the_sole_configured_driver_when_no_default_is_set(): void
    {
        $manager = $this->makeManager([
            'providers' => [
                $this->validsign(['api_key' => 'k']),
                $this->docusign(['integration_key' => null]),
            ],
        ]);

        self::assertSame('validsign', $manager->getDefaultDriver());
        self::assertInstanceOf(ValidSignProvider::class, $manager->driver());
    }

    #[Test]
    public function it_auto_selects_docusign_when_only_docusign_is_configured(): void
    {
        if (self::$rsaPem === null) {
            self::markTestSkipped('openssl not available');
        }

        $manager = $this->makeManager([
            'providers' => [
                $this->validsign(['api_key' => null]),
                $this->docusign([
                    'integration_key' => 'i', 'user_id' => 'u', 'account_id' => 'a',
                    'private_key' => self::$rsaPem,
                ]),
            ],
        ]);

        self::assertSame('docusign', $manager->getDefaultDriver());
        self::assertInstanceOf(DocuSignProvider::class, $manager->driver());
    }

    #[Test]
    public function it_throws_a_multi_driver_hint_when_more_than_one_is_configured_and_no_default_is_set(): void
    {
        $manager = $this->makeManager([
            'providers' => [
                $this->validsign(['api_key' => 'k']),
                $this->docusign(['integration_key' => 'i']),
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiple document-signer drivers are configured (docusign, validsign)');

        $manager->driver();
    }

    #[Test]
    public function explicit_default_wins_over_auto_selection(): void
    {
        if (self::$rsaPem === null) {
            self::markTestSkipped('openssl not available');
        }

        $manager = $this->makeManager([
            'default' => 'docusign',
            'providers' => [
                $this->validsign(['api_key' => 'k']),
                $this->docusign([
                    'integration_key' => 'i', 'user_id' => 'u', 'account_id' => 'a',
                    'private_key' => self::$rsaPem,
                ]),
            ],
        ]);

        self::assertInstanceOf(DocuSignProvider::class, $manager->driver());
    }

    #[Test]
    public function it_throws_on_unknown_driver(): void
    {
        $manager = $this->makeManager([
            'default' => 'wat',
            'providers' => [],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown document-signer driver: 'wat'");

        $manager->driver('wat');
    }

    #[Test]
    public function it_resolves_a_configured_custom_provider_and_injects_the_pdf_renderer(): void
    {
        $renderer = $this->fakeRenderer();
        $container = new Container();
        $container->instance('config', new Repository([
            'document-signer' => [
                'default' => 'internal',
                'providers' => [
                    ['class' => ConfigurableTestProvider::class, 'config' => ['enabled' => true]],
                ],
            ],
        ]));
        $container->instance(PdfRenderer::class, $renderer);

        $manager = new DocumentSignerManager($container);
        $provider = $manager->driver('internal');

        self::assertInstanceOf(ConfigurableTestProvider::class, $provider);
        self::assertSame($renderer, $provider->pdfRenderer);
    }

    #[Test]
    public function it_passes_the_entry_config_to_a_custom_provider(): void
    {
        $renderer = $this->fakeRenderer();
        $container = new Container();
        $container->instance('config', new Repository([
            'document-signer' => [
                'default' => 'internal',
                'providers' => [
                    [
                        'class'  => ConfigurableTestProvider::class,
                        'config' => ['api_key' => 'acme-secret', 'region' => 'eu'],
                    ],
                ],
            ],
        ]));
        $container->instance(PdfRenderer::class, $renderer);

        $manager = new DocumentSignerManager($container);
        $provider = $manager->driver('internal');

        self::assertInstanceOf(ConfigurableTestProvider::class, $provider);
        self::assertSame(['api_key' => 'acme-secret', 'region' => 'eu'], $provider->config);
        self::assertSame($renderer, $provider->pdfRenderer);
    }

    #[Test]
    public function it_ignores_provider_entries_whose_class_is_not_a_signature_provider(): void
    {
        $manager = $this->makeManager([
            'providers' => [
                ['class' => \stdClass::class, 'config' => ['enabled' => true]],
            ],
        ]);

        self::assertSame([], $manager->configuredDrivers());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No document-signer driver is configured');

        $manager->driver();
    }

    #[Test]
    public function it_throws_when_a_provider_class_has_no_name_constant(): void
    {
        $namelessClass = $this->fakeProvider()::class;

        $manager = $this->makeManager([
            'providers' => [
                ['class' => $namelessClass, 'config' => ['enabled' => true]],
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must declare a `public const string NAME`');

        $manager->getDefaultDriver();
    }

    /**
     * @param array<string, mixed> $config
     * @return array{class: class-string, config: array<string, mixed>}
     */
    private function validsign(array $config): array
    {
        return ['class' => ValidSignProvider::class, 'config' => $config];
    }

    /**
     * @param array<string, mixed> $config
     * @return array{class: class-string, config: array<string, mixed>}
     */
    private function docusign(array $config): array
    {
        return ['class' => DocuSignProvider::class, 'config' => $config];
    }

    /**
     * @param array<string, mixed> $documentSignerConfig
     */
    private function makeManager(array $documentSignerConfig): DocumentSignerManager
    {
        $container = new Container();
        $container->instance('config', new Repository(['document-signer' => $documentSignerConfig]));

        return new DocumentSignerManager($container);
    }

    private function fakeRenderer(): PdfRenderer
    {
        return new class implements PdfRenderer {
            public function render(string $html, ?PageDecoration $decoration = null): string
            {
                return '';
            }
        };
    }

    private function fakeProvider(): SignatureProvider
    {
        return new class implements SignatureProvider {
            public function send($envelope): EnvelopeReceipt
            {
                return new EnvelopeReceipt(provider: 'fake', providerEnvelopeId: 'x', status: EnvelopeStatus::Sent);
            }
            public function getStatus(string $providerEnvelopeId): EnvelopeStatus { return EnvelopeStatus::Completed; }
            public function downloadSigned(string $providerEnvelopeId): \SplFileInfo { return new \SplFileInfo('/dev/null'); }
            public function downloadSignedDocument(string $providerEnvelopeId, string $documentId): \SplFileInfo { return new \SplFileInfo('/dev/null'); }
            public function hasAuditTrail(): bool { return true; }
            public function downloadAudit(string $providerEnvelopeId): \SplFileInfo { return new \SplFileInfo('/dev/null'); }
            public function getFieldValues(string $providerEnvelopeId): array { return []; }
            public function cancel(string $providerEnvelopeId, ?string $reason = null): void {}
        };
    }
}
