<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Tests\Pdf;

use LaSouris\DocumentSigner\Laravel\Pdf\LaravelPdfRenderer;
use LaSouris\DocumentSigner\Sdk\Exception\DocumentSignerException;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelPdf\Facades\Pdf;

final class LaravelPdfRendererTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [\Spatie\LaravelPdf\PdfServiceProvider::class];
    }

    #[Test]
    public function it_drives_the_pdf_facade_with_the_provided_html(): void
    {
        $fake = Pdf::fake();

        try {
            (new LaravelPdfRenderer())->render('<p>Hello signer</p>');
        } catch (DocumentSignerException) {
            // The fake's base64() returns empty bytes; the renderer raises a
            // DocumentSignerException because of that. We only care that the
            // facade chain was driven all the way to Pdf::html() first.
        }

        self::assertSame('<p>Hello signer</p>', $fake->html);
    }

    #[Test]
    public function it_invokes_the_configure_closure_with_the_pdf_builder(): void
    {
        Pdf::fake();
        $received = null;

        try {
            (new LaravelPdfRenderer(
                configure: function ($pdfBuilder) use (&$received): void {
                    $received = $pdfBuilder;
                },
            ))->render('<p>x</p>');
        } catch (DocumentSignerException) {
            // empty-bytes path — see test above.
        }

        self::assertInstanceOf(\Spatie\LaravelPdf\PdfBuilder::class, $received);
    }
}
