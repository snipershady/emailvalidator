<?php

declare(strict_types=1);

namespace EmailValidator\Tests\Support;

/**
 * Stato controllabile per le funzioni DNS native rimpiazzate, nei test, dagli
 * override in {@see dns_function_overrides.php}. Quando non è "armato" (dopo
 * {@see reset()}) delega alle vere funzioni globali, per non alterare i test
 * di integrazione che eseguono risoluzioni DNS reali.
 */
final class DnsStub
{
    private static bool $active = false;

    /** @var array<string, bool> */
    private static array $checkDnsRr = [];

    /** @var array<array-key, mixed>|false */
    private static array|false $dnsGetRecord = false;

    /**
     * @param array<string, bool>           $checkDnsRr   mappa tipo di record (MX, A, AAAA) => esito
     * @param array<array-key, mixed>|false $dnsGetRecord risultato grezzo, nello stesso formato di dns_get_record()
     */
    public static function fake(array $checkDnsRr, array|false $dnsGetRecord): void
    {
        self::$active = true;
        self::$checkDnsRr = $checkDnsRr;
        self::$dnsGetRecord = $dnsGetRecord;
    }

    public static function reset(): void
    {
        self::$active = false;
        self::$checkDnsRr = [];
        self::$dnsGetRecord = false;
    }

    public static function checkDnsRr(string $hostname, string $type): bool
    {
        if (!self::$active) {
            return checkdnsrr($hostname, $type);
        }

        return self::$checkDnsRr[$type] ?? false;
    }

    /**
     * @return array<array-key, mixed>|false
     */
    public static function dnsGetRecord(string $hostname, int $type): array|false
    {
        if (!self::$active) {
            return dns_get_record($hostname, $type);
        }

        return self::$dnsGetRecord;
    }
}
