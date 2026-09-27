<?php

declare(strict_types=1);

namespace EmailValidator\Tests\Integration;

use EmailValidator\Enum\EmailError;
use EmailValidator\Service\DnsMxResolver;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Test di integrazione: esegue vere risoluzioni DNS.
 * Esclusi dalla suite di default (vedi phpunit.xml.dist), perché dipendono
 * dalla rete e dallo stato di domini di terze parti.
 */
#[Group('network')]
final class DnsMxResolverTest extends TestCase
{
    public function testDetectsNullMxOnExampleDotCom(): void
    {
        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::NULL_MX, $dnsMxResolver->resolve('example.com'));
    }

    public function testResolvesMxHostsForGmailDotCom(): void
    {
        $dnsMxResolver = new DnsMxResolver();

        $result = $dnsMxResolver->resolve('gmail.com');

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function testReturnsNoMxRecordForNonExistentDomain(): void
    {
        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::NO_MX_RECORD, $dnsMxResolver->resolve('dominio-inesistente-xyz123.it'));
    }

    public function testReturnsDnsFailureOnServfail(): void
    {
        // dnssec-failed.org ha firme DNSSEC volutamente rotte: un resolver
        // validante risponde SERVFAIL. Con un resolver non validante il
        // dominio risolve normalmente, quindi il test viene saltato.
        $dnsMxResolver = new DnsMxResolver();

        $result = $dnsMxResolver->resolve('dnssec-failed.org');

        if (is_array($result)) {
            $this->markTestSkipped('Il resolver di sistema non valida DNSSEC');
        }

        $this->assertSame(EmailError::DNS_FAILURE, $result);
    }
}
