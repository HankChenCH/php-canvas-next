<?php

namespace HankChen\CanvasNext\Runtime;

use HankChen\CanvasNext\Exception\MaterializeException;

/**
 * 超时取消信号：pull-based，构造即换算 deadline，检查点比较时钟，零 loop 依赖
 * （amp TimeoutCancellation 的同步管线等价物——不跑 event-loop 也有活定时器）
 *
 * seconds <= 0 合法 = 立即超时（deadline 落在过去）；deadline() 是绝对 Unix
 * 时间戳，下载侧据此换算 socket 超时余量（DefaultDownloader）
 */
final class TimeoutCancellation implements Cancellation
{
    private readonly float $deadline;

    public function __construct(float $seconds)
    {
        $this->deadline = microtime(true) + $seconds;
    }

    public function isRequested(): bool
    {
        return microtime(true) >= $this->deadline;
    }

    public function throwIfRequested(): void
    {
        if (!$this->isRequested()) {
            return;
        }

        throw new MaterializeException(
            'render_deadline_exceeded',
            sprintf(
                'render_deadline_exceeded: render deadline %.3F exceeded (now %.3F)',
                $this->deadline,
                microtime(true)
            )
        );
    }

    public function deadline(): ?float
    {
        return $this->deadline;
    }
}
