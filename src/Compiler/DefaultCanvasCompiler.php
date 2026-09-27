<?php

namespace HankChen\CanvasNext\Compiler;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Contracts\ExpressionEvaluatorInterface;
use HankChen\CanvasNext\Hydrate\CanvasHydrator;

/**
 * 包内唯一具体编译器（spec §2）：hydrate hook 委托固定编排器 CanvasHydrator，
 * paginate hook 委托 CanvasPaginator（分页选项经构造器注入，工票 06）
 *
 * 求值器注入链（spec §3.2）：用户经基类构造器传入 → 原样转发给填充器，
 * 用户视角只见 compiler 构造器上的 evaluator 参数
 */
final class DefaultCanvasCompiler extends CanvasCompiler
{
    private readonly CanvasPaginator $paginator;

    public function __construct(
        ?ExpressionEvaluatorInterface $evaluator = null,
        array $paginateOptions = [],
    ) {
        parent::__construct($evaluator, $paginateOptions);
        // 构造期建 Paginator（不可变、可跨编译复用）：page_geometry_invalid 在此 fail-fast
        $this->paginator = CanvasPaginator::fromOptions($paginateOptions);
    }

    protected function hydrate(CompileContext $context): Canvas
    {
        return (new CanvasHydrator($this->evaluator))
            ->hydrate($context->canvas, $context->dataset, $context->warnings);
    }

    protected function paginate(Canvas $canvas): array
    {
        return $this->paginator->paginate($canvas);
    }
}
