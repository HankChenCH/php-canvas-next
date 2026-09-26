<?php

namespace HankChen\CanvasNext\Compiler;

/**
 * 软诊断值对象（spec §5.1）：编译期策略性软失败的观测载体。
 * 硬失败走异常（三端各自映射本地类型），软信息走 CompileResult.warnings；
 * 发射面随工票 04 落地（hydrate 文案兜底警告），形态先钉定
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
