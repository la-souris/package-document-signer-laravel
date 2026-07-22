<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Tests\Http\Controllers;

use LaSouris\DocumentSigner\DocuSign\DocuSignProvider;
use LaSouris\DocumentSigner\DocuSign\Webhook\EventType as DocuSignEventType;
use LaSouris\DocumentSigner\Laravel\Events\DocumentSignerWebhookReceived;
use LaSouris\DocumentSigner\Laravel\Http\Controllers\WebhookController;
use LaSouris\DocumentSigner\Laravel\Tests\Fixtures\AcmeSignProvider;
use LaSouris\DocumentSigner\ValidSign\ValidSignProvider;
use LaSouris\DocumentSigner\ValidSign\Webhook\EventType;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WebhookControllerTest extends TestCase
{
    #[Test]
    public function it_dispatches_event_when_docusign_signature_matches(): void
    {
        $body = '{"event":"envelope-completed"}';
        $secret = 'shhh';
        $hmac = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $request = Request::create('/x', 'POST',
            server: [
                'HTTP_X_DOCUSIGN_SIGNATURE_1' => $hmac,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body);

        [$controller, $captured] = $this->buildController(docusignSecret: $secret);

        $response = $controller->docusign($request);

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $captured);
        self::assertInstanceOf(DocumentSignerWebhookReceived::class, $captured[0]);
        self::assertSame(DocuSignProvider::class, $captured[0]->provider);
        // DocuSign now ships an enum, so the token resolves to a semantic case.
        self::assertSame(DocuSignEventType::EnvelopeCompleted, $captured[0]->event);
        self::assertTrue($captured[0]->event->isCompleted());
    }

    #[Test]
    public function it_returns_401_when_docusign_signature_is_invalid(): void
    {
        $request = Request::create('/x', 'POST',
            server: ['HTTP_X_DOCUSIGN_SIGNATURE_1' => 'wrong'],
            content: '{"a":1}');

        [$controller, $captured] = $this->buildController(docusignSecret: 'shhh');

        $response = $controller->docusign($request);

        self::assertSame(401, $response->getStatusCode());
        self::assertCount(0, $captured);
    }

    #[Test]
    public function it_dispatches_event_when_validsign_token_matches(): void
    {
        $body = '{"name":"PACKAGE_COMPLETE","packageId":"x"}';
        $request = Request::create('/x', 'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('validsign:secret-token'),
            ],
            content: $body);

        [$controller, $captured] = $this->buildController(validsignSecret: 'secret-token');

        $response = $controller->validsign($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $captured);
        self::assertSame(ValidSignProvider::class, $captured[0]->provider);
        self::assertSame(['name' => 'PACKAGE_COMPLETE', 'packageId' => 'x'], $captured[0]->payload);
        // Controller resolves the payload's event token against the ValidSign enum
        // before dispatching, so listeners can use the semantic predicates directly.
        self::assertSame(EventType::PackageComplete, $captured[0]->event);
        self::assertTrue($captured[0]->event->isCompleted());
    }

    #[Test]
    public function it_resolves_an_unknown_validsign_token_to_the_unknown_case(): void
    {
        $body = '{"name":"MYSTERY_EVENT"}';
        $request = Request::create('/x', 'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('validsign:secret-token'),
            ],
            content: $body);

        [$controller, $captured] = $this->buildController(validsignSecret: 'secret-token');

        $controller->validsign($request);

        self::assertCount(1, $captured);
        self::assertSame(ValidSignProvider::class, $captured[0]->provider);
        // ValidSign's enum has an Unknown case, so an unmatched token stays non-null
        // and semantically inert; the raw payload is still available.
        self::assertSame(EventType::Unknown, $captured[0]->event);
        self::assertFalse($captured[0]->event->isCompleted());
    }

    #[Test]
    public function it_resolves_an_unknown_docusign_token_to_the_unknown_case(): void
    {
        $body = '{"event":"envelope-something-new"}';
        $secret = 'shhh';
        $hmac = base64_encode(hash_hmac('sha256', $body, $secret, true));

        $request = Request::create('/x', 'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_DOCUSIGN_SIGNATURE_1' => $hmac,
            ],
            content: $body);

        [$controller, $captured] = $this->buildController(docusignSecret: $secret);

        $controller->docusign($request);

        self::assertCount(1, $captured);
        self::assertSame(DocuSignProvider::class, $captured[0]->provider);
        // An unmodelled token stays non-null and semantically inert.
        self::assertSame(DocuSignEventType::Unknown, $captured[0]->event);
        self::assertFalse($captured[0]->event->isCompleted());
    }

    #[Test]
    public function it_returns_401_when_validsign_token_is_wrong(): void
    {
        $request = Request::create('/x', 'POST',
            server: ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('validsign:wrong')],
            content: '{}');

        [$controller, $captured] = $this->buildController(validsignSecret: 'right');

        self::assertSame(401, $controller->validsign($request)->getStatusCode());
        self::assertCount(0, $captured);
    }

    #[Test]
    public function it_dispatches_event_for_a_custom_provider_when_the_signature_matches(): void
    {
        $request = Request::create('/x', 'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_ACME_TOKEN' => 'acme-secret',
            ],
            content: '{"status":"signed"}');

        [$controller, $captured] = $this->buildCustomController('acme-secret');

        $response = $controller->custom($request, 'acme');

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $captured);
        // The custom provider's class-string is carried on the envelope.
        self::assertSame(AcmeSignProvider::class, $captured[0]->provider);
        self::assertSame(['status' => 'signed'], $captured[0]->payload);
        // AcmeSignProvider ships no event enum, so resolveWebhookEvent returns null.
        self::assertNull($captured[0]->event);
    }

    #[Test]
    public function it_returns_401_for_a_custom_provider_when_the_signature_is_invalid(): void
    {
        $request = Request::create('/x', 'POST',
            server: ['HTTP_X_ACME_TOKEN' => 'wrong'],
            content: '{}');

        [$controller, $captured] = $this->buildCustomController('acme-secret');

        self::assertSame(401, $controller->custom($request, 'acme')->getStatusCode());
        self::assertCount(0, $captured);
    }

    #[Test]
    public function it_returns_404_when_the_custom_provider_is_not_configured(): void
    {
        $request = Request::create('/x', 'POST', server: ['HTTP_X_ACME_TOKEN' => 'x'], content: '{}');

        [$controller, $captured] = $this->buildCustomController('acme-secret');

        self::assertSame(404, $controller->custom($request, 'unregistered')->getStatusCode());
        self::assertCount(0, $captured);
    }

    /**
     * Build a controller whose config carries the given webhook secrets in the
     * `document-signer.providers` list (matched by each provider's NAME).
     *
     * @return array{0: WebhookController, 1: \ArrayObject<int, DocumentSignerWebhookReceived>}
     */
    private function buildController(?string $validsignSecret = null, ?string $docusignSecret = null): array
    {
        $config = new Repository([
            'document-signer' => [
                'providers' => [
                    [
                        'class'   => ValidSignProvider::class,
                        'webhook' => ['callback_secret' => $validsignSecret],
                    ],
                    [
                        'class'   => DocuSignProvider::class,
                        'webhook' => ['hmac_secret' => $docusignSecret],
                    ],
                ],
            ],
        ]);

        $dispatcher = new Dispatcher(new Container());
        $captured = new \ArrayObject();
        $dispatcher->listen(DocumentSignerWebhookReceived::class, static function ($event) use ($captured): void {
            $captured[] = $event;
        });

        return [new WebhookController($config, $dispatcher), $captured];
    }

    /**
     * As {@see buildController()}, but configures a single custom provider
     * ({@see AcmeSignProvider}) with the given webhook secret.
     *
     * @return array{0: WebhookController, 1: \ArrayObject<int, DocumentSignerWebhookReceived>}
     */
    private function buildCustomController(?string $secret): array
    {
        $config = new Repository([
            'document-signer' => [
                'providers' => [
                    [
                        'class'   => AcmeSignProvider::class,
                        'webhook' => ['secret' => $secret],
                    ],
                ],
            ],
        ]);

        $dispatcher = new Dispatcher(new Container());
        $captured = new \ArrayObject();
        $dispatcher->listen(DocumentSignerWebhookReceived::class, static function ($event) use ($captured): void {
            $captured[] = $event;
        });

        return [new WebhookController($config, $dispatcher), $captured];
    }
}
