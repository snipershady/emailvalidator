<?php

declare(strict_types=1);

namespace EmailValidator\Service;

use EmailValidator\Enum\EmailError;

/**
 * Risolve i record MX tramite le funzioni DNS native di PHP, con rilevamento
 * del Null MX (RFC 7505) e supporto opzionale all'implicit MX (RFC 5321 §5.1).
 *
 * Distingue "il record non esiste" (NXDOMAIN/NODATA → dns_get_record()
 * restituisce []) da "il DNS non ha risposto" (SERVFAIL/timeout → false):
 * un guasto transitorio produce {@see EmailError::DNS_FAILURE}, mai
 * {@see EmailError::NO_MX_RECORD}, così un indirizzo reale non viene
 * scartato come definitivamente invalido.
 *
 * Timeout e numero di tentativi dipendono dal resolver di sistema
 * (options timeout/attempts in /etc/resolv.conf): le funzioni DNS native non
 * permettono di impostarli. Per un controllo più fine implementare
 * {@see MxResolver} con una libreria DNS dedicata.
 */
final readonly class DnsMxResolver implements MxResolver
{
    // TLD alfabetico o IDN in punycode, come in EmailValidator: esclude i
    // target numerici che la libc interpreta come IP (127.1, 0177.0.0.1, 0x7f.1)
    private const string TLD_REGEX = '/^(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/Di';

    public function __construct(
        /** Se true, in assenza di MX accetta un record A/AAAA (implicit MX) */
        private bool $allowImplicitMx = false,
        /**
         * Attivo di default: scarta gli host MX che non risolvono verso almeno
         * un indirizzo pubblico o che risolvono anche verso indirizzi privati,
         * loopback, link-local o riservati (vedi {@see FILTER_FLAG_GLOBAL_RANGE}),
         * così {@see resolve()} non restituisce mai host che portano a SSRF
         * verso la rete interna se il client vi si connette (es. verifica
         * SMTP). Costa una query A e una AAAA per ogni host. Va disattivato
         * solo se i server di posta legittimi sono su indirizzi privati (es.
         * validazione di indirizzi di una rete aziendale interna).
         */
        private bool $rejectNonPublicHosts = true,
    ) {
    }

    #[\Override]
    public function resolve(string $domain): array|EmailError
    {
        // Il punto finale rende il nome FQDN ed evita l'append dei search domain del resolver
        $fqdn = $domain . '.';

        $records = $this->query($fqdn, DNS_MX);

        if (false === $records) {
            return EmailError::DNS_FAILURE;
        }

        if ([] === $records) {
            return $this->resolveImplicitMx($domain);
        }

        $hosts = [];
        $nullMx = false;

        foreach ($records as $record) {
            if (!is_array($record) || !isset($record['target']) || !is_string($record['target'])) {
                continue;
            }

            $target = rtrim($record['target'], '.');

            if ('' === $target) {
                $nullMx = true;
                continue;
            }

            if (!$this->isValidMxTarget($target)) {
                continue;
            }

            $priority = isset($record['pri']) && is_int($record['pri']) ? $record['pri'] : 0;
            $hosts[] = ['host' => $target, 'pri' => $priority];
        }

        if ([] === $hosts) {
            // Null MX (RFC 7505): nessun host reale, solo target vuoti o "."
            return $nullMx ? EmailError::NULL_MX : EmailError::NO_MX_RECORD;
        }

        // Un Null MX mescolato ad altri MX viola RFC 7505 §3: il record vuoto
        // viene ignorato e si usano gli host reali.
        usort($hosts, static fn (array $a, array $b): int => $a['pri'] <=> $b['pri']);

        return $this->filterHosts(array_column($hosts, 'host'));
    }

    /**
     * RFC 5321 §5.1: il target di un MX è un nome di dominio, mai un address
     * literal. FILTER_FLAG_HOSTNAME da solo accetta "127.0.0.1", "10.0.0.1" e
     * "localhost", oltre a forme numeriche non canoniche ("127.1",
     * "0177.0.0.1", "2130706433") che FILTER_VALIDATE_IP non riconosce ma che
     * getaddrinfo()/inet_aton() risolvono comunque verso un IP: si richiedono
     * quindi almeno due label e un TLD alfabetico. Non sostituisce
     * $rejectNonPublicHosts: un nome può sempre risolvere verso un indirizzo
     * privato.
     */
    private function isValidMxTarget(string $target): bool
    {
        if (false === filter_var($target, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return false;
        }

        $dot = strrpos($target, '.');

        return false !== $dot && 1 === preg_match(self::TLD_REGEX, substr($target, $dot + 1));
    }

    /**
     * @return list<string>|EmailError
     */
    private function resolveImplicitMx(string $domain): array|EmailError
    {
        if (!$this->allowImplicitMx) {
            return EmailError::NO_MX_RECORD;
        }

        $addresses = $this->addressesOf($domain);

        if (false === $addresses) {
            return EmailError::DNS_FAILURE;
        }

        if ([] === $addresses) {
            return EmailError::NO_MX_RECORD;
        }

        return $this->filterHosts([$domain]);
    }

    /**
     * @param list<string> $hosts
     *
     * @return list<string>|EmailError
     */
    private function filterHosts(array $hosts): array|EmailError
    {
        if (!$this->rejectNonPublicHosts) {
            return $hosts;
        }

        $safe = [];
        $dnsFailure = false;

        foreach ($hosts as $host) {
            $addresses = $this->addressesOf($host);

            if (false === $addresses) {
                $dnsFailure = true;
                continue;
            }

            if ([] === $addresses) {
                continue;
            }

            foreach ($addresses as $address) {
                if (false === filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
                    continue 2;
                }
            }

            $safe[] = $host;
        }

        if ([] !== $safe) {
            return $safe;
        }

        return $dnsFailure ? EmailError::DNS_FAILURE : EmailError::NON_PUBLIC_MX_HOST;
    }

    /**
     * Indirizzi IPv4 e IPv6 di $host; false se una delle due query fallisce.
     *
     * @return list<string>|false
     */
    private function addressesOf(string $host): array|false
    {
        $fqdn = $host . '.';
        $a = $this->query($fqdn, DNS_A);
        $aaaa = $this->query($fqdn, DNS_AAAA);

        if (false === $a || false === $aaaa) {
            return false;
        }

        $addresses = [];
        foreach ([...$a, ...$aaaa] as $record) {
            $ip = is_array($record) ? ($record['ip'] ?? $record['ipv6'] ?? null) : null;
            if (is_string($ip)) {
                $addresses[] = $ip;
            }
        }

        return $addresses;
    }

    /**
     * dns_get_record() restituisce [] per NXDOMAIN/NODATA e false (con un
     * warning) per SERVFAIL/timeout: il warning viene intercettato senza '@'.
     *
     * @return array<array-key, mixed>|false
     */
    private function query(string $fqdn, int $type): array|false
    {
        set_error_handler(static fn (int $errno, string $errstr): bool => true);

        try {
            return dns_get_record($fqdn, $type);
        } finally {
            restore_error_handler();
        }
    }
}
