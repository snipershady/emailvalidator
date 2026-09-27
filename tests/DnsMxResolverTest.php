<?php

declare(strict_types=1);

namespace EmailValidator\Tests;

use EmailValidator\Enum\EmailError;
use EmailValidator\Service\DnsMxResolver;
use EmailValidator\Tests\Support\DnsStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Test unitari di DnsMxResolver: dns_get_record() è sostituita da
 * {@see DnsStub} tramite l'override di namespace in
 * tests/Support/dns_function_overrides.php, quindi nessuna di queste
 * asserzioni tocca la rete.
 */
final class DnsMxResolverTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        DnsStub::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        DnsStub::reset();
    }

    public function testReturnsNoMxRecordWhenNoMxAndImplicitMxDisabled(): void
    {
        DnsStub::fake(['example.com.' => [DNS_A => [['ip' => '93.184.216.34']]]]);

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::NO_MX_RECORD, $dnsMxResolver->resolve('example.com'));
    }

    public function testReturnsNoMxRecordWhenNoMxAndNoImplicitHostEvenIfAllowed(): void
    {
        DnsStub::fake([]);

        $dnsMxResolver = new DnsMxResolver(allowImplicitMx: true);

        $this->assertSame(EmailError::NO_MX_RECORD, $dnsMxResolver->resolve('example.com'));
    }

    public function testAcceptsImplicitMxViaARecord(): void
    {
        DnsStub::fake(['example.com.' => [DNS_A => [['ip' => '93.184.216.34']]]]);

        $dnsMxResolver = new DnsMxResolver(allowImplicitMx: true);

        $this->assertSame(['example.com'], $dnsMxResolver->resolve('example.com'));
    }

    public function testAcceptsImplicitMxViaAaaaRecord(): void
    {
        DnsStub::fake(['example.com.' => [DNS_AAAA => [['ipv6' => '2606:2800:220:1::1']]]]);

        $dnsMxResolver = new DnsMxResolver(allowImplicitMx: true);

        $this->assertSame(['example.com'], $dnsMxResolver->resolve('example.com'));
    }

    public function testReturnsDnsFailureWhenImplicitMxLookupFails(): void
    {
        DnsStub::fake(['example.com.' => [DNS_A => false]]);

        $dnsMxResolver = new DnsMxResolver(allowImplicitMx: true);

        $this->assertSame(EmailError::DNS_FAILURE, $dnsMxResolver->resolve('example.com'));
    }

    public function testReturnsDnsFailureNotNoMxWhenMxLookupFails(): void
    {
        // SERVFAIL/timeout: un guasto transitorio non deve diventare NO_MX_RECORD
        DnsStub::fake(['example.com.' => [DNS_MX => false]]);

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::DNS_FAILURE, $dnsMxResolver->resolve('example.com'));
    }

    #[DataProvider('nullMxTargetProvider')]
    public function testDetectsNullMx(string $target): void
    {
        DnsStub::fake(['example.com.' => [DNS_MX => [['target' => $target, 'pri' => 0]]]]);

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::NULL_MX, $dnsMxResolver->resolve('example.com'));
    }

    /** @return iterable<string, array{string}> */
    public static function nullMxTargetProvider(): iterable
    {
        yield 'empty target' => [''];
        yield 'dot-only target' => ['.'];
    }

    public function testIgnoresNullMxMixedWithRealHosts(): void
    {
        // RFC 7505 §3: un Null MX non deve coesistere con altri MX; il record
        // vuoto viene ignorato invece di scartare l'intero dominio.
        DnsStub::fake(['example.com.' => [DNS_MX => [
            ['target' => '', 'pri' => 0],
            ['target' => 'mx.example.com', 'pri' => 10],
        ]]]);

        $dnsMxResolver = new DnsMxResolver(rejectNonPublicHosts: false);

        $this->assertSame(['mx.example.com'], $dnsMxResolver->resolve('example.com'));
    }

    public function testSkipsTargetsThatAreNotValidHostnames(): void
    {
        DnsStub::fake(['example.com.' => [DNS_MX => [
            ['target' => "evil\r\nhost.example.com", 'pri' => 0],
            ['target' => 'mx.example.com', 'pri' => 10],
        ]]]);

        $dnsMxResolver = new DnsMxResolver(rejectNonPublicHosts: false);

        $this->assertSame(['mx.example.com'], $dnsMxResolver->resolve('example.com'));
    }

    #[DataProvider('addressLikeTargetProvider')]
    public function testSkipsAddressLiteralTargetsEvenWithFilterDisabled(string $target): void
    {
        DnsStub::fake(['example.com.' => [DNS_MX => [
            ['target' => $target, 'pri' => 0],
            ['target' => 'mx.example.com', 'pri' => 10],
        ]]]);

        $dnsMxResolver = new DnsMxResolver(rejectNonPublicHosts: false);

        $this->assertSame(['mx.example.com'], $dnsMxResolver->resolve('example.com'));
    }

    /** @return iterable<string, array{string}> */
    public static function addressLikeTargetProvider(): iterable
    {
        yield 'ipv4 loopback' => ['127.0.0.1'];
        yield 'ipv4 loopback with trailing dot' => ['127.0.0.1.'];
        yield 'ipv4 private' => ['10.0.0.1'];
        yield 'cloud metadata' => ['169.254.169.254'];
        yield 'localhost' => ['localhost'];
        yield 'localhost with trailing dot' => ['localhost.'];
        yield 'short ipv4 form' => ['127.1'];
        yield 'octal ipv4 form' => ['0177.0.0.1'];
        yield 'hex ipv4 form' => ['0x7f.0.0.1'];
        yield 'integer ipv4 form' => ['2130706433'];
        yield 'numeric tld' => ['mx.example.123'];
        yield 'ipv6 literal' => ['[::1]'];
    }

    public function testAcceptsPunycodeTld(): void
    {
        DnsStub::fake(['example.com.' => [DNS_MX => [['target' => 'mx.example.xn--p1ai', 'pri' => 0]]]]);

        $dnsMxResolver = new DnsMxResolver(rejectNonPublicHosts: false);

        $this->assertSame(['mx.example.xn--p1ai'], $dnsMxResolver->resolve('example.com'));
    }

    public function testMalformedRecordIsNotMistakenForNullMx(): void
    {
        DnsStub::fake(['example.com.' => [DNS_MX => [['pri' => 0], 'garbage']]]);

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::NO_MX_RECORD, $dnsMxResolver->resolve('example.com'));
    }

    public function testReturnsNoMxRecordWhenNoTargetIsAValidHostname(): void
    {
        DnsStub::fake(['example.com.' => [DNS_MX => [['target' => 'bad host', 'pri' => 0]]]]);

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::NO_MX_RECORD, $dnsMxResolver->resolve('example.com'));
    }

    public function testResolvesAndSortsHostsByPriorityStrippingTrailingDot(): void
    {
        DnsStub::fake(['example.com.' => [DNS_MX => [
            ['target' => 'mx2.example.com.', 'pri' => 20],
            ['target' => 'mx1.example.com.', 'pri' => 10],
        ]]]);

        $dnsMxResolver = new DnsMxResolver(rejectNonPublicHosts: false);

        $this->assertSame(['mx1.example.com', 'mx2.example.com'], $dnsMxResolver->resolve('example.com'));
    }

    public function testKeepsPrivateHostsWhenFilterIsDisabled(): void
    {
        DnsStub::fake([
            'example.com.' => [DNS_MX => [['target' => 'internal.example.com', 'pri' => 0]]],
            'internal.example.com.' => [DNS_A => [['ip' => '10.0.0.5']]],
        ]);

        $dnsMxResolver = new DnsMxResolver(rejectNonPublicHosts: false);

        $this->assertSame(['internal.example.com'], $dnsMxResolver->resolve('example.com'));
    }

    public function testDropsNonPublicHostsByDefault(): void
    {
        DnsStub::fake([
            'example.com.' => [DNS_MX => [
                ['target' => 'loopback.example.com', 'pri' => 0],
                ['target' => 'mixed.example.com', 'pri' => 5],
                ['target' => 'metadata.example.com', 'pri' => 10],
                ['target' => 'unresolved.example.com', 'pri' => 15],
                ['target' => 'public.example.com', 'pri' => 20],
            ]],
            'loopback.example.com.' => [DNS_A => [['ip' => '127.0.0.1']]],
            'mixed.example.com.' => [
                DNS_A => [['ip' => '93.184.216.34']],
                DNS_AAAA => [['ipv6' => '::1']],
            ],
            'metadata.example.com.' => [DNS_A => [['ip' => '169.254.169.254']]],
            'public.example.com.' => [DNS_AAAA => [['ipv6' => '2606:2800:220:1::1']]],
        ]);

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(['public.example.com'], $dnsMxResolver->resolve('example.com'));
    }

    public function testReturnsNonPublicMxHostWhenEveryHostIsPrivate(): void
    {
        DnsStub::fake([
            'example.com.' => [DNS_MX => [['target' => 'internal.example.com', 'pri' => 0]]],
            'internal.example.com.' => [DNS_A => [['ip' => '192.168.1.10']]],
        ]);

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::NON_PUBLIC_MX_HOST, $dnsMxResolver->resolve('example.com'));
    }

    public function testReturnsDnsFailureWhenHostAddressLookupFails(): void
    {
        DnsStub::fake([
            'example.com.' => [DNS_MX => [['target' => 'mx.example.com', 'pri' => 0]]],
            'mx.example.com.' => [DNS_AAAA => false],
        ]);

        $dnsMxResolver = new DnsMxResolver();

        $this->assertSame(EmailError::DNS_FAILURE, $dnsMxResolver->resolve('example.com'));
    }

    public function testFiltersImplicitMxHostByDefault(): void
    {
        DnsStub::fake(['example.com.' => [DNS_A => [['ip' => '127.0.0.1']]]]);

        $dnsMxResolver = new DnsMxResolver(allowImplicitMx: true);

        $this->assertSame(EmailError::NON_PUBLIC_MX_HOST, $dnsMxResolver->resolve('example.com'));
    }
}
