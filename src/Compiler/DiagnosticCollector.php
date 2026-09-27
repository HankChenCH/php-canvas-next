<?php

namespace HankChen\CanvasNext\Compiler;

/**
 * 软诊断收集器（spec §3.3）：CompileContext.warnings 通道的写侧载体。
 *
 * 诊断发射是 Hydrator 的职责（spec §3.2），发射经本收集器进行、读取归 compile()
 * 汇入 CompileResult.warnings——收集器随 CompileContext 注入步骤，
 * Hydrate → Compiler 的这一类型引用是发射契约的一部分（均为编译期结构侧）
 */
final class DiagnosticCollector
{
    /** @var list<Diagnostic> */
    private array $diagnostics = [];

    public function collect(Diagnostic $diagnostic): void
    {
        $this->diagnostics[] = $diagnostic;
    }

    /** @return list<Diagnostic> */
    public function all(): array
    {
        return $this->diagnostics;
    }
}
