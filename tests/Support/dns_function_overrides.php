<?php

declare(strict_types=1);

/*
 * Override, nel namespace di EmailValidator\Service\DnsMxResolver, della
 * funzione DNS nativa di PHP. Grazie alla risoluzione dei nomi di funzione di
 * PHP (che cerca prima nel namespace corrente e solo poi ricade sul globale),
 * la chiamata non qualificata a dns_get_record() dentro DnsMxResolver.php
 * userà questa versione durante i test, invece della vera funzione globale —
 * permettendo di testare ogni ramo della risoluzione MX senza dipendere dalla
 * rete. Caricato come file "autoload-dev" (vedi composer.json), così è sempre
 * disponibile prima che un test venga eseguito.
 */

namespace EmailValidator\Service;

use EmailValidator\Tests\Support\DnsStub;

/**
 * @return array<array-key, mixed>|false
 */
function dns_get_record(string $hostname, int $type = DNS_ANY): array|false
{
    return DnsStub::dnsGetRecord($hostname, $type);
}
