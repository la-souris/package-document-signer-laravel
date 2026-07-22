<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Http\SignatureVerification;

use Illuminate\Http\Request;

interface WebhookSignatureVerifier
{
    /**
     * Return true when the request is provably authentic for the given driver,
     * false otherwise. Implementations MUST run in constant time when comparing
     * secrets to avoid timing-leak attacks.
     */
    public function verify(Request $request): bool;
}
