<?php

namespace HankChen\CanvasNext\Compiler;

/**
 * 软诊断值对象（spec §5.1）：编译期策略性软失败的观测载体。
 * 硬失败走异常（三端各自映射本地类型），软信息走 CompileResult.warnings；
 * 现有发射面 = hydrate 文案类兜底空串（expression_fallback_empty，spec §3.3），
 * 消息人读可本地化，code 是三端一致性锚点
 */
final class Diagnostic
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $path = null,
    ) {
    }
}
