<?php

declare(strict_types=1);

namespace EmailValidator\Tests;

use EmailValidator\Enum\EmailError;
use EmailValidator\Service\EmailValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailValidatorTest extends TestCase
{
    public function testValidEmailWithMxRecords(): void
    {
        $emailValidator = new EmailValidator(new FakeMxResolver(['mx1.example.com', 'mx2.example.com']));

        $emailValidationResult = $emailValidator->validate('  Mario.Rossi@Example.com ');

        $this->assertTrue($emailValidationResult->isValid());
        $this->assertSame('Mario.Rossi@example.com', $emailValidationResult->getEmail());
        $this->assertSame('Mario.Rossi@example.com', $emailValidationResult->getSanitizedEmail());
        $this->assertSame(['mx1.example.com', 'mx2.example.com'], $emailValidationResult->getMxHosts());
        $this->assertNull($emailValidationResult->getError());
    }

    public function testAllowsPlusAddressingInLocalPart(): void
    {
        $emailValidator = new EmailValidator(new FakeMxResolver(['mx.example.com']));

        $emailValidationResult = $emailValidator->validate('user+tag@example.com');

        $this->assertTrue($emailValidationResult->isValid());
        $this->assertSame('user+tag@example.com', $emailValidationResult->getSanitizedEmail());
    }

    public function testNormalizesUnicodeDomainToPunycode(): void
    {
        $emailValidator = new EmailValidator(new FakeMxResolver(['mx.mueller.de']));

        $emailValidationResult = $emailValidator->validate('utente@müller.de');

        $this->assertTrue($emailValidationResult->isValid());
        $this->assertSame('utente@xn--mller-kva.de', $emailValidationResult->getEmail());
    }

    public function testFallsBackToOriginalDomainWhenIdnConversionFails(): void
    {
        // "\u{0639}" (ain, script arabo) seguito da 'a' (script latino) viola la
        // regola BIDI di IDNA_CHECK_BIDI: idn_to_ascii() restituisce false e il
        // dominio Unicode originale viene lasciato invariato, per essere poi
        // scartato da FILTER_SANITIZE_EMAIL (che rimuove i byte non ASCII).
        $emailValidator = new EmailValidator(new FakeMxResolver([]));

        $emailValidationResult = $emailValidator->validate("user@\u{0639}a.com");

        $this->assertFalse($emailValidationResult->isValid());
        $this->assertSame(EmailError::SANITIZE_ALTERED, $emailValidationResult->getError());
    }

    #[DataProvider('emptyInputProvider')]
    public function testRejectsEmptyOrNonStringInput(mixed $input): void
    {
        $emailValidator = new EmailValidator(new FakeMxResolver([]));

        $emailValidationResult = $emailValidator->validate($input);

        $this->assertFalse($emailValidationResult->isValid());
        $this->assertSame(EmailError::EMPTY_ADDRESS, $emailValidationResult->getError());
        $this->assertNull($emailValidationResult->getSanitizedEmail());
    }

    /** @return iterable<string, array{mixed}> */
    public static function emptyInputProvider(): iterable
    {
        yield 'empty string' => [''];
        yield 'whitespace only' => ['   '];
        yield 'null' => [null];
        yield 'array' => [['not', 'a', 'string']];
    }

    public function testRejectsInvalidUtf8Encoding(): void
    {
        $emailValidator = new EmailValidator(new FakeMxResolver([]));

        $emailValidationResult = $emailValidator->validate("user@example.com\xB1");

        $this->assertFalse($emailValidationResult->isValid());
        $this->assertSame(EmailError::INVALID_ENCODING, $emailValidationResult->getError());
    }

    public function testRejectsAddressAlteredBySanitize(): void
    {
        $emailValidator = new EmailValidator(new FakeMxResolver([]));

        $emailValidationResult = $emailValidator->validate('john(comment)@example.com');

        $this->assertFalse($emailValidationResult->isValid());
        $this->assertSame(EmailError::SANITIZE_ALTERED, $emailValidationResult->getError());
    }

    public function testRejectsAddressExceedingMaxLength(): void
    {
        $emailValidator = new EmailValidator(new FakeMxResolver([]));

        $emailValidationResult = $emailValidator->validate(str_repeat('a', 250) . '@example.com');

        $this->assertFalse($emailValidationResult->isValid());
        $this->assertSame(EmailError::TOO_LONG, $emailValidationResult->getError());
    }

    public function testRejectsInvalidSyntax(): void
    {
        $emailValidator = new EmailValidator(new FakeMxResolver([]));

        $emailValidationResult = $emailValidator->validate('not-an-email');

        $this->assertFalse($emailValidationResult->isValid());
        $this->assertSame(EmailError::INVALID_SYNTAX, $emailValidationResult->getError());
    }

    #[DataProvider('invalidFormatProvider')]
    public function testRejectsInvalidFormat(string $email): void
    {
        $emailValidator = new EmailValidator(new FakeMxResolver([]));

        $emailValidationResult = $emailValidator->validate($email);

        $this->assertFalse($emailValidationResult->isValid());
        $this->assertSame(EmailError::INVALID_FORMAT, $emailValidationResult->getError());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidFormatProvider(): iterable
    {
        yield 'tld too short' => ['user@example.c'];
        yield 'ip literal domain' => ['user@[192.168.1.1]'];
    }

    public function testPropagatesNullMxFromResolver(): void
    {
        $emailValidator = new EmailValidator(new FakeMxResolver(EmailError::NULL_MX));

        $emailValidationResult = $emailValidator->validate('user@example.com');

        $this->assertFalse($emailValidationResult->isValid());
        $this->assertSame(EmailError::NULL_MX, $emailValidationResult->getError());
    }

    public function testPropagatesNoMxRecordFromResolver(): void
    {
        $emailValidator = new EmailValidator(new FakeMxResolver(EmailError::NO_MX_RECORD));

        $emailValidationResult = $emailValidator->validate('user@dominio-inesistente-xyz123.it');

        $this->assertFalse($emailValidationResult->isValid());
        $this->assertSame(EmailError::NO_MX_RECORD, $emailValidationResult->getError());
    }
}
