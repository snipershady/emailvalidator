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
        // validante risponde SERVFAIL a qualunque query. Un resolver non
        // validante (es. quello dei runner GitHub Actions) risponde invece
        // normalmente, e poiché il dominio non ha MX la query MX dà NODATA,
        // cioè correttamente NO_MX_RECORD: non si può quindi dedurre dal
        // solo esito MX se il resolver valida. Si interroga prima il record
        // A, che esiste: se risolve, il resolver non valida e il test non è
        // applicabile.
        set_error_handler(static fn (): bool => true);
        try {
            $validating = false === dns_get_record('dnssec-failed.org.', DNS_A);
        } finally {
            restore_error_handler();
        }

        if (!$validating) {
            $this->markTestSkipped('Il resolver di sistema non valida DNSSEC');
        }

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::DNS_FAILURE, $dnsMxResolver->resolve('dnssec-failed.org'));
    }
}
