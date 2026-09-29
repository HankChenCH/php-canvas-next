<?php

namespace HankChen\CanvasNext\Tests\Runtime;

use HankChen\CanvasNext\Exception\MaterializeException;
use HankChen\CanvasNext\Runtime\CancellationSource;
use HankChen\CanvasNext\Runtime\CompositeCancellation;
use HankChen\CanvasNext\Runtime\NullCancellation;
use HankChen\CanvasNext\Runtime\TimeoutCancellation;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CompositeCancellationTest extends TestCase
{
    public function testEmptyCompositeEqualsNullSemantics(): void
    {
        $composite = new CompositeCancellation();

        $this->assertFalse($composite->isRequested());
        $this->assertNull($composite->deadline());
        $composite->throwIfRequested(); // 空组合恒静默
        $this->assertTrue(true);
    }

    public function testAnyRequestedChildTriggers(): void
    {
        $source = new CancellationSource();
        $source->cancel(new RuntimeException('人为取消'));
        $composite = new CompositeCancellation(new NullCancellation(), $source->getCancellation());

        $this->assertTrue($composite->isRequested());

        try {
            $composite->throwIfRequested();
            $this->fail('任一子源触发即抛错');
        } catch (MaterializeException $e) {
            // 委托给被请求的子源：code 语义与 previous 链保留
            $this->assertSame('render_cancelled', $e->getErrorCode());
            $this->assertSame('人为取消', $e->getPrevious()?->getMessage());
        }
    }

    public function testDeadlineIsEarliestNonNull(): void
    {
        $composite = new CompositeCancellation(
            new TimeoutCancellation(100.0),
            new TimeoutCancellation(50.0),
            new NullCancellation(),
        );

        // deadline 取各子源最早非空（≈ now + 50，远小于 now + 100）
        $this->assertEqualsWithDelta(microtime(true) + 50.0, $composite->deadline(), 2.0);
        $this->assertLessThan(microtime(true) + 90.0, $composite->deadline());
    }

    public function testAllNullDeadlinesYieldNull(): void
    {
        $composite = new CompositeCancellation(new NullCancellation(), new NullCancellation());

        $this->assertNull($composite->deadline());
    }

    public function testTimeoutChildKeepsDeadlineCode(): void
    {
        // Go parent+local 派生形态：composite(父信号, 本地源信号)
        $localSource = new CancellationSource();
        $composite = new CompositeCancellation(new TimeoutCancellation(0.0), $localSource->getCancellation());

        $this->assertTrue($composite->isRequested());

        try {
            $composite->throwIfRequested();
            $this->fail('超时子源必须抛错');
        } catch (MaterializeException $e) {
            $this->assertSame('render_deadline_exceeded', $e->getErrorCode());
        }
    }
}
