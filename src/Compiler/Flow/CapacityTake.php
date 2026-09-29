<?php

namespace HankChen\CanvasNext\Compiler\Flow;

use HankChen\CanvasNext\Compiler\TableGeometry;
use HankChen\CanvasNext\Exception\PaginateException;

/**
 * 容量型 fixed（spec §10.3）：可用区装得下的最大行前缀——行保序、不切开。
 *
 * 首行即超容（单行高 > 可用区）→ 分配期 fail-fast 抛 paginate 段 content_overflow
 * （同一装箱数学的同一失败语义，由 DocumentCompiler 抛出，不换载体不换段，spec §10.4）；
 * 自然 0 行仅当剩余已空——首行超容已被 fail-fast 接管，不存在静默 0 行路径
 */
final class CapacityTake implements FlowConsumer
{
    public function take(FrameCapacity $capacity, array $remaining): int
    {
        if ($remaining === []) {
            return 0;
        }

        $firstHeight = TableGeometry::rowHeight($remaining[0]);
        if ($firstHeight > $capacity->available) {
            throw new PaginateException(
                'content_overflow',
                sprintf('content_overflow: 流分配首行高 %d > 帧可用区 %d', $firstHeight, $capacity->available)
            );
        }

        $taken = 0;
        $used = 0;
        foreach ($remaining as $rowGraph) {
            $height = TableGeometry::rowHeight($rowGraph);
            if ($used + $height > $capacity->available) {
                break;
            }
            $used += $height;
            $taken++;
        }

        return $taken;
    }
}
