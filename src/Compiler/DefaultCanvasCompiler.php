<?php

namespace HankChen\CanvasNext\Compiler;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Hydrate\CanvasHydrator;

/**
 * 包内唯一具体编译器（spec §2）：hydrate hook 委托固定编排器 CanvasHydrator，
 * paginate hook 沿用基类单元素直通。
 *
 * 求值器注入链（spec §3.2）：用户经基类构造器传入 → 原样转发给填充器，
 * 用户视角只见 compiler 构造器上的 evaluator 参数
 */
final class DefaultCanvasCompiler extends CanvasCompiler
{
    protected function hydrate(CompileContext $context): Canvas
    {
        return (new CanvasHydrator($this->evaluator))
            ->hydrate($context->canvas, $context->dataset, $context->warnings);
    }
}
