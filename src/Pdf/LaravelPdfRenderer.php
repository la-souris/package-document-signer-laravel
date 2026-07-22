<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Pdf;

use LaSouris\DocumentSigner\Sdk\Exception\DocumentSignerException;
use LaSouris\DocumentSigner\Sdk\Pdf\FooterPlacement;
use LaSouris\DocumentSigner\Sdk\Pdf\HeaderPlacement;
use LaSouris\DocumentSigner\Sdk\Pdf\PageDecoration;
use LaSouris\DocumentSigner\Sdk\Pdf\PdfRenderer;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Renders HTML to PDF using spatie/laravel-pdf.
 *
 * The package itself wraps Browsershot under the hood, but provides a Laravel-
 * native fluent API and respects bindings/macros registered against the
 * {@see \Spatie\LaravelPdf\Facades\Pdf} facade — making this renderer the right
 * choice when an application already configures laravel-pdf elsewhere
 * (custom Node binary, headers/footers, paper size defaults, etc.).
 */
final class LaravelPdfRenderer implements PdfRenderer
{
    /**
     * @param \Closure(PdfBuilder):void|null $configure Optional hook for fluent customisation
     *                                                   of the PdfBuilder before the PDF is produced.
     */
    public function __construct(
        private readonly ?\Closure $configure = null,
    ) {}

    public function render(string $html, ?PageDecoration $decoration = null): string
    {
        $body = $this->applyInlineDecoration($html, $decoration);

        try {
            $pdf = Pdf::html($body);

            $this->applyNativeDecoration($pdf, $decoration);

            if ($this->configure !== null) {
                ($this->configure)($pdf);
            }

            $base64 = $pdf->base64();
            $binary = base64_decode($base64, strict: true);

            if ($binary === false || $binary === '') {
                throw new DocumentSignerException('spatie/laravel-pdf returned empty PDF bytes.');
            }

            return $binary;
        } catch (DocumentSignerException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new DocumentSignerException(
                'spatie/laravel-pdf failed to render HTML to PDF: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    private function applyInlineDecoration(string $html, ?PageDecoration $decoration): string
    {
        if ($decoration === null || $decoration->isEmpty()) {
            return $html;
        }

        $body = $html;

        if ($decoration->hasHeader() && $decoration->headerPlacement === HeaderPlacement::FirstPage) {
            $body = $decoration->headerHtml . $body;
        }

        if ($decoration->hasFooter() && $decoration->footerPlacement === FooterPlacement::FirstPage) {
            $body .= $decoration->footerHtml;
        }

        return $body;
    }

    private function applyNativeDecoration(PdfBuilder $pdf, ?PageDecoration $decoration): void
    {
        if ($decoration === null) {
            return;
        }

        if ($decoration->hasHeader() && $decoration->headerPlacement === HeaderPlacement::AllPages) {
            $pdf->headerHtml((string) $decoration->headerHtml);
        }

        if ($decoration->hasFooter() && $decoration->footerPlacement === FooterPlacement::AllPages) {
            $pdf->footerHtml((string) $decoration->footerHtml);
        }
    }
}
