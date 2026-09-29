<?php

namespace HankChen\CanvasNext\Compiler\Flow;

/**
 * paged 链尾终止（spec §10.3）：吃尽剩余，页内切分全权交 paginate
 * （页高 = 帧画布高，v1 无覆盖位，逃生舱 = fixed + quota）
 */
final class PagedTake implements FlowConsumer
{
    public function take(FrameCapacity $capacity, array $remaining): int
    {
        return count($remaining);
    }
}
