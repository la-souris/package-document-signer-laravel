<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Tests\RecipientOverride;

use InvalidArgumentException;
use LaSouris\DocumentSigner\Laravel\RecipientOverride\RecipientRewriter;
use LaSouris\DocumentSigner\Sdk\Document\Document;
use LaSouris\DocumentSigner\Sdk\Envelope\Envelope;
use LaSouris\DocumentSigner\Sdk\Signer\Signer;
use LaSouris\DocumentSigner\Sdk\Signer\SigningOrder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RecipientRewriterTest extends TestCase
{
    #[Test]
    public function it_returns_null_when_disabled(): void
    {
        self::assertNull(RecipientRewriter::fromConfig(['enabled' => false]));
        self::assertNull(RecipientRewriter::fromConfig([]));
        self::assertNull(RecipientRewriter::fromConfig(null));
    }

    #[Test]
    public function it_reads_a_truthy_string_enabled_flag(): void
    {
        // env() values arrive as strings; "true"/"1" must enable it.
        self::assertNotNull(RecipientRewriter::fromConfig([
            'enabled'  => 'true',
            'strategy' => 'domain',
            'domain'   => 'you.test',
        ]));
    }

    #[Test]
    public function catch_all_folds_the_original_address_into_a_plus_tag(): void
    {
        $rewriter = RecipientRewriter::fromConfig([
            'enabled'  => true,
            'strategy' => 'catch_all',
            'to'       => 'dev@you.test',
        ]);

        $out = $rewriter->apply($this->envelope([
            new Signer(key: 'a', name: 'Alice Example', email: 'alice@example.com'),
            new Signer(key: 'b', name: 'Bob Example', email: 'bob@example.com'),
        ]));

        // Each signer stays a distinct, deliverable recipient on one inbox.
        self::assertSame('dev+alice=example.com@you.test', $out->signerByKey('a')->email);
        self::assertSame('dev+bob=example.com@you.test', $out->signerByKey('b')->email);
    }

    #[Test]
    public function domain_strategy_keeps_the_local_part(): void
    {
        $rewriter = RecipientRewriter::fromConfig([
            'enabled'  => true,
            'strategy' => 'domain',
            'domain'   => 'you.test',
        ]);

        $out = $rewriter->apply($this->envelope([
            new Signer(key: 'a', name: 'Alice Example', email: 'alice@example.com'),
        ]));

        self::assertSame('alice@you.test', $out->signerByKey('a')->email);
    }

    #[Test]
    public function redirect_strategy_sends_everyone_to_one_address(): void
    {
        $rewriter = RecipientRewriter::fromConfig([
            'enabled'  => true,
            'strategy' => 'redirect',
            'to'       => 'dev@you.test',
        ]);

        $out = $rewriter->apply($this->envelope([
            new Signer(key: 'a', name: 'Alice Example', email: 'alice@example.com'),
        ]));

        self::assertSame('dev@you.test', $out->signerByKey('a')->email);
    }

    #[Test]
    public function it_preserves_every_other_signer_and_envelope_field(): void
    {
        $rewriter = RecipientRewriter::fromConfig([
            'enabled'  => true,
            'strategy' => 'domain',
            'domain'   => 'you.test',
        ]);

        $envelope = new Envelope(
            name:         'Contract',
            documents:    [new Document(id: 'd1', name: 'Doc', html: '<p>{[signature:a:x]}</p>')],
            signers:      [new Signer(key: 'a', name: 'Alice', email: 'alice@example.com', order: 2, language: 'en')],
            emailSubject: 'Please sign',
            emailMessage: 'Body',
            signingOrder: SigningOrder::Sequential,
            metadata:     ['ref' => '123'],
        );

        $out = $rewriter->apply($envelope);
        $signer = $out->signerByKey('a');

        self::assertSame('alice@you.test', $signer->email);
        self::assertSame('a', $signer->key);
        self::assertSame('Alice', $signer->name);
        self::assertSame(2, $signer->order);
        self::assertSame('en', $signer->language);
        self::assertSame('Contract', $out->name);
        self::assertSame('Please sign', $out->emailSubject);
        self::assertSame('Body', $out->emailMessage);
        self::assertSame(SigningOrder::Sequential, $out->signingOrder);
        self::assertSame(['ref' => '123'], $out->metadata);
    }

    #[Test]
    public function only_domains_leaves_real_addresses_untouched(): void
    {
        $rewriter = RecipientRewriter::fromConfig([
            'enabled'      => true,
            'strategy'     => 'domain',
            'domain'       => 'you.test',
            'only_domains' => ['example.com', '*.test'],
        ]);

        $out = $rewriter->apply($this->envelope([
            new Signer(key: 'seed', name: 'Seed User', email: 'seed@example.com'),
            new Signer(key: 'sub', name: 'Sub User', email: 'user@app.test'),
            new Signer(key: 'real', name: 'Real Customer', email: 'customer@gmail.com'),
        ]));

        self::assertSame('seed@you.test', $out->signerByKey('seed')->email, 'exact match rewritten');
        self::assertSame('user@you.test', $out->signerByKey('sub')->email, 'wildcard suffix rewritten');
        self::assertSame('customer@gmail.com', $out->signerByKey('real')->email, 'real address untouched');
    }

    #[Test]
    public function it_returns_the_same_envelope_instance_when_nothing_is_in_scope(): void
    {
        $rewriter = RecipientRewriter::fromConfig([
            'enabled'      => true,
            'strategy'     => 'domain',
            'domain'       => 'you.test',
            'only_domains' => ['example.com'],
        ]);

        $envelope = $this->envelope([
            new Signer(key: 'real', name: 'Real Customer', email: 'customer@gmail.com'),
        ]);

        self::assertSame($envelope, $rewriter->apply($envelope));
    }

    #[Test]
    public function catch_all_without_a_to_address_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("strategy 'catch_all' requires a valid 'to'");

        RecipientRewriter::fromConfig(['enabled' => true, 'strategy' => 'catch_all']);
    }

    #[Test]
    public function domain_strategy_without_a_domain_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("strategy 'domain' requires a bare 'domain'");

        RecipientRewriter::fromConfig(['enabled' => true, 'strategy' => 'domain']);
    }

    #[Test]
    public function an_unknown_strategy_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown document-signer recipient override strategy');

        RecipientRewriter::fromConfig(['enabled' => true, 'strategy' => 'nope', 'to' => 'dev@you.test']);
    }

    /**
     * @param Signer[] $signers
     */
    private function envelope(array $signers): Envelope
    {
        return new Envelope(
            name:         'Contract',
            documents:    [new Document(id: 'd1', name: 'Doc', html: '<p>hi</p>')],
            signers:      $signers,
            emailSubject: 'Please sign',
        );
    }
}
