<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use LaSouris\DocumentSigner\Sdk\Webhook\WebhookEvent;

final class DocumentSignerWebhookReceived
{
    use Dispatchable;

    /**
     * @param class-string          $provider Originating provider's class-string (e.g. `ValidSignProvider::class`).
     *                                        Always set — compare with `=== ValidSignProvider::class`; short name is
     *                                        `$event->provider::NAME`.
     * @param array<string, mixed>  $payload  Parsed JSON body. Empty for `application/xml` callbacks; consult `$request` for those.
     * @param Request               $request  Original HTTP request, for callers that need raw access (XML body, headers).
     * @param WebhookEvent|null     $event    Resolved provider-native event. A provider whose enum ships an `Unknown` case
     *                                        always yields a non-null value (unknown tokens resolve to that case) — both
     *                                        first-party providers (ValidSign and DocuSign) do. A custom provider with no
     *                                        `WebhookEvent` enum yields `null`. Safe to call the semantic predicates
     *                                        null-safely: `$event->event?->isCompleted()`.
     */
    public function __construct(
        public readonly string        $provider,
        public readonly array         $payload,
        public readonly Request       $request,
        public readonly ?WebhookEvent $event = null,
    ) {}
}
