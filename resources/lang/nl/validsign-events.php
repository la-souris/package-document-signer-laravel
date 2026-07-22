<?php

declare(strict_types=1);

/**
 * Nederlandse vertalingen voor iedere ValidSign webhook event.
 *
 * Gebruik via `trans('document-signer::validsign-events.PACKAGE_COMPLETE')`
 * (of via {@see \LaSouris\DocumentSigner\Laravel\Webhook\EventTranslator::label()}
 * met een expliciete locale-parameter).
 */

return [
    'DOCUMENT_VIEWED'            => 'Document bekeken',
    'DOCUMENT_SIGNED'            => 'Document ondertekend',
    'EMAIL_BOUNCE'               => 'Notificatie-e-mail teruggekomen',
    'KBA_FAILURE'                => 'Kennisverificatie mislukt',
    'PACKAGE_ACTIVATE'           => 'Pakket geactiveerd',
    'PACKAGE_ARCHIVE'            => 'Pakket gearchiveerd',
    'PACKAGE_ATTACHMENT'         => 'Bijlage geüpload',
    'PACKAGE_COMPLETE'           => 'Pakket voltooid',
    'PACKAGE_CREATE'             => 'Pakket aangemaakt',
    'PACKAGE_DEACTIVATE'         => 'Pakket gedeactiveerd',
    'PACKAGE_DECLINE'            => 'Ondertekenaar heeft het pakket geweigerd',
    'PACKAGE_DELETE'             => 'Pakket verwijderd',
    'PACKAGE_EXPIRE'             => 'Pakket verlopen',
    'PACKAGE_OPT_OUT'            => 'Ondertekenaar heeft zich afgemeld',
    'PACKAGE_READY_FOR_COMPLETE' => 'Pakket wacht op laatste voltooiing',
    'PACKAGE_RESTORE'            => 'Pakket hersteld',
    'PACKAGE_TRASH'              => 'Pakket naar prullenbak verplaatst',
    'ROLE_REASSIGN'              => 'Rol opnieuw toegewezen',
    'SIGNER_COMPLETE'            => 'Ondertekenaar heeft ondertekend',
    'SIGNER_LOCKED'              => 'Ondertekenaar geblokkeerd',
    'TEMPLATE_CREATE'            => 'Sjabloon aangemaakt',
];