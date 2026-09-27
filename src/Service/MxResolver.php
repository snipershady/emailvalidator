<?php

declare(strict_types=1);

namespace EmailValidator\Service;

use EmailValidator\Enum\EmailError;

/**
 * Risolve i record MX di un dominio. Astratto dietro un'interfaccia per
 * permettere ai consumer di sostituire la risoluzione DNS reale con un
 * doppio di test, senza dipendere dalla rete.
 */
interface MxResolver
{
    /**
     * @return list<string>|EmailError host MX ordinati per priorità, oppure l'errore riscontrato
     */
    public function resolve(string $domain): array|EmailError;
}
