<?php

declare(strict_types=1);

namespace EmailValidator\Tests;

use EmailValidator\Enum\EmailError;
use EmailValidator\Service\MxResolver;

/**
 * Doppio di test per {@see MxResolver}: restituisce sempre lo stesso esito,
 * senza eseguire alcuna risoluzione DNS reale.
 */
final readonly class FakeMxResolver implements MxResolver
{
    /** @param list<string>|EmailError $result */
    public function __construct(
        private array|EmailError $result,
    ) {
    }

    #[\Override]
    public function resolve(string $domain): array|EmailError
    {
        return $this->result;
    }
}
