<?php

declare(strict_types=1);

namespace EmailValidator\Tests\Support;

/**
 * Stato controllabile per dns_get_record(), rimpiazzata nei test dall'override
 * in {@see dns_function_overrides.php}. Quando non è "armato" (dopo
 * {@see reset()}) delega alla vera funzione globale, per non alterare i test
 * di integrazione che eseguono risoluzioni DNS reali.
 */
final class DnsStub
{
    private static bool $active = false;

    /** @var array<string, array<int, array<array-key, mixed>|false>> */
    private static array $zones = [];

    /**
     * Mappa FQDN (con punto finale) => tipo DNS_* => risultato grezzo di
     * dns_get_record(). Un host o un tipo assente equivale a NXDOMAIN/NODATA
     * ([]); false simula un errore del resolver (SERVFAIL/timeout).
     *
     * @param array<string, array<int, array<array-key, mixed>|false>> $zones
     */
    public static function fake(array $zones): void
    {
        self::$active = true;
        self::$zones = $zones;
    }

    public static function reset(): void
    {
        self::$active = false;
        self::$zones = [];
    }

    /**
     * @return array<array-key, mixed>|false
     */
    public static function dnsGetRecord(string $hostname, int $type): array|false
    {
        if (!self::$active) {
            return dns_get_record($hostname, $type);
        }

        return self::$zones[$hostname][$type] ?? [];
    }
}
