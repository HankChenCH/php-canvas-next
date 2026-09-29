<?php

namespace HankChen\CanvasNext\Compiler\Flow;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\TableGeometry;

/**
 * 帧装箱容量（spec §10.3）：可用区 = 页底 − 表顶，与 paginate 同一数学
 * （页底基准经 TableGeometry::shellTop 共享，防数学漂移）。
 *
 * fixed 帧单页页底 = 帧画布高（CanvasPaginator pageHeight=null 的溢出判定基准）；
 * paged 帧页高 = 帧画布高——两种帧的页底同值，容量计算不区分 mode
 */
final class FrameCapacity
{
    private function __construct(
        public readonly int $available,
    ) {
    }

    /**
     * @param Canvas $frame 目标帧（hydrate 产物或声明态帧，取尺寸用）
     * @param array<string, mixed> $tableGraph 该帧顶层槽位表的 graph（锚点/位置读取）
     */
    public static function of(Canvas $frame, array $tableGraph): self
    {
        return new self(
            $frame->getHeight() - TableGeometry::shellTop($tableGraph, $frame->getWidth(), $frame->getHeight())
        );
    }
}
