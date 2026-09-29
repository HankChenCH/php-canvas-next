<?php

namespace HankChen\CanvasNext\Runtime;

/**
 * 零值取消信号：永不取消、无 deadline 的空对象（amp 同名同义）
 *
 * 所有 render/resolve/download 调用点的默认形态（ADR 0008：ctx 严格非空，
 * 不想受控即显式传零值）；空 variadic 的 CompositeCancellation 语义等价
 *
 * @api
 */
final class NullCancellation implements Cancellation
{
    public function isRequested(): bool
    {
        return false;
    }

    public function throwIfRequested(): void
    {
        // 永不请求，恒静默
    }

    public function deadline(): ?float
    {
        return null;
    }
}
