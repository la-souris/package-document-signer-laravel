<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\RecipientOverride;

use InvalidArgumentException;
use LaSouris\DocumentSigner\Sdk\Envelope\Envelope;
use LaSouris\DocumentSigner\Sdk\Signer\Signer;

/**
 * Rewrites signer email addresses on an {@see Envelope} before it is sent.
 *
 * Its reason to exist: some providers (notably ValidSign) reject reserved
 * domains such as `example.com`, yet seeded/development data is full of them.
 * Enabling this in dev/staging lets those envelopes go out to a real inbox you
 * control instead of failing at the provider.
 *
 * Strategies (`document-signer.recipient_override.strategy`):
 *
 *  - `catch_all` — every signer is redirected to `to`, with the original
 *    address folded into a `+tag` so each signer stays a *distinct* recipient
 *    (ValidSign rejects duplicate recipients on one package) while all mail
 *    lands in one inbox. `alice@example.com` → `dev+alice=example.com@you.test`.
 *
 *  - `domain` — the local part is kept and only the domain is swapped for
 *    `domain`. `alice@example.com` → `alice@you.test`. Recipients stay unique;
 *    you need a catch-all inbox on `domain` to actually receive them.
 *
 *  - `redirect` — every signer is sent to `to` verbatim. Simplest, but multiple
 *    signers on one envelope collapse to the same address, which the provider
 *    may reject. Use only for single-signer flows or a provider that tolerates
 *    duplicates.
 *
 * Scope (`only_domains`): when non-empty, only addresses whose domain matches
 * an entry are rewritten — real customer addresses pass through untouched even
 * if the override is left on by mistake. Entries are matched case-insensitively
 * and may use a `*.` prefix for suffix matches (`*.test` matches `foo.test`).
 * An empty list rewrites every address.
 *
 * The class is deliberately provider-agnostic: it only ever touches
 * {@see Signer::$email}, reconstructing the readonly {@see Envelope}/{@see Signer}
 * value objects with every other field preserved.
 */
final class RecipientRewriter
{
    public const STRATEGY_CATCH_ALL = 'catch_all';
    public const STRATEGY_DOMAIN    = 'domain';
    public const STRATEGY_REDIRECT  = 'redirect';

    /**
     * @param list<string> $onlyDomains lower-cased domain patterns; empty = all
     */
    private function __construct(
        private readonly string $strategy,
        private readonly ?string $to,
        private readonly ?string $domain,
        private readonly array $onlyDomains,
    ) {}

    /**
     * Build a rewriter from the `document-signer.recipient_override` config
     * array, or `null` when the override is disabled (the production default).
     *
     * Configuration is validated eagerly: a misconfigured-but-enabled override
     * throws here — on the first send — rather than silently sending mail to the
     * wrong place.
     *
     * @param mixed $config the `recipient_override` config value
     */
    public static function fromConfig(mixed $config): ?self
    {
        if (!is_array($config)) {
            return null;
        }

        if (filter_var($config['enabled'] ?? false, FILTER_VALIDATE_BOOL) !== true) {
            return null;
        }

        $strategy = is_string($config['strategy'] ?? null) && $config['strategy'] !== ''
            ? $config['strategy']
            : self::STRATEGY_CATCH_ALL;

        $to     = self::stringOrNull($config['to'] ?? null);
        $domain = self::stringOrNull($config['domain'] ?? null);

        $onlyDomains = [];
        foreach ((array) ($config['only_domains'] ?? []) as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $onlyDomains[] = strtolower(ltrim(trim($entry), '@'));
            }
        }

        return self::build($strategy, $to, $domain, $onlyDomains);
    }

    /**
     * @param list<string> $onlyDomains
     */
    private static function build(string $strategy, ?string $to, ?string $domain, array $onlyDomains): self
    {
        switch ($strategy) {
            case self::STRATEGY_CATCH_ALL:
            case self::STRATEGY_REDIRECT:
                if ($to === null || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException(sprintf(
                        "document-signer recipient override strategy '%s' requires a valid 'to' address "
                        . '(set DOCUMENT_SIGNER_OVERRIDE_TO). Got: %s',
                        $strategy,
                        $to === null ? 'null' : "'{$to}'",
                    ));
                }
                break;

            case self::STRATEGY_DOMAIN:
                if ($domain === null || $domain === '' || str_contains($domain, '@')) {
                    throw new InvalidArgumentException(sprintf(
                        "document-signer recipient override strategy 'domain' requires a bare 'domain' "
                        . "(set DOCUMENT_SIGNER_OVERRIDE_DOMAIN, e.g. 'you.test'). Got: %s",
                        $domain === null ? 'null' : "'{$domain}'",
                    ));
                }
                break;

            default:
                throw new InvalidArgumentException(sprintf(
                    "Unknown document-signer recipient override strategy: '%s'. Expected '%s', '%s' or '%s'.",
                    $strategy,
                    self::STRATEGY_CATCH_ALL,
                    self::STRATEGY_DOMAIN,
                    self::STRATEGY_REDIRECT,
                ));
        }

        return new self($strategy, $to, $domain, $onlyDomains);
    }

    /**
     * Return a copy of the envelope with every in-scope signer email rewritten.
     * Signers whose address is out of scope are left as-is; when nothing
     * changes the original envelope instance is returned.
     */
    public function apply(Envelope $envelope): Envelope
    {
        $changed = false;
        $signers = [];

        foreach ($envelope->signers as $signer) {
            $rewritten = $this->rewriteSigner($signer);
            $changed = $changed || $rewritten !== $signer;
            $signers[] = $rewritten;
        }

        if (!$changed) {
            return $envelope;
        }

        return new Envelope(
            name:         $envelope->name,
            documents:    $envelope->documents,
            signers:      $signers,
            emailSubject: $envelope->emailSubject,
            emailMessage: $envelope->emailMessage,
            signingOrder: $envelope->signingOrder,
            expiresAt:    $envelope->expiresAt,
            metadata:     $envelope->metadata,
        );
    }

    private function rewriteSigner(Signer $signer): Signer
    {
        $email = $this->rewriteEmail($signer->email);

        if ($email === $signer->email) {
            return $signer;
        }

        return new Signer(
            key:      $signer->key,
            name:     $signer->name,
            email:    $email,
            order:    $signer->order,
            language: $signer->language,
        );
    }

    private function rewriteEmail(string $email): string
    {
        if (!$this->inScope($email)) {
            return $email;
        }

        return match ($this->strategy) {
            self::STRATEGY_REDIRECT  => (string) $this->to,
            self::STRATEGY_DOMAIN    => $this->localPart($email) . '@' . $this->domain,
            self::STRATEGY_CATCH_ALL => $this->catchAll($email),
        };
    }

    private function inScope(string $email): bool
    {
        if ($this->onlyDomains === []) {
            return true;
        }

        $domain = strtolower($this->domainPart($email));
        if ($domain === '') {
            return false;
        }

        foreach ($this->onlyDomains as $pattern) {
            if (str_starts_with($pattern, '*.')) {
                $suffix = substr($pattern, 1); // ".test"
                if (str_ends_with($domain, $suffix)) {
                    return true;
                }
                continue;
            }
            if ($domain === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fold the original address into a `+tag` on the catch-all inbox so each
     * signer remains a distinct, deliverable recipient.
     */
    private function catchAll(string $email): string
    {
        [$local, $domain] = $this->split((string) $this->to);

        $tag = str_replace('@', '=', strtolower($email));
        $tag = preg_replace('/[^a-z0-9.=_-]+/i', '-', $tag) ?? $tag;
        $tag = trim($tag, '-');

        if ($tag === '') {
            return (string) $this->to;
        }

        return $local . '+' . $tag . '@' . $domain;
    }

    private function localPart(string $email): string
    {
        return $this->split($email)[0];
    }

    private function domainPart(string $email): string
    {
        return $this->split($email)[1];
    }

    /**
     * @return array{0: string, 1: string} [localPart, domain]
     */
    private function split(string $email): array
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return [$email, ''];
        }

        return [substr($email, 0, $at), substr($email, $at + 1)];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
