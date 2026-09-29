<?php

namespace HankChen\CanvasNext\Tests\Runtime;

use HankChen\CanvasNext\Runtime\NullCancellation;
use PHPUnit\Framework\TestCase;

class NullCancellationTest extends TestCase
{
    public function testNeverRequestsAndHasNoDeadline(): void
    {
        $null = new NullCancellation();

        $this->assertFalse($null->isRequested());
        $this->assertNull($null->deadline());
    }

    public function testThrowIfRequestedIsSilent(): void
    {
        (new NullCancellation())->throwIfRequested();

        $this->assertTrue(true); // 恒不抛出
    }
}
