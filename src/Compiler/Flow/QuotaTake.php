<?php

namespace HankChen\CanvasNext\Compiler\Flow;

/**
 * fixed + quota（spec §10.3）：min(quota, count)——quota 是声明非许可，
 * 超容不钳制（quota:0 = 显式不吃行的声明位）；多装行由帧内 paginate 溢出语义裁决
 */
final class QuotaTake implements FlowConsumer
{
    public function __construct(
        private readonly int $quota,
    ) {
    }

    public function take(FrameCapacity $capacity, array $remaining): int
    {
        return min($this->quota, count($remaining));
    }
}
