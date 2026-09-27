<?php

declare(strict_types=1);

namespace EmailValidator\Tests;

use EmailValidator\Dto\EmailValidationResult;
use EmailValidator\Enum\EmailError;
use PHPUnit\Framework\TestCase;

final class EmailValidationResultTest extends TestCase
{
    public function testOkResultExposesEmailAndMxHosts(): void
    {
        $emailValidationResult = EmailValidationResult::ok('user@example.com', ['mx.example.com']);

        $this->assertTrue($emailValidationResult->isValid());
        $this->assertSame('user@example.com', $emailValidationResult->getEmail());
        $this->assertSame('user@example.com', $emailValidationResult->getSanitizedEmail());
        $this->assertSame(['mx.example.com'], $emailValidationResult->getMxHosts());
        $this->assertNull($emailValidationResult->getError());
    }

    public function testFailResultHidesSanitizedEmail(): void
    {
        $emailValidationResult = EmailValidationResult::fail('not-an-email', EmailError::INVALID_SYNTAX);

        $this->assertFalse($emailValidationResult->isValid());
        $this->assertSame('not-an-email', $emailValidationResult->getEmail());
        $this->assertNull($emailValidationResult->getSanitizedEmail());
        $this->assertSame([], $emailValidationResult->getMxHosts());
        $this->assertSame(EmailError::INVALID_SYNTAX, $emailValidationResult->getError());
    }
}
