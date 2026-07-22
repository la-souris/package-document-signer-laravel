<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Tests;

use LaSouris\DocumentSigner\DocuSign\DocuSignProvider;
use LaSouris\DocumentSigner\Laravel\DocumentSignerManager;
use LaSouris\DocumentSigner\Laravel\DocumentSignerServiceProvider;
use LaSouris\DocumentSigner\Laravel\Tests\Fixtures\AcmeSignProvider;
use LaSouris\DocumentSigner\ValidSign\ValidSignProvider;
use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase;

final class DocumentSignerServiceProviderTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $envOverrides = [];

    protected function getPackageProviders($app): array
    {
        return [DocumentSignerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        foreach ($this->envOverrides as $key => $value) {
            $app['config']->set($key, $value);
        }
    }

    public function test_it_binds_the_manager_as_a_singleton(): void
    {
        $a = $this->app->make(DocumentSignerManager::class);
        $b = $this->app->make(DocumentSignerManager::class);

        self::assertSame($a, $b);
        self::assertSame($a, $this->app->make('document-signer'));
    }

    public function test_no_webhook_routes_when_no_secrets_are_configured(): void
    {
        $this->withProviders(validsignSecret: null, docusignSecret: null);

        $routes = $this->routeNames();
        self::assertNotContains('document-signer.webhooks.docusign', $routes);
        self::assertNotContains('document-signer.webhooks.validsign', $routes);
    }

    public function test_only_validsign_webhook_when_only_validsign_secret_is_set(): void
    {
        $this->withProviders(validsignSecret: 'vs-secret', docusignSecret: null);

        $routes = $this->routeNames();
        self::assertContains('document-signer.webhooks.validsign', $routes);
        self::assertNotContains('document-signer.webhooks.docusign', $routes);
    }

    public function test_only_docusign_webhook_when_only_docusign_secret_is_set(): void
    {
        $this->withProviders(validsignSecret: null, docusignSecret: 'ds-secret');

        $routes = $this->routeNames();
        self::assertContains('document-signer.webhooks.docusign', $routes);
        self::assertNotContains('document-signer.webhooks.validsign', $routes);
    }

    public function test_both_webhooks_when_both_secrets_are_set(): void
    {
        $this->withProviders(validsignSecret: 'vs-secret', docusignSecret: 'ds-secret');

        $routes = $this->routeNames();
        self::assertContains('document-signer.webhooks.validsign', $routes);
        self::assertContains('document-signer.webhooks.docusign', $routes);
    }

    public function test_driver_credentials_alone_do_not_enable_webhooks(): void
    {
        // Setting the API key doesn't imply we want to receive webhooks —
        // only the webhook secret does. Guards against a regression that
        // would re-couple the two.
        $this->envOverrides = [
            'document-signer.providers' => [
                [
                    'class'   => ValidSignProvider::class,
                    'config'  => ['api_key' => 'k'],
                    'webhook' => ['callback_secret' => null],
                ],
                [
                    'class'   => DocuSignProvider::class,
                    'config'  => ['integration_key' => 'i'],
                    'webhook' => ['hmac_secret' => null],
                ],
            ],
        ];
        $this->refreshApplication();

        $routes = $this->routeNames();
        self::assertNotContains('document-signer.webhooks.validsign', $routes);
        self::assertNotContains('document-signer.webhooks.docusign', $routes);
    }

    public function test_empty_string_secret_is_treated_as_absent(): void
    {
        // Users often clear the .env value to ''; that should behave the
        // same as never setting it at all.
        $this->withProviders(validsignSecret: '', docusignSecret: null);

        self::assertNotContains('document-signer.webhooks.validsign', $this->routeNames());
    }

    public function test_custom_provider_with_a_secret_registers_a_webhook_route(): void
    {
        $this->envOverrides = [
            'document-signer.providers' => [
                ['class' => AcmeSignProvider::class, 'webhook' => ['secret' => 'acme-secret']],
            ],
        ];
        $this->refreshApplication();

        self::assertContains('document-signer.webhooks.acme', $this->routeNames());
    }

    public function test_custom_provider_without_a_secret_registers_no_route(): void
    {
        $this->envOverrides = [
            'document-signer.providers' => [
                ['class' => AcmeSignProvider::class, 'webhook' => ['secret' => null]],
            ],
        ];
        $this->refreshApplication();

        self::assertNotContains('document-signer.webhooks.acme', $this->routeNames());
    }

    public function test_custom_provider_webhook_verifies_the_request_end_to_end(): void
    {
        // Drives the full path: generated route -> provider name bound as a
        // route default -> WebhookController::custom -> the provider's verifier.
        $this->envOverrides = [
            'document-signer.providers' => [
                ['class' => AcmeSignProvider::class, 'webhook' => ['secret' => 'acme-secret']],
            ],
        ];
        $this->refreshApplication();

        $ok = $this->postJson(
            '/document-signer/webhooks/acme',
            ['status' => 'signed'],
            ['X-Acme-Token' => 'acme-secret'],
        );
        $ok->assertStatus(200);

        $rejected = $this->postJson(
            '/document-signer/webhooks/acme',
            ['status' => 'signed'],
            ['X-Acme-Token' => 'wrong'],
        );
        $rejected->assertStatus(401);
    }

    /**
     * Point `document-signer.providers` at both first-party providers with the
     * given webhook secrets, then rebuild the application so the service
     * provider re-registers routes.
     */
    private function withProviders(?string $validsignSecret, ?string $docusignSecret): void
    {
        $this->envOverrides = [
            'document-signer.providers' => [
                [
                    'class'   => ValidSignProvider::class,
                    'webhook' => ['callback_secret' => $validsignSecret],
                ],
                [
                    'class'   => DocuSignProvider::class,
                    'webhook' => ['hmac_secret' => $docusignSecret],
                ],
            ],
        ];
        $this->refreshApplication();
    }

    /**
     * @return list<string>
     */
    private function routeNames(): array
    {
        /** @var Router $router */
        $router = $this->app->make('router');
        $names = [];
        foreach ($router->getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }
}
