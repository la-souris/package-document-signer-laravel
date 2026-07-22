<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel;

use LaSouris\DocumentSigner\DocuSign\DocuSignConfig;
use LaSouris\DocumentSigner\DocuSign\DocuSignProvider;
use LaSouris\DocumentSigner\Laravel\Pdf\LaravelPdfRenderer;
use LaSouris\DocumentSigner\Laravel\RecipientOverride\OverridingSignatureProvider;
use LaSouris\DocumentSigner\Laravel\RecipientOverride\RecipientRewriter;
use LaSouris\DocumentSigner\Sdk\Pdf\BrowsershotPdfRenderer;
use LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider;
use LaSouris\DocumentSigner\ValidSign\ValidSignConfig;
use LaSouris\DocumentSigner\ValidSign\ValidSignProvider;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Driver-based manager for the SDK's {@see SignatureProvider}.
 *
 * Modelled on Laravel's {@see \Illuminate\Support\Manager}, but typed: every
 * resolved driver is guaranteed to implement {@see SignatureProvider}, so
 * static analysis and IDE completion work end-to-end.
 *
 * Providers are configured as a list under `document-signer.providers`, each
 * entry naming a `class`. A provider's short name — used by `driver('...')`,
 * the `default` selector and webhook routing — is the `NAME` constant on that
 * class, so there is no separate key to keep in sync.
 *
 * @method \LaSouris\DocumentSigner\Sdk\Provider\EnvelopeReceipt send(\LaSouris\DocumentSigner\Sdk\Envelope\Envelope $envelope)
 * @method \LaSouris\DocumentSigner\Sdk\Envelope\EnvelopeStatus getStatus(string $providerEnvelopeId)
 * @method string downloadSigned(string $providerEnvelopeId)
 * @method \SplFileInfo downloadSignedDocument(string $providerEnvelopeId, string $documentId)
 * @method bool   hasAuditTrail()
 * @method void   cancel(string $providerEnvelopeId, ?string $reason = null)
 */
class DocumentSignerManager
{
    /**
     * Per-class primary credential key. A provider counts as "configured" (and
     * so eligible for auto-selection as the default) when this key is set in
     * its `config`. Custom providers with no entry here count as configured
     * whenever they carry any config at all.
     *
     * @var array<class-string<SignatureProvider>, string>
     */
    private const PRIMARY_CREDENTIAL = [
        ValidSignProvider::class => 'api_key',
        DocuSignProvider::class  => 'integration_key',
    ];

    /** @var array<string, SignatureProvider> */
    private array $drivers = [];

    /** @var array<string, \Closure(Container, array<string, mixed>): SignatureProvider> */
    private array $customCreators = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function driver(?string $name = null): SignatureProvider
    {
        $name ??= $this->getDefaultDriver();

        return $this->drivers[$name] ??= $this->withRecipientOverride($this->resolve($name));
    }

    /**
     * Wrap a resolved provider so signer emails are rewritten before every
     * send, when `document-signer.recipient_override.enabled` is on. Returns the
     * provider untouched otherwise — production pays no overhead.
     *
     * Drivers seeded via {@see set()} bypass this on purpose: tests inject an
     * exact instance and expect it back.
     */
    private function withRecipientOverride(SignatureProvider $provider): SignatureProvider
    {
        $rewriter = RecipientRewriter::fromConfig($this->config('document-signer.recipient_override'));

        return $rewriter === null
            ? $provider
            : new OverridingSignatureProvider($provider, $rewriter);
    }

    /**
     * Register a custom driver factory (used for tests, third-party providers).
     *
     * @param \Closure(Container, array<string, mixed>): SignatureProvider $factory
     */
    public function extend(string $name, \Closure $factory): self
    {
        $this->customCreators[$name] = $factory;
        unset($this->drivers[$name]);

        return $this;
    }

    /**
     * Replace (or pre-seed) a resolved driver instance. Useful for tests.
     */
    public function set(string $name, SignatureProvider $provider): self
    {
        $this->drivers[$name] = $provider;

        return $this;
    }

    public function forgetDrivers(): self
    {
        $this->drivers = [];

        return $this;
    }

    public function getDefaultDriver(): string
    {
        $explicit = $this->config('document-signer.default');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $configured = $this->configuredDrivers();

        return match (count($configured)) {
            1 => $configured[0],
            0 => throw new InvalidArgumentException(
                'No document-signer driver is configured. Set at least one provider credential '
                . '(VALIDSIGN_API_KEY, DOCUSIGN_INTEGRATION_KEY, ...) or set DOCUMENT_SIGNER_DRIVER explicitly.'
            ),
            default => throw new InvalidArgumentException(sprintf(
                'Multiple document-signer drivers are configured (%s). '
                . 'Set DOCUMENT_SIGNER_DRIVER to pick one.',
                implode(', ', $configured),
            )),
        };
    }

    /**
     * Names of the configured providers whose primary credential is present.
     *
     * @return list<string>
     */
    public function configuredDrivers(): array
    {
        $configured = [];
        foreach ($this->providerList() as $entry) {
            if ($this->isConfigured($entry['class'], $entry['config'])) {
                $configured[] = $entry['name'];
            }
        }

        sort($configured);

        return $configured;
    }

    /**
     * @param class-string<SignatureProvider> $class
     * @param array<string, mixed>            $config
     */
    private function isConfigured(string $class, array $config): bool
    {
        $credentialKey = self::PRIMARY_CREDENTIAL[$class] ?? null;

        if ($credentialKey === null) {
            // Custom provider: presence of any config is enough to count it.
            return $config !== [];
        }

        $value = $config[$credentialKey] ?? null;

        return is_string($value) && $value !== '';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->driver()->$method(...$arguments);
    }

    private function resolve(string $name): SignatureProvider
    {
        if (isset($this->customCreators[$name])) {
            $entry = $this->findEntry($name);

            return ($this->customCreators[$name])($this->container, $entry['config'] ?? []);
        }

        $entry = $this->requireEntry($name);

        return match ($entry['class']) {
            ValidSignProvider::class => $this->createValidSignDriver($entry['config']),
            DocuSignProvider::class  => $this->createDocuSignDriver($entry['config']),
            default                  => $this->createConfiguredDriver($entry['class'], $entry['config']),
        };
    }

    /**
     * Build an app-owned provider through the container, so its own
     * dependencies auto-wire. Two constructor arguments are supplied by name
     * when the provider declares them:
     *  - `$config`      the entry's `config` array, so a provider can take its
     *                   credentials straight from configuration.
     *  - `$pdfRenderer` the integration-managed {@see PdfRenderer}, for
     *                   providers that render documents themselves.
     * Either may be omitted from the constructor; unused arguments are ignored.
     *
     * This is the extension point for providers that shouldn't ship in this
     * package: add an entry to `document-signer.providers` whose `class` points
     * at your {@see SignatureProvider} and select it by its `NAME`.
     *
     * @param class-string<SignatureProvider> $class
     * @param array<string, mixed>            $config
     */
    private function createConfiguredDriver(string $class, array $config): SignatureProvider
    {
        return $this->container->make($class, [
            'config'      => $config,
            'pdfRenderer' => $this->resolvePdfRenderer(),
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createValidSignDriver(array $config): SignatureProvider
    {
        if (!class_exists(ValidSignProvider::class)) {
            throw new InvalidArgumentException(
                'The validsign driver requires documentsigner/validsign. Install it with: composer require documentsigner/validsign'
            );
        }

        $apiKey = (string) ($config['api_key'] ?? '');
        if ($apiKey === '') {
            throw new InvalidArgumentException(
                'ValidSign API key missing. Set VALIDSIGN_API_KEY or the api_key of the validsign provider entry.'
            );
        }

        return new ValidSignProvider(
            new ValidSignConfig(
                apiKey:               $apiKey,
                baseUrl:              (string) ($config['base_url'] ?? 'https://my.validsign.nl/api'),
                defaultLanguage:      (string) ($config['default_language'] ?? 'nl'),
                timeoutSeconds:       (int)    ($config['timeout'] ?? 15),
                uploadTimeoutSeconds: (int)    ($config['upload_timeout'] ?? 60),
            ),
            pdfRenderer: $this->resolvePdfRenderer(),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function createDocuSignDriver(array $config): SignatureProvider
    {
        if (!class_exists(DocuSignProvider::class)) {
            throw new InvalidArgumentException(
                'The docusign driver requires documentsigner/docusign. Install it with: composer require documentsigner/docusign'
            );
        }

        $privateKey = $this->resolvePrivateKey($config);

        return new DocuSignProvider(
            new DocuSignConfig(
                integrationKey:        (string) ($config['integration_key'] ?? ''),
                userId:                (string) ($config['user_id'] ?? ''),
                accountId:             (string) ($config['account_id'] ?? ''),
                privateKey:            $privateKey,
                oauthBaseUrl:          (string) ($config['oauth_base_url'] ?? 'account-d.docusign.com'),
                apiBaseUrl:            (string) ($config['api_base_url'] ?? 'https://demo.docusign.net/restapi'),
                scopes:                (string) ($config['scopes'] ?? 'signature impersonation'),
                accessTokenTtlSeconds: (int)    ($config['access_token_ttl'] ?? 3600),
                timeoutSeconds:        (int)    ($config['timeout'] ?? 15),
                uploadTimeoutSeconds:  (int)    ($config['upload_timeout'] ?? 60),
                anchorYOffsetPixels:   (int)    ($config['anchor_y_offset_pixels'] ?? 0),
            ),
            pdfRenderer: $this->resolvePdfRenderer(),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolvePrivateKey(array $config): string
    {
        $path = $config['private_key_path'] ?? null;
        if (is_string($path) && $path !== '') {
            if (!is_readable($path)) {
                throw new InvalidArgumentException("DocuSign private key file is not readable: '{$path}'.");
            }
            return (string) file_get_contents($path);
        }

        $inline = $config['private_key'] ?? null;
        if (is_string($inline) && $inline !== '') {
            return $inline;
        }

        throw new InvalidArgumentException(
            'DocuSign private key missing. Set DOCUSIGN_PRIVATE_KEY_PATH or DOCUSIGN_PRIVATE_KEY.'
        );
    }

    /**
     * The configured provider entry for a short name, or `null` when none match.
     *
     * @return array{name: string, class: class-string<SignatureProvider>, config: array<string, mixed>, webhook: array<string, mixed>}|null
     */
    private function findEntry(string $name): ?array
    {
        foreach ($this->providerList() as $entry) {
            if ($entry['name'] === $name) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @return array{name: string, class: class-string<SignatureProvider>, config: array<string, mixed>, webhook: array<string, mixed>}
     */
    private function requireEntry(string $name): array
    {
        $entry = $this->findEntry($name);

        if ($entry === null) {
            throw new InvalidArgumentException(
                "Unknown document-signer driver: '{$name}'. Add an entry to document-signer.providers whose "
                . 'class exposes a matching NAME constant, or register it via DocumentSignerManager::extend().'
            );
        }

        return $entry;
    }

    /**
     * Normalised list of configured providers, keyed positionally.
     *
     * Entries whose `class` is absent or does not implement
     * {@see SignatureProvider} are skipped — referencing an uninstalled
     * provider in config is harmless. Entries whose class lacks a `NAME`
     * constant are a misconfiguration and throw.
     *
     * @return list<array{name: string, class: class-string<SignatureProvider>, config: array<string, mixed>, webhook: array<string, mixed>}>
     */
    private function providerList(): array
    {
        $providers = $this->config('document-signer.providers');
        if (!is_array($providers)) {
            return [];
        }

        $out = [];
        foreach ($providers as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $class = $entry['class'] ?? null;
            if (!is_string($class) || !is_a($class, SignatureProvider::class, true)) {
                continue;
            }

            $out[] = [
                'name'    => $this->providerName($class),
                'class'   => $class,
                'config'  => is_array($entry['config'] ?? null) ? $entry['config'] : [],
                'webhook' => is_array($entry['webhook'] ?? null) ? $entry['webhook'] : [],
            ];
        }

        return $out;
    }

    /**
     * @param class-string<SignatureProvider> $class
     */
    private function providerName(string $class): string
    {
        if (!defined("{$class}::NAME")) {
            throw new InvalidArgumentException(sprintf(
                'Provider %s must declare a `public const string NAME` to be listed in document-signer.providers.',
                $class,
            ));
        }

        return (string) constant("{$class}::NAME");
    }

    private function config(string $key): mixed
    {
        /** @var \Illuminate\Contracts\Config\Repository $repo */
        $repo = $this->container->make('config');

        return $repo->get($key);
    }

    /**
     * Decide which {@see PdfRenderer} every driver should use.
     *
     * Resolution order:
     *  1. A binding for the {@see PdfRenderer} interface in the container — when present
     *     the caller has fully replaced the renderer, including any constructor wiring.
     *  2. The `document-signer.pdf.renderer` config value, which selects between the
     *     two built-in renderers (`browsershot`, `laravel-pdf`). Default: `browsershot`.
     */
    private function resolvePdfRenderer(): PdfRenderer
    {
        if ($this->container->bound(PdfRenderer::class)) {
            $bound = $this->container->make(PdfRenderer::class);
            if (!$bound instanceof PdfRenderer) {
                throw new InvalidArgumentException(sprintf(
                    'Binding for %s must implement %s.',
                    PdfRenderer::class,
                    PdfRenderer::class,
                ));
            }
            return $bound;
        }

        $choice = $this->config('document-signer.pdf.renderer');
        $choice = is_string($choice) && $choice !== '' ? $choice : 'browsershot';

        return match ($choice) {
            'browsershot' => $this->createBrowsershotRenderer(),
            'laravel-pdf' => $this->createLaravelPdfRenderer(),
            default       => throw new InvalidArgumentException(
                "Unknown document-signer PDF renderer: '{$choice}'. "
                . "Expected 'browsershot' or 'laravel-pdf'."
            ),
        };
    }

    private function createBrowsershotRenderer(): PdfRenderer
    {
        if (!class_exists(\Spatie\Browsershot\Browsershot::class)) {
            throw new InvalidArgumentException(
                'The browsershot renderer requires spatie/browsershot. '
                . 'Install it with: composer require spatie/browsershot'
            );
        }

        return new BrowsershotPdfRenderer();
    }

    private function createLaravelPdfRenderer(): PdfRenderer
    {
        if (!class_exists(\Spatie\LaravelPdf\Facades\Pdf::class)) {
            throw new InvalidArgumentException(
                'The laravel-pdf renderer requires spatie/laravel-pdf. '
                . 'Install it with: composer require spatie/laravel-pdf'
            );
        }

        return new LaravelPdfRenderer();
    }
}
