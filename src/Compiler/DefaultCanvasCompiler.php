<?php

namespace HankChen\CanvasNext\Compiler;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Expand\CanvasExpander;

/**
 * 包内唯一具体编译器（spec §2）：hydrate hook 委托现有展开器 CanvasExpander
 * （工票 02 更名 CanvasHydrator 后此处仅改委托类名），paginate hook 沿用基类单元素直通。
 *
 * 求值器注入链（spec §3.2）：用户经基类构造器传入 → 原样转发给展开器，
 * 用户视角只见 compiler 构造器上的 evaluator 参数
 */
final class DefaultCanvasCompiler extends CanvasCompiler
{
    protected function hydrate(CompileContext $context): Canvas
    {
        return (new CanvasExpander($this->evaluator))->expand($context->canvas, $context->dataset);
    }
}
