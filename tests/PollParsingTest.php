<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\CapSkip;
use CapSkip\Exceptions\ApiException;
use CapSkip\Exceptions\NetworkException;
use PHPUnit\Framework\TestCase;

/** Unit tests for res.php response parsing (CapSkip::parsePollResponse). */
class PollParsingTest extends TestCase
{
    /**
     * @dataProvider emptyBodies
     *
     * @param string|null $body
     */
    public function testEmptyBodyIsRetryable($body, int $jsonMode): void
    {
        // CapSkip returns an empty body before a result is available; it must be
        // signalled as "not ready" (NetworkException), never a fatal ApiException.
        $this->expectException(NetworkException::class);
        CapSkip::parsePollResponse($body, $jsonMode);
    }

    /** @return array<int, array{0: string|null, 1: int}> */
    public static function emptyBodies(): array
    {
        $cases = [];
        foreach (['', '   ', "\n", null] as $body) {
            foreach ([0, 1] as $jsonMode) {
                $cases[] = [$body, $jsonMode];
            }
        }

        return $cases;
    }

    public function testNotReadyMarker(): void
    {
        $this->expectException(NetworkException::class);
        CapSkip::parsePollResponse('CAPCHA_NOT_READY');
    }

    public function testOkToken(): void
    {
        $this->assertSame('thetoken', CapSkip::parsePollResponse('OK|thetoken'));
    }

    public function testOkTokenIsStripped(): void
    {
        $this->assertSame('thetoken', CapSkip::parsePollResponse("OK|thetoken\n"));
    }

    public function testUnrecognizedResponseRaises(): void
    {
        $this->expectException(ApiException::class);
        CapSkip::parsePollResponse('SOMETHING_UNEXPECTED');
    }

    public function testJsonReady(): void
    {
        $data = CapSkip::parsePollResponse('{"status":1,"request":"tok"}', 1);
        $this->assertIsArray($data);
        $this->assertSame('tok', $data['request']);
    }

    public function testJsonNotReadyIsRetryable(): void
    {
        $this->expectException(NetworkException::class);
        CapSkip::parsePollResponse('{"status":0,"request":"CAPCHA_NOT_READY"}', 1);
    }
}
