<?php

declare(strict_types=1);

/**
 * Human-readable labels for every ValidSign webhook event.
 *
 * Referenced as `trans('document-signer::validsign-events.PACKAGE_COMPLETE')`
 * (or, via the SDK's WebhookEvent contract, through
 * {@see \LaSouris\DocumentSigner\Laravel\Webhook\EventTranslator::label()}).
 *
 * Keys match the enum's raw values verbatim so a callback token like
 * "PACKAGE_COMPLETE" translates directly.
 */

return [
    'DOCUMENT_VIEWED'            => 'Document viewed',
    'DOCUMENT_SIGNED'            => 'Document signed',
    'EMAIL_BOUNCE'               => 'Notification email bounced',
    'KBA_FAILURE'                => 'Knowledge-based authentication failed',
    'PACKAGE_ACTIVATE'           => 'Package activated',
    'PACKAGE_ARCHIVE'            => 'Package archived',
    'PACKAGE_ATTACHMENT'         => 'Attachment uploaded',
    'PACKAGE_COMPLETE'           => 'Package complete',
    'PACKAGE_CREATE'             => 'Package created',
    'PACKAGE_DEACTIVATE'         => 'Package deactivated',
    'PACKAGE_DECLINE'            => 'Signer declined the package',
    'PACKAGE_DELETE'             => 'Package deleted',
    'PACKAGE_EXPIRE'             => 'Package expired',
    'PACKAGE_OPT_OUT'            => 'Signer opted out of the package',
    'PACKAGE_READY_FOR_COMPLETE' => 'Package awaiting final completion',
    'PACKAGE_RESTORE'            => 'Package restored',
    'PACKAGE_TRASH'              => 'Package moved to trash',
    'ROLE_REASSIGN'              => 'Role reassigned',
    'SIGNER_COMPLETE'            => 'Signer finished signing',
    'SIGNER_LOCKED'              => 'Signer locked out',
    'TEMPLATE_CREATE'            => 'Template created',
];
