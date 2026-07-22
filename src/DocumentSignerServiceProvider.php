<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel;

use LaSouris\DocumentSigner\Laravel\Http\Controllers\WebhookController;
use LaSouris\DocumentSigner\Laravel\Http\Webhook\ProvidesWebhook;
use LaSouris\DocumentSigner\Sdk\Provider\SignatureProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class DocumentSignerServiceProvider extends ServiceProvider
{
    /**
     * Provider names the {@see WebhookController} handles with a dedicated
     * action, mapped to that method. Everything else goes through the generic
     * `custom` action via {@see ProvidesWebhook}.
     *
     * @var array<string, string>
     */
    private const BUILTIN_WEBHOOK_ACTIONS = [
        'validsign' => 'validsign',
        'docusign'  => 'docusign',
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/document-signer.php', 'document-signer');

        $this->app->singleton(DocumentSignerManager::class, static function (Application $app): DocumentSignerManager {
            return new DocumentSignerManager($app);
        });

        $this->app->alias(DocumentSignerManager::class, 'document-signer');
    }

    public function boot(): void
    {
        $this->publishes(
            [__DIR__ . '/../config/document-signer.php' => $this->configPath('document-signer.php')],
            'document-signer-config',
        );

        Blade::anonymousComponentPath(
            __DIR__ . '/../resources/views/components',
            'document-signer',
        );

        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'document-signer');

        $this->publishes(
            [__DIR__ . '/../resources/lang' => $this->langPath('vendor/document-signer')],
            'document-signer-translations',
        );

        $this->registerWebhookRoutes();
    }

    private function registerWebhookRoutes(): void
    {
        $config = $this->app->make('config');

        $routes = $this->webhookRoutes($config);
        if ($routes === []) {
            return;
        }

        $prefix = trim((string) $config->get('document-signer.routing.prefix', 'document-signer/webhooks'), '/');
        $middleware = (array) $config->get('document-signer.routing.middleware', ['api']);

        Route::middleware($middleware)
            ->prefix($prefix)
            ->name('document-signer.webhooks.')
            ->group(static function () use ($routes): void {
                foreach ($routes as $route) {
                    $name = $route['name'];

                    if ($route['custom']) {
                        // App-owned provider: one generic action, name bound as a
                        // route default so it survives `route:cache` (no closure).
                        Route::post($name, [WebhookController::class, 'custom'])
                            ->name($name)
                            ->defaults('provider', $name);
                    } else {
                        Route::post($name, [WebhookController::class, self::BUILTIN_WEBHOOK_ACTIONS[$name]])
                            ->name($name);
                    }
                }
            });
    }

    /**
     * The webhook routes to register: one per provider whose `webhook` block
     * holds a non-empty secret. A webhook with no secret would 401 every
     * request anyway, so secret-presence is the on/off flag.
     *
     * A provider is routable when either the {@see WebhookController} has a
     * dedicated action for its name (the built-in ValidSign/DocuSign path), or
     * its class implements {@see ProvidesWebhook} (the generic custom path).
     * Providers with a secret but neither are skipped — there'd be nothing to
     * verify the callback with.
     *
     * @return list<array{name: string, custom: bool}>
     */
    private function webhookRoutes(Repository $config): array
    {
        $providers = $config->get('document-signer.providers');
        if (!is_array($providers)) {
            return [];
        }

        $out = [];
        foreach ($providers as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $class = $entry['class'] ?? null;
            if (!is_string($class) || !is_a($class, SignatureProvider::class, true) || !defined("{$class}::NAME")) {
                continue;
            }

            $webhook = $entry['webhook'] ?? [];
            if (!is_array($webhook) || !$this->hasSecret($webhook)) {
                continue;
            }

            $name = (string) constant("{$class}::NAME");

            if (isset(self::BUILTIN_WEBHOOK_ACTIONS[$name])) {
                $out[] = ['name' => $name, 'custom' => false];
            } elseif (is_a($class, ProvidesWebhook::class, true)) {
                $out[] = ['name' => $name, 'custom' => true];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $webhook
     */
    private function hasSecret(array $webhook): bool
    {
        foreach ($webhook as $value) {
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }

    private function configPath(string $file): string
    {
        $base = $this->app instanceof Application && method_exists($this->app, 'configPath')
            ? $this->app->configPath($file)
            : base_path('config/' . $file);

        return $base;
    }

    private function langPath(string $sub): string
    {
        if ($this->app instanceof Application && method_exists($this->app, 'langPath')) {
            return $this->app->langPath($sub);
        }

        return base_path('lang/' . $sub);
    }
}
