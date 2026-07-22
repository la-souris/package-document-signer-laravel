<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Tests;

use LaSouris\DocumentSigner\Laravel\DocumentSignerServiceProvider;
use Illuminate\Support\Facades\Blade;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class BladeComponentsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DocumentSignerServiceProvider::class];
    }

    #[Test]
    #[DataProvider('componentCases')]
    public function each_component_compiles_to_the_raw_placeholder_token(
        string $componentTemplate,
        string $expectedToken,
    ): void {
        $rendered = trim(Blade::render($componentTemplate));

        self::assertSame($expectedToken, $rendered);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function componentCases(): iterable
    {
        yield 'signature' => [
            '<x-document-signer::signature signer="counterparty" name="sig" />',
            '{[signature:counterparty:sig]}',
        ];
        yield 'initials' => [
            '<x-document-signer::initials signer="counterparty" name="initials_pg2" />',
            '{[initials:counterparty:initials_pg2]}',
        ];
        yield 'text' => [
            '<x-document-signer::text signer="customer" name="fullname" />',
            '{[text:customer:fullname]}',
        ];
        yield 'date' => [
            '<x-document-signer::date signer="customer" name="signdate" />',
            '{[date:customer:signdate]}',
        ];
        yield 'checkbox' => [
            '<x-document-signer::checkbox signer="customer" name="opt_in" />',
            '{[checkbox:customer:opt_in]}',
        ];
    }

    #[Test]
    public function multiple_components_in_one_template_all_compile(): void
    {
        $rendered = Blade::render(
            '<p><x-document-signer::text signer="c" name="fullname" /></p>'
            . '<p><x-document-signer::signature signer="c" name="sig" /></p>',
        );

        self::assertStringContainsString('{[text:c:fullname]}', $rendered);
        self::assertStringContainsString('{[signature:c:sig]}', $rendered);
    }
}
