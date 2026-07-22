<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Http\SignatureVerification;

use Illuminate\Http\Request;

/**
 * Verifies ValidSign callback authenticity by comparing the configured shared
 * secret against the `Authorization: Basic <credentials>` header ValidSign
 * sends with every callback.
 *
 * The credentials are accepted in three shapes to cover the encodings
 * ValidSign has been observed to send:
 *  1. Standard Basic auth: `base64("username:secret")` — we compare against
 *     the password portion.
 *  2. `base64(secret)` — no colon in the decoded value.
 *  3. `<secret>` (unencoded) — some tenants send the raw string.
 *
 * Every comparison runs through `hash_equals` for constant-time safety.
 */
final class ValidSignSignatureVerifier implements WebhookSignatureVerifier
{
    public function __construct(
        private readonly ?string $secret,
    ) {}

    public function verify(Request $request): bool
    {
        if (!is_string($this->secret) || $this->secret === '') {
            return false;
        }

        foreach ($this->extractBasicAuthCredentials($request->header('Authorization')) as $given) {
            if (hash_equals($this->secret, $given)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return every plausible interpretation of the credentials in an
     * `Authorization: Basic <…>` header, so the outer loop can `hash_equals`
     * them all in constant time. Returns an empty array when the header is
     * absent or doesn't start with `Basic `.
     *
     * @return list<string>
     */
    private function extractBasicAuthCredentials(?string $header): array
    {
        if (!is_string($header) || !preg_match('/^\s*Basic\s+(.+)$/i', $header, $m)) {
            return [];
        }

        $encoded = trim($m[1]);
        if ($encoded === '') {
            return [];
        }

        $out = [$encoded]; // raw value, in case the tenant sends the secret unencoded

        $decoded = base64_decode($encoded, strict: true);
        if ($decoded === false || $decoded === '') {
            return $out;
        }

        $out[] = $decoded;

        // Standard Basic auth: username:password. Push the password portion so
        // callers can configure the secret without worrying about the username slot.
        if (str_contains($decoded, ':')) {
            $out[] = substr($decoded, strpos($decoded, ':') + 1);
        }

        return $out;
    }
}
