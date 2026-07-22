<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Tests\Http\SignatureVerification;

use LaSouris\DocumentSigner\Laravel\Http\SignatureVerification\ValidSignSignatureVerifier;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidSignSignatureVerifierTest extends TestCase
{
    #[Test]
    public function it_accepts_authorization_basic_with_standard_userpass_encoding(): void
    {
        // Authorization: Basic base64("validsign:secret-xyz")
        $encoded = base64_encode('validsign:secret-xyz');
        $request = Request::create('/x', 'POST', server: ['HTTP_AUTHORIZATION' => 'Basic ' . $encoded]);

        self::assertTrue((new ValidSignSignatureVerifier('secret-xyz'))->verify($request));
    }

    #[Test]
    public function it_accepts_authorization_basic_with_base64_encoded_secret_only(): void
    {
        // Authorization: Basic base64("secret-xyz")  — no colon in the decoded value
        $encoded = base64_encode('secret-xyz');
        $request = Request::create('/x', 'POST', server: ['HTTP_AUTHORIZATION' => 'Basic ' . $encoded]);

        self::assertTrue((new ValidSignSignatureVerifier('secret-xyz'))->verify($request));
    }

    #[Test]
    public function it_accepts_authorization_basic_with_raw_unencoded_secret(): void
    {
        // Some tenants send the secret verbatim after "Basic ".
        $request = Request::create('/x', 'POST', server: ['HTTP_AUTHORIZATION' => 'Basic secret-xyz']);

        self::assertTrue((new ValidSignSignatureVerifier('secret-xyz'))->verify($request));
    }

    #[Test]
    public function it_rejects_authorization_basic_with_wrong_secret(): void
    {
        $encoded = base64_encode('validsign:wrong-secret');
        $request = Request::create('/x', 'POST', server: ['HTTP_AUTHORIZATION' => 'Basic ' . $encoded]);

        self::assertFalse((new ValidSignSignatureVerifier('secret-xyz'))->verify($request));
    }

    #[Test]
    public function it_ignores_authorization_headers_that_are_not_basic(): void
    {
        $request = Request::create('/x', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer secret-xyz']);

        self::assertFalse((new ValidSignSignatureVerifier('secret-xyz'))->verify($request));
    }

    #[Test]
    public function it_ignores_query_parameters_and_legacy_x_callback_headers(): void
    {
        // Older delivery modes (?token=, X-Callback-Key, X-Callback-Token) are no longer accepted;
        // only Authorization: Basic verifies. This test guards against a regression that would
        // re-open those paths.
        $withQuery  = Request::create('/x?token=secret-xyz', 'POST');
        $withHeader = Request::create('/x', 'POST', server: ['HTTP_X_CALLBACK_KEY' => 'secret-xyz']);

        self::assertFalse((new ValidSignSignatureVerifier('secret-xyz'))->verify($withQuery));
        self::assertFalse((new ValidSignSignatureVerifier('secret-xyz'))->verify($withHeader));
    }

    #[Test]
    public function it_rejects_when_no_authorization_header_is_present(): void
    {
        $request = Request::create('/x', 'POST');
        self::assertFalse((new ValidSignSignatureVerifier('secret-xyz'))->verify($request));
    }

    #[Test]
    public function it_rejects_when_secret_is_missing(): void
    {
        $request = Request::create('/x', 'POST', server: ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('u:anything')]);
        self::assertFalse((new ValidSignSignatureVerifier(null))->verify($request));
        self::assertFalse((new ValidSignSignatureVerifier(''))->verify($request));
    }
}
