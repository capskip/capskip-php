<?php

declare(strict_types=1);

namespace CapSkip\Tests;

use CapSkip\Exceptions\CapSkipError;

class NormalTest extends AbstractTestCase
{
    public function testBase64(): void
    {
        $body = str_repeat('A', 60);

        $result = $this->solver->normal($body);

        $this->assertSent(['method' => 'base64', 'body' => $body]);
        $this->assertResult($result);
    }

    public function testDataUri(): void
    {
        $body = str_repeat('A', 60);

        $result = $this->solver->normal('data:image/png;base64,' . $body);

        $this->assertSent(['method' => 'base64', 'body' => $body]);
        $this->assertResult($result);
    }

    public function testInvalidFile(): void
    {
        $this->expectException(CapSkipError::class);
        $this->solver->normal('lost_file.png');
    }

    public function testRejectsUnsupportedParams(): void
    {
        $this->expectException($this->solver->exceptions);
        $this->solver->normal(str_repeat('A', 60), ['numeric' => 1]);
    }

    public function testRejectsProxy(): void
    {
        $this->expectException($this->solver->exceptions);
        $this->solver->normal(str_repeat('A', 60), ['proxy' => ['type' => 'HTTP', 'uri' => '1.2.3.4:3128']]);
    }
}
