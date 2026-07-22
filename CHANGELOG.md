# Changelog

All notable changes to `la-souris/document-signer-laravel` are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-07-22

Initial public release.

### Added

- Laravel integration for the `la-souris/document-signer-sdk`: config, service
  provider, a driver manager, a `DocumentSigner` facade, and webhook routes for
  ValidSign and DocuSign.
- Webhook signature verification per provider (`ValidSignSignatureVerifier`,
  `DocuSignSignatureVerifier`) and a `DocumentSignerWebhookReceived` event.
- Recipient-override support (`OverridingSignatureProvider`, `RecipientRewriter`)
  for redirecting signers in non-production environments.
- Blade components and a Laravel-native PDF renderer (`LaravelPdfRenderer`).
- Event translation for human-readable webhook event names, with English and
  Dutch translations for ValidSign and DocuSign events.
- `anchor_y_offset_pixels` provider config value (env
  `DOCUSIGN_ANCHOR_Y_OFFSET_PIXELS`) passed through to `DocuSignConfig` (positive
  moves every field down the page, negative up; default 0).
- Requires `la-souris/document-signer-sdk` ^1.0 and PHP 8.5+. Install a provider
  package (`la-souris/document-signer-validsign` and/or
  `la-souris/document-signer-docusign`) to enable a driver.
