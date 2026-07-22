<?php

declare(strict_types=1);

namespace LaSouris\DocumentSigner\Laravel\Tests\Webhook;

use LaSouris\DocumentSigner\DocuSign\DocuSignProvider;
use LaSouris\DocumentSigner\DocuSign\Webhook\EventType as DocuSignEventType;
use LaSouris\DocumentSigner\Laravel\DocumentSignerServiceProvider;
use LaSouris\DocumentSigner\Laravel\Webhook\EventTranslator;
use LaSouris\DocumentSigner\ValidSign\ValidSignProvider;
use LaSouris\DocumentSigner\ValidSign\Webhook\EventType;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EventTranslatorTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [DocumentSignerServiceProvider::class];
    }

    #[Test]
    public function it_translates_a_validsign_event_using_the_english_fallback(): void
    {
        $this->app->setLocale('en');

        $translator = new EventTranslator($this->app->make('translator'));

        self::assertSame('Package complete',       $translator->label(EventType::PackageComplete, ValidSignProvider::class));
        self::assertSame('Signer declined the package', $translator->label(EventType::PackageDecline, ValidSignProvider::class));
        self::assertSame('Knowledge-based authentication failed', $translator->label(EventType::KbaFailure, ValidSignProvider::class));
    }

    #[Test]
    public function it_translates_a_validsign_event_using_the_dutch_locale(): void
    {
        $translator = new EventTranslator($this->app->make('translator'));

        self::assertSame('Pakket voltooid', $translator->label(EventType::PackageComplete, ValidSignProvider::class, 'nl'));
        self::assertSame(
            'Ondertekenaar heeft het pakket geweigerd',
            $translator->label(EventType::PackageDecline, ValidSignProvider::class, 'nl'),
        );
    }

    #[Test]
    public function it_translates_a_docusign_event_in_both_locales(): void
    {
        $translator = new EventTranslator($this->app->make('translator'));

        self::assertSame(
            'Envelope completed',
            $translator->label(DocuSignEventType::EnvelopeCompleted, DocuSignProvider::class, 'en'),
        );
        self::assertSame(
            'Envelop voltooid',
            $translator->label(DocuSignEventType::EnvelopeCompleted, DocuSignProvider::class, 'nl'),
        );
    }

    #[Test]
    public function every_docusign_case_has_english_and_dutch_translations(): void
    {
        $translator = new EventTranslator($this->app->make('translator'));

        foreach (DocuSignEventType::cases() as $case) {
            if ($case === DocuSignEventType::Unknown) {
                continue; // synthetic sentinel, intentionally untranslated
            }
            foreach (['en', 'nl'] as $locale) {
                self::assertNotSame(
                    $case->value,
                    $translator->label($case, DocuSignProvider::class, $locale),
                    sprintf('Missing %s translation for %s', $locale, $case->value),
                );
            }
        }
    }

    #[Test]
    public function it_falls_back_to_the_raw_provider_token_when_no_translation_exists(): void
    {
        $translator = new EventTranslator($this->app->make('translator'));

        // The synthetic Unknown case has no translation entry.
        // Never returns Laravel's "translation missing" key — always a printable label.
        self::assertSame(EventType::Unknown->value, $translator->label(EventType::Unknown, ValidSignProvider::class));
    }

    #[Test]
    public function every_validsign_case_has_an_english_translation(): void
    {
        $translator = new EventTranslator($this->app->make('translator'));

        foreach (EventType::cases() as $case) {
            if ($case === EventType::Unknown) {
                continue; // synthetic sentinel, intentionally untranslated
            }
            $label = $translator->label($case, ValidSignProvider::class, 'en');
            self::assertNotSame(
                $case->value,
                $label,
                sprintf('Missing English translation for %s', $case->value),
            );
        }
    }

    #[Test]
    public function every_validsign_case_has_a_dutch_translation(): void
    {
        $translator = new EventTranslator($this->app->make('translator'));

        foreach (EventType::cases() as $case) {
            if ($case === EventType::Unknown) {
                continue; // synthetic sentinel, intentionally untranslated
            }
            $label = $translator->label($case, ValidSignProvider::class, 'nl');
            self::assertNotSame(
                $case->value,
                $label,
                sprintf('Missing Dutch translation for %s', $case->value),
            );
        }
    }
}
