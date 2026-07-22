<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Tests\Http\SignatureVerification;

use LaSouris\DocumentSigner\Laravel\Http\SignatureVerification\DocuSignSignatureVerifier;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocuSignSignatureVerifierTest extends TestCase
{
    #[Test]
    public function it_accepts_a_request_with_a_matching_hmac_header(): void
    {
        $body = '{"event":"completed"}';
        $secret = 'shhh';
        $hmac = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $request = Request::create('/x', 'POST', server: ['HTTP_X_DOCUSIGN_SIGNATURE_1' => $hmac], content: $body);

        self::assertTrue((new DocuSignSignatureVerifier($secret))->verify($request));
    }

    #[Test]
    public function it_rejects_a_request_with_no_signature_header(): void
    {
        $request = Request::create('/x', 'POST', content: '{"event":"completed"}');

        self::assertFalse((new DocuSignSignatureVerifier('shhh'))->verify($request));
    }

    #[Test]
    public function it_rejects_a_request_with_wrong_signature(): void
    {
        $request = Request::create('/x', 'POST',
            server: ['HTTP_X_DOCUSIGN_SIGNATURE_1' => 'not-the-right-hmac'],
            content: '{"event":"completed"}');

        self::assertFalse((new DocuSignSignatureVerifier('shhh'))->verify($request));
    }

    #[Test]
    public function it_supports_secret_rotation_via_multiple_secrets(): void
    {
        $body = 'payload';
        $newSecret = 'new';
        $oldSecret = 'old';
        $hmac = base64_encode(hash_hmac('sha256', $body, $oldSecret, true));

        $request = Request::create('/x', 'POST',
            server: ['HTTP_X_DOCUSIGN_SIGNATURE_1' => $hmac],
            content: $body);

        $verifier = new DocuSignSignatureVerifier([$newSecret, $oldSecret]);
        self::assertTrue($verifier->verify($request));
    }

    #[Test]
    public function it_rejects_when_secret_is_missing_or_empty(): void
    {
        $request = Request::create('/x', 'POST',
            server: ['HTTP_X_DOCUSIGN_SIGNATURE_1' => 'anything'],
            content: 'b');

        self::assertFalse((new DocuSignSignatureVerifier(null))->verify($request));
        self::assertFalse((new DocuSignSignatureVerifier(''))->verify($request));
    }
}
