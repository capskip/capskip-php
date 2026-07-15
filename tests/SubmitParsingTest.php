<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\CapSkip;
use CapSkip\Exceptions\ApiException;
use PHPUnit\Framework\TestCase;

/** Unit tests for in.php submit-response parsing (CapSkip::parseSubmitResponse). */
class SubmitParsingTest extends TestCase
{
    public function testOkForm(): void
    {
        $this->assertSame('12345', CapSkip::parseSubmitResponse('OK|12345'));
    }

    public function testOkFormIsTrimmed(): void
    {
        $this->assertSame('12345', CapSkip::parseSubmitResponse("OK|12345\n"));
    }

    public function testJsonForm(): void
    {
        // CapSkip returns this shape when the submit carried json=1.
        $this->assertSame('12345', CapSkip::parseSubmitResponse('{"status":1,"request":"12345"}'));
    }

    public function testUnrecognizedResponseRaises(): void
    {
        $this->expectException(ApiException::class);
        CapSkip::parseSubmitResponse('SOMETHING_UNEXPECTED');
    }

    public function testJsonWithoutSuccessStatusRaises(): void
    {
        $this->expectException(ApiException::class);
        CapSkip::parseSubmitResponse('{"status":0,"request":"ERROR"}');
    }
}
