<?php

namespace HankChen\CanvasNext\Compiler;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Contracts\ExpressionEvaluatorInterface;
use HankChen\CanvasNext\Expression\InterpolationEvaluator;

/**
 * 编译管线模板方法基类（spec §2）：compile() 为 final 模板方法，
 * 钉死步骤顺序 hydrate（数据填充，1→1）→ paginate（分页，1→n）。
 *
 * 【非第三方扩展点】子类仅限包内（唯一子类 DefaultCanvasCompiler 启用真实步骤）；
 * 用户定制面 = 公共构造器注入（求值器、分页选项），先例 ImageRenderer::__construct(?ResourceResolver)。
 * 新步骤的兼容路径 = 基类新增 hook + 默认直通 + 默认子类启用（对既有子类零破坏）。
 *
 * 步骤间通信经由封闭的 CompileContext（canvas + dataset + options）；
 * 语义形态钉定为「画布序列 → 画布序列」，为未来基数变化型步骤预留。
 * pass 边界状态必须 wire 可表达（graph()/fromGraph() 往返无语义损失）。
 */
abstract class CanvasCompiler
{
    protected ExpressionEvaluatorInterface $evaluator;

    /** 分页选项占位（spec §4.2 待钉稿，工票 05/06 定稿后收敛为专门类型） */
    protected readonly array $paginateOptions;

    public function __construct(
        ?ExpressionEvaluatorInterface $evaluator = null,
        array $paginateOptions = [],
    ) {
        $this->evaluator = $evaluator ?? new InterpolationEvaluator();
        $this->paginateOptions = $paginateOptions;
    }

    /**
     * 编译入口（final 模板方法）：hydrate → paginate，顺序不可被子类重排
     */
    final public function compile(Canvas $canvas, mixed $dataset): CompileResult
    {
        $context = new CompileContext($canvas, $dataset, $this->paginateOptions);
        $pages = $this->paginate($this->hydrate($context));

        return new CompileResult($pages, []);
    }

    /**
     * hydrate 步骤（数据填充，1→1）：声明态 graph + dataset → 具体态 graph
     * （求值 + 模板表实例化 + 行高定稿），产物 = 无标记、无模板、高度定稿。
     *
     * 【与 React SSR hydration 无关】hydrate = 数据填充（更名自「展开 / expand」）。
     * 直通条件：dataset === null（未绑），或图上无标记无模板（spec §2 表）。
     * 默认实现 = no-op 直通。
     */
    protected function hydrate(CompileContext $context): Canvas
    {
        return $context->canvas;
    }

    /**
     * paginate 步骤（分页，1→n）：消费高度定稿的画布，产出页序列。
     * 默认实现 = 单元素直通；真实分页随工票 06 落地（语义钉稿见工票 05 / spec §4.2）
     *
     * @return list<Canvas>
     */
    protected function paginate(Canvas $canvas): array
    {
        return [$canvas];
    }
}
