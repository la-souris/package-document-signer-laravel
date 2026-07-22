<?php

declare(strict_types=1);

/**
 * Nederlandse labels voor elke DocuSign Connect webhook-gebeurtenis.
 *
 * Sleutels komen exact overeen met de ruwe enum-waarden, zodat een
 * callback-token als "envelope-completed" direct vertaald wordt.
 */

return [
    'envelope-created'                => 'Envelop aangemaakt',
    'envelope-sent'                   => 'Envelop verzonden',
    'envelope-resent'                 => 'Envelop opnieuw verzonden',
    'envelope-delivered'              => 'Envelop geopend',
    'envelope-completed'              => 'Envelop voltooid',
    'envelope-declined'               => 'Ontvanger heeft de envelop geweigerd',
    'envelope-voided'                 => 'Envelop geannuleerd',
    'envelope-corrected'              => 'Envelop gecorrigeerd',
    'envelope-purge'                  => 'Envelop gewist',
    'recipient-sent'                  => 'Naar ontvanger verzonden',
    'recipient-resent'                => 'Opnieuw naar ontvanger verzonden',
    'recipient-delivered'             => 'Ontvanger heeft de envelop geopend',
    'recipient-completed'             => 'Ontvanger heeft ondertekend',
    'recipient-declined'              => 'Ontvanger heeft geweigerd te ondertekenen',
    'recipient-authenticationfailed'  => 'Authenticatie van ontvanger mislukt',
    'recipient-autoresponded'         => 'E-mail van ontvanger gaf automatisch antwoord',
    'recipient-finish-later'          => 'Ontvanger gaat later verder',
    'recipient-delegate'              => 'Ontvanger heeft ondertekening gedelegeerd',
    'recipient-reassign'              => 'Ontvanger opnieuw toegewezen',
];
