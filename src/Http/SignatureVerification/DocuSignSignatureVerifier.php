<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Http\SignatureVerification;

use Illuminate\Http\Request;

/**
 * Verifies DocuSign Connect HMAC signatures.
 *
 * Connect signs each request body with HMAC-SHA256 keyed by the configured
 * shared secret and sends the base64-encoded digest in `X-DocuSign-Signature-1`.
 * If multiple secrets are active, Connect emits one header per secret
 * (`X-DocuSign-Signature-1`, `-2`, ...). A request is authentic if **any**
 * configured secret produces a digest that matches **any** present header.
 */
final class DocuSignSignatureVerifier implements WebhookSignatureVerifier
{
    /** @var list<string> */
    private array $secrets;

    /**
     * @param list<string>|string|null $secrets
     */
    public function __construct(array|string|null $secrets)
    {
        $this->secrets = array_values(array_filter(
            is_array($secrets) ? $secrets : [$secrets ?? ''],
            static fn (mixed $s) => is_string($s) && $s !== '',
        ));
    }

    public function verify(Request $request): bool
    {
        if ($this->secrets === []) {
            return false;
        }

        $body = $request->getContent();
        if (!is_string($body) || $body === '') {
            return false;
        }

        $headers = [];
        for ($i = 1; $i <= 10; $i++) {
            $value = $request->header("X-DocuSign-Signature-{$i}");
            if (is_string($value) && $value !== '') {
                $headers[] = $value;
            }
        }
        if ($headers === []) {
            return false;
        }

        foreach ($this->secrets as $secret) {
            $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));
            foreach ($headers as $given) {
                if (hash_equals($expected, $given)) {
                    return true;
                }
            }
        }

        return false;
    }
}
