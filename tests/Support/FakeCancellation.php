<?php

namespace HankChen\CanvasNext\Tests\Support;

use HankChen\CanvasNext\Exception\MaterializeException;
use HankChen\CanvasNext\Runtime\Cancellation;

/**
 * Cancellation 手写假实现（接口即测试缝，无需真实定时）：
 * requested 是公开可变位——测试可在渲染中途"武装"取消，驱动检查点掐断
 */
class FakeCancellation implements Cancellation
{
    public bool $requested;

    public function __construct(
        bool $requested = false,
        private readonly ?float $deadline = null,
        private readonly ?\Throwable $previous = null
    ) {
        $this->requested = $requested;
    }

    public function isRequested(): bool
    {
        return $this->requested;
    }

    public function throwIfRequested(): void
    {
        if (!$this->requested) {
            return;
        }

        throw new MaterializeException(
            'render_cancelled',
            'render_cancelled: fake cancellation',
            $this->previous
        );
    }

    public function deadline(): ?float
    {
        return $this->deadline;
    }
}
