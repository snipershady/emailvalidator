<?php

declare(strict_types=1);

/*
 * Override, nel namespace di EmailValidator\Service\DnsMxResolver, delle
 * funzioni DNS native di PHP. Grazie alla risoluzione dei nomi di funzione di
 * PHP (che cerca prima nel namespace corrente e solo poi ricade sul globale),
 * le chiamate non qualificate a checkdnsrr()/dns_get_record() dentro
 * DnsMxResolver.php useranno queste versioni durante i test, invece delle
 * vere funzioni globali — permettendo di testare ogni ramo della risoluzione
 * MX senza dipendere dalla rete. Caricato come file "autoload-dev" (vedi
 * composer.json), così è sempre disponibile prima che un test venga eseguito.
 */

namespace EmailValidator\Service;

use EmailValidator\Tests\Support\DnsStub;

function checkdnsrr(string $hostname, string $type = 'MX'): bool
{
    return DnsStub::checkDnsRr($hostname, $type);
}

/**
 * @return array<array-key, mixed>|false
 */
function dns_get_record(string $hostname, int $type = DNS_ANY): array|false
{
    return DnsStub::dnsGetRecord($hostname, $type);
}
