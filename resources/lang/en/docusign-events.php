<?php

declare(strict_types=1);

/**
 * Human-readable labels for every DocuSign Connect webhook event.
 *
 * Referenced as `trans('document-signer::docusign-events.envelope-completed')`
 * (or, via the SDK's WebhookEvent contract, through
 * {@see \LaSouris\DocumentSigner\Laravel\Webhook\EventTranslator::label()}).
 *
 * Keys match the enum's raw values verbatim so a callback token like
 * "envelope-completed" translates directly.
 */

return [
    'envelope-created'                => 'Envelope created',
    'envelope-sent'                   => 'Envelope sent',
    'envelope-resent'                 => 'Envelope resent',
    'envelope-delivered'              => 'Envelope opened',
    'envelope-completed'              => 'Envelope completed',
    'envelope-declined'               => 'Recipient declined the envelope',
    'envelope-voided'                 => 'Envelope voided',
    'envelope-corrected'              => 'Envelope corrected',
    'envelope-purge'                  => 'Envelope purged',
    'recipient-sent'                  => 'Sent to recipient',
    'recipient-resent'                => 'Resent to recipient',
    'recipient-delivered'             => 'Recipient opened the envelope',
    'recipient-completed'             => 'Recipient finished signing',
    'recipient-declined'              => 'Recipient declined to sign',
    'recipient-authenticationfailed'  => 'Recipient authentication failed',
    'recipient-autoresponded'         => 'Recipient email auto-responded',
    'recipient-finish-later'          => 'Recipient chose to finish later',
    'recipient-delegate'              => 'Recipient delegated signing',
    'recipient-reassign'              => 'Recipient reassigned',
];
