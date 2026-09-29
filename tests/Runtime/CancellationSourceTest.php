<?php

namespace HankChen\CanvasNext\Tests\Runtime;

use HankChen\CanvasNext\Exception\MaterializeException;
use HankChen\CanvasNext\Runtime\CancellationSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CancellationSourceTest extends TestCase
{
    public function testFreshSourceIsNotCancelled(): void
    {
        $source = new CancellationSource();

        $this->assertFalse($source->isCancelled());
        $this->assertFalse($source->getCancellation()->isRequested());
        $this->assertNull($source->getCancellation()->deadline());
        $source->getCancellation()->throwIfRequested(); // 未取消恒静默
        $this->assertTrue(true);
    }

    public function testCancelMakesSignalRequested(): void
    {
        $source = new CancellationSource();
        $signal = $source->getCancellation();

        $source->cancel();

        $this->assertTrue($source->isCancelled());
        $this->assertTrue($signal->isRequested());

        try {
            $signal->throwIfRequested();
            $this->fail('取消后 throwIfRequested 必须抛错');
        } catch (MaterializeException $e) {
            $this->assertSame('render_cancelled', $e->getErrorCode());
            $this->assertNull($e->getPrevious());
        }
    }

    public function testCancelReasonIsAttachedAsPrevious(): void
    {
        $source = new CancellationSource();
        $reason = new RuntimeException('客户端断连');

        $source->cancel($reason);

        try {
            $source->getCancellation()->throwIfRequested();
            $this->fail('取消后必须抛错');
        } catch (MaterializeException $e) {
            $this->assertSame('render_cancelled', $e->getErrorCode());
            $this->assertSame($reason, $e->getPrevious());
        }
    }

    public function testCancelIsIdempotentFirstWins(): void
    {
        $source = new CancellationSource();
        $first = new RuntimeException('first');
        $second = new RuntimeException('second');

        $source->cancel($first);
        $source->cancel($second); // 一次性：后续调用连 previous 都不改

        try {
            $source->getCancellation()->throwIfRequested();
            $this->fail('取消后必须抛错');
        } catch (MaterializeException $e) {
            $this->assertSame($first, $e->getPrevious());
            $this->assertNotSame($second, $e->getPrevious());
        }
    }

    public function testSignalIdentityIsStableAcrossCancel(): void
    {
        $source = new CancellationSource();

        $this->assertSame($source->getCancellation(), $source->getCancellation());
    }
}
