<?php

namespace HankChen\CanvasNext\Runtime;

use Closure;
use HankChen\CanvasNext\Exception\MaterializeException;

/**
 * 取消源：持有取消权的一次性开关（源/信号分离，ADR 0008）
 *
 * cancel() 幂等 first-wins：只有首次调用生效，后续调用连 previous 都不改；
 * 一次性，无 un-cancel。getCancellation() 返回的信号端只可观察（isRequested/
 * throwIfRequested/deadline），不暴露 cancel()——对齐 Go 刻意的不对称设计：
 * 接收取消信号的人通常不是发送信号的人
 */
final class CancellationSource
{
    private bool $cancelled = false;

    private ?\Throwable $previous = null;

    private Cancellation $signal;

    public function __construct()
    {
        // 信号端与源共享可变状态：绑定闭包读私有态，避免为读状态扩公开面
        // （cancel 权不进信号端，源/信号分离）
        $readState = Closure::bind(function (): array {
            return [$this->cancelled, $this->previous];
        }, $this, self::class);

        $this->signal = new class($readState) implements Cancellation {
            public function __construct(private readonly Closure $readState)
            {
            }

            public function isRequested(): bool
            {
                return ($this->readState)()[0];
            }

            public function throwIfRequested(): void
            {
                [$cancelled, $previous] = ($this->readState)();
                if (!$cancelled) {
                    return;
                }

                throw new MaterializeException(
                    'render_cancelled',
                    'render_cancelled: rendering cancelled' . ($previous !== null ? ": {$previous->getMessage()}" : ''),
                    $previous
                );
            }

            public function deadline(): ?float
            {
                return null;
            }
        };
    }

    /**
     * 请求取消（幂等 first-wins，一次性）：$previous 为取消原因，
     * 经检查点抛出的 MaterializeException::getPrevious() 透出
     */
    public function cancel(?\Throwable $previous = null): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;
        $this->previous = $previous;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function getCancellation(): Cancellation
    {
        return $this->signal;
    }
}
