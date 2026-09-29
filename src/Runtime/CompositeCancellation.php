<?php

namespace HankChen\CanvasNext\Runtime;

use HankChen\CanvasNext\Exception\MaterializeException;

/**
 * 组合取消信号：variadic 任一子源触发即触发（Go parent+local 派生的等价形态：
 * new CompositeCancellation($父信号, $本地源->getCancellation())）
 *
 * deadline 取各子源最早非空；空 variadic 合法 = NullCancellation 语义。
 * throwIfRequested 委托给首个已请求的子源——code 语义随子源性质
 * （超时 → render_deadline_exceeded，人为取消 → render_cancelled + previous 链）
 */
final class CompositeCancellation implements Cancellation
{
    /** @var list<Cancellation> */
    private readonly array $cancellations;

    /** @param Cancellation ...$cancellations */
    public function __construct(Cancellation ...$cancellations)
    {
        $this->cancellations = $cancellations;
    }

    public function isRequested(): bool
    {
        foreach ($this->cancellations as $cancellation) {
            if ($cancellation->isRequested()) {
                return true;
            }
        }

        return false;
    }

    public function throwIfRequested(): void
    {
        foreach ($this->cancellations as $cancellation) {
            if ($cancellation->isRequested()) {
                $cancellation->throwIfRequested();

                return;
            }
        }
    }

    public function deadline(): ?float
    {
        $earliest = null;
        foreach ($this->cancellations as $cancellation) {
            $deadline = $cancellation->deadline();
            if ($deadline !== null && ($earliest === null || $deadline < $earliest)) {
                $earliest = $deadline;
            }
        }

        return $earliest;
    }
}
