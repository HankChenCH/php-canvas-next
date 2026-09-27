<?php

namespace HankChen\CanvasNext\Compiler;

use HankChen\CanvasNext\Canvas;

/**
 * 编译产物（spec §5.1）。返回类型一次定稿，避免日后破坏性变更
 */
final class CompileResult
{
    /**
     * @param list<Canvas> $canvases 分页后的页序列（未启用分页 = 单元素）
     * @param list<Diagnostic> $warnings 软诊断（现仅 hydrate 文案兜底警告，spec §3.3）
     */
    public function __construct(
        public readonly array $canvases,
        public readonly array $warnings = [],
    ) {
    }
}
