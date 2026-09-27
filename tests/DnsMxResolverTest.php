<?php

declare(strict_types=1);

namespace EmailValidator\Tests;

use EmailValidator\Enum\EmailError;
use EmailValidator\Service\DnsMxResolver;
use EmailValidator\Tests\Support\DnsStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test unitari di DnsMxResolver: le funzioni DNS globali sono sostituite da
 * {@see DnsStub} tramite gli override di namespace in
 * tests/Support/dns_function_overrides.php, quindi nessuna di queste
 * asserzioni tocca la rete.
 */
final class DnsMxResolverTest extends TestCase
{
    protected function setUp(): void
    {
        DnsStub::reset();
    }

    protected function tearDown(): void
    {
        DnsStub::reset();
    }

    public function testReturnsNoMxRecordWhenNoMxAndImplicitMxDisabled(): void
    {
        DnsStub::fake(['MX' => false], dnsGetRecord: false);

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::NO_MX_RECORD, $dnsMxResolver->resolve('example.com'));
    }

    public function testReturnsNoMxRecordWhenNoMxAndNoImplicitHostEvenIfAllowed(): void
    {
        DnsStub::fake(['MX' => false, 'A' => false, 'AAAA' => false], dnsGetRecord: false);

        $dnsMxResolver = new DnsMxResolver(allowImplicitMx: true);

        $this->assertSame(EmailError::NO_MX_RECORD, $dnsMxResolver->resolve('example.com'));
    }

    public function testAcceptsImplicitMxViaARecord(): void
    {
        DnsStub::fake(['MX' => false, 'A' => true, 'AAAA' => false], dnsGetRecord: false);

        $dnsMxResolver = new DnsMxResolver(allowImplicitMx: true);

        $this->assertSame(['example.com'], $dnsMxResolver->resolve('example.com'));
    }

    public function testAcceptsImplicitMxViaAaaaRecord(): void
    {
        DnsStub::fake(['MX' => false, 'A' => false, 'AAAA' => true], dnsGetRecord: false);

        $dnsMxResolver = new DnsMxResolver(allowImplicitMx: true);

        $this->assertSame(['example.com'], $dnsMxResolver->resolve('example.com'));
    }

    public function testReturnsDnsFailureWhenRecordLookupFails(): void
    {
        DnsStub::fake(['MX' => true], dnsGetRecord: false);

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::DNS_FAILURE, $dnsMxResolver->resolve('example.com'));
    }

    public function testReturnsDnsFailureWhenRecordLookupIsEmpty(): void
    {
        DnsStub::fake(['MX' => true], []);

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::DNS_FAILURE, $dnsMxResolver->resolve('example.com'));
    }

    #[DataProvider('nullMxTargetProvider')]
    public function testDetectsNullMx(string $target): void
    {
        DnsStub::fake(['MX' => true], [['target' => $target, 'pri' => 0]]);

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::NULL_MX, $dnsMxResolver->resolve('example.com'));
    }

    /** @return iterable<string, array{string}> */
    public static function nullMxTargetProvider(): iterable
    {
        yield 'empty target' => [''];
        yield 'dot-only target' => ['.'];
    }

    public function testResolvesAndSortsHostsByPriorityStrippingTrailingDot(): void
    {
        DnsStub::fake(['MX' => true], [
            ['target' => 'mx2.example.com.', 'pri' => 20],
            ['target' => 'mx1.example.com.', 'pri' => 10],
        ]);

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(['mx1.example.com', 'mx2.example.com'], $dnsMxResolver->resolve('example.com'));
    }
}
