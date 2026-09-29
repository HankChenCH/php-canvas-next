<?php

namespace HankChen\CanvasNext\Tests\Runtime;

use HankChen\CanvasNext\Exception\MaterializeException;
use HankChen\CanvasNext\Runtime\TimeoutCancellation;
use PHPUnit\Framework\TestCase;

class TimeoutCancellationTest extends TestCase
{
    public function testZeroSecondsIsImmediateTimeout(): void
    {
        $timeout = new TimeoutCancellation(0.0);

        $this->assertTrue($timeout->isRequested());
        // 构造即换算：deadline 是过去（或此刻）的绝对 Unix 时间戳
        $this->assertLessThanOrEqual(microtime(true), $timeout->deadline());

        try {
            $timeout->throwIfRequested();
            $this->fail('立即超时必须抛错');
        } catch (MaterializeException $e) {
            $this->assertSame('render_deadline_exceeded', $e->getErrorCode());
            $this->assertStringContainsString('render_deadline_exceeded', $e->getMessage());
        }
    }

    public function testNegativeSecondsIsImmediateTimeoutToo(): void
    {
        $this->assertTrue((new TimeoutCancellation(-5.0))->isRequested());
    }

    public function testFutureDeadlineNotRequestedYet(): void
    {
        $timeout = new TimeoutCancellation(3600.0);

        $this->assertFalse($timeout->isRequested());
        $timeout->throwIfRequested(); // 未到期恒静默
        // deadline ≈ 构造时刻 + 3600（构造即换算，允许秒级误差）
        $this->assertEqualsWithDelta(microtime(true) + 3600.0, $timeout->deadline(), 2.0);
    }
}
