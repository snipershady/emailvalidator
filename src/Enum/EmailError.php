<?php

declare(strict_types=1);

namespace EmailValidator\Enum;

/**
 * Motivo per cui un indirizzo email non ha superato la pipeline di validazione.
 */
enum EmailError: string
{
    case EMPTY_ADDRESS = 'Address is empty';
    case INVALID_ENCODING = 'Invalid UTF-8 encoding';
    case SANITIZE_ALTERED = 'Address contains characters that are not allowed';
    case TOO_LONG = 'Address is too long';
    case INVALID_SYNTAX = 'Invalid syntax';
    case INVALID_FORMAT = 'Invalid format';
    case NULL_MX = 'Domain declares it does not accept email (Null MX)';
    case NO_MX_RECORD = 'No MX record for domain';
    case DNS_FAILURE = 'DNS resolution error';
}
