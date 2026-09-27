<?php

declare(strict_types=1);

namespace EmailValidator\Service;

use EmailValidator\Enum\EmailError;

/**
 * Risolve i record MX tramite le funzioni DNS native di PHP, con rilevamento
 * del Null MX (RFC 7505) e supporto opzionale all'implicit MX (RFC 5321 §5.1).
 */
final readonly class DnsMxResolver implements MxResolver
{
    public function __construct(
        /** Se true, in assenza di MX accetta un record A/AAAA (implicit MX) */
        private bool $allowImplicitMx = false,
    ) {
    }

    public function resolve(string $domain): array|EmailError
    {
        // Il punto finale rende il nome FQDN ed evita l'append dei search domain del resolver
        $fqdn = $domain . '.';

        if (!checkdnsrr($fqdn, 'MX')) {
            if ($this->allowImplicitMx && (checkdnsrr($fqdn, 'A') || checkdnsrr($fqdn, 'AAAA'))) {
                return [$domain];
            }

            return EmailError::NO_MX_RECORD;
        }

        // dns_get_record emette un warning in caso di errore: lo intercettiamo senza usare '@'
        set_error_handler(static fn (int $errno, string $errstr): bool => true);

        try {
            $records = dns_get_record($fqdn, DNS_MX);
        } finally {
            restore_error_handler();
        }

        if (false === $records || [] === $records) {
            return EmailError::DNS_FAILURE;
        }

        /** @var list<array{target?: string, pri?: int}> $records */

        // Null MX (RFC 7505): un unico record con target vuoto o "."
        foreach ($records as $record) {
            $target = rtrim($record['target'] ?? '', '.');
            if ('' === $target) {
                return EmailError::NULL_MX;
            }
        }

        usort(
            $records,
            static fn (array $a, array $b): int => ($a['pri'] ?? 0) <=> ($b['pri'] ?? 0),
        );

        return array_values(array_map(
            static fn (array $record): string => rtrim($record['target'] ?? '', '.'),
            $records,
        ));
    }
}
