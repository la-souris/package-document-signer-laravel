<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Tests\Pdf;

use LaSouris\DocumentSigner\Laravel\DocumentSignerManager;
use LaSouris\DocumentSigner\Laravel\Pdf\LaravelPdfRenderer;
use LaSouris\DocumentSigner\Sdk\Pdf\BrowsershotPdfRenderer;
use LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer;
use LaSouris\DocumentSigner\ValidSign\ValidSignProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies how DocumentSignerManager picks a PdfRenderer for the providers it
 * resolves. We inspect the wired-in renderer by reflection so the tests don't
 * need real provider credentials or the underlying HTTP stack.
 */
final class PdfRendererResolutionTest extends TestCase
{
    #[Test]
    public function it_defaults_to_the_browsershot_renderer(): void
    {
        $manager = $this->makeManager(['pdf' => []]);

        $renderer = $this->extractRenderer($manager->driver('validsign'));

        self::assertInstanceOf(BrowsershotPdfRenderer::class, $renderer);
    }

    #[Test]
    public function it_uses_the_laravel_pdf_renderer_when_configured(): void
    {
        if (!class_exists(\Spatie\LaravelPdf\Facades\Pdf::class)) {
            self::markTestSkipped('spatie/laravel-pdf is not installed in this environment.');
        }

        $manager = $this->makeManager(['pdf' => ['renderer' => 'laravel-pdf']]);

        $renderer = $this->extractRenderer($manager->driver('validsign'));

        self::assertInstanceOf(LaravelPdfRenderer::class, $renderer);
    }

    #[Test]
    public function it_throws_a_helpful_error_when_laravel_pdf_is_selected_but_not_installed(): void
    {
        if (class_exists(\Spatie\LaravelPdf\Facades\Pdf::class)) {
            self::markTestSkipped('spatie/laravel-pdf IS installed; cannot exercise the missing-package guard.');
        }

        $manager = $this->makeManager(['pdf' => ['renderer' => 'laravel-pdf']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('composer require spatie/laravel-pdf');

        $manager->driver('validsign');
    }

    #[Test]
    public function it_throws_a_helpful_error_when_browsershot_is_selected_but_not_installed(): void
    {
        if (class_exists(\Spatie\Browsershot\Browsershot::class)) {
            self::markTestSkipped('spatie/browsershot IS installed; cannot exercise the missing-package guard.');
        }

        $manager = $this->makeManager(['pdf' => ['renderer' => 'browsershot']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('composer require spatie/browsershot');

        $manager->driver('validsign');
    }

    #[Test]
    public function unknown_renderer_value_is_rejected(): void
    {
        $manager = $this->makeManager(['pdf' => ['renderer' => 'wkhtmltopdf']]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown document-signer PDF renderer: 'wkhtmltopdf'");

        $manager->driver('validsign');
    }

    #[Test]
    public function a_container_binding_wins_over_the_config_choice(): void
    {
        $custom = new class implements PdfRenderer {
            public function render(string $html, ?\LaSouris\DocumentSigner\Sdk\Pdf\PageDecoration $decoration = null): string { return 'CUSTOM'; }
        };

        $container = new Container();
        $container->instance('config', new Repository([
            'document-signer' => [
                'default'   => 'validsign',
                'providers' => [['class' => ValidSignProvider::class, 'config' => ['api_key' => 'k']]],
                'pdf'       => ['renderer' => 'laravel-pdf'],
            ],
        ]));
        $container->instance(PdfRenderer::class, $custom);

        $manager = new DocumentSignerManager($container);
        $renderer = $this->extractRenderer($manager->driver('validsign'));

        self::assertSame($custom, $renderer);
    }

    /**
     * @param array<string, mixed> $extraDocumentSignerConfig
     */
    private function makeManager(array $extraDocumentSignerConfig): DocumentSignerManager
    {
        $container = new Container();
        $container->instance('config', new Repository([
            'document-signer' => array_merge([
                'default'   => 'validsign',
                'providers' => [['class' => ValidSignProvider::class, 'config' => ['api_key' => 'k']]],
            ], $extraDocumentSignerConfig),
        ]));

        return new DocumentSignerManager($container);
    }

    private function extractRenderer(object $provider): PdfRenderer
    {
        $value = (new \ReflectionObject($provider))
            ->getProperty('pdfRenderer')
            ->getValue($provider);

        self::assertInstanceOf(PdfRenderer::class, $value);

        return $value;
    }
}
