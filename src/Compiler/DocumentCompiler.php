<?php

namespace HankChen\CanvasNext\Compiler;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\Flow\FlowChainValidator;
use HankChen\CanvasNext\Compiler\Flow\FlowConsumerFactory;
use HankChen\CanvasNext\Compiler\Flow\FrameCapacity;
use HankChen\CanvasNext\Contracts\ExpressionEvaluatorInterface;
use HankChen\CanvasNext\Hydrate\CanvasHydrator;
use InvalidArgumentException;

/**
 * 文档级编译入口（spec §10.1，ADR 0009）：N 帧 × 1 dataset 的文档管线——
 * 工票 07 renderPages 的生产端对称物。
 *
 * 独立类：与单画布 CanvasCompiler 共享内部件（CanvasHydrator / CanvasPaginator），
 * 不共享模板方法——§2 预留的「画布序列 → 画布序列」语义形态由本入口承接。
 *
 * 两条路径（flowChain null / [] = 无流，非空 = 流装箱，spec §10.3 时序）：
 * - 无流：逐帧 hydrate（全帧共享单一 warnings collector）→ 逐帧 paginate
 *   （pageHeight=null：单页 + 溢出判定，spec §10.6 链外帧规则）。
 * - 流：0 链校验（fail-fast，FlowChainValidator）→ 1 全量 hydrate（行高定稿、
 *   $index 即全局序号）→ 2 flow 分配（remaining = 链首帧槽位实例行序列，逐节点
 *   FlowConsumerFactory::fromNode(node)->take(FrameCapacity::of(frame), remaining)
 *   后 array_splice 切份额）→ 3 回写 + 分页（槽位 rows 整体替换 → fromGraph 重建 →
 *   既有 CanvasPaginator 逐帧分页；fixed/链外帧 pageHeight=null，paged 帧页高 = 帧画布高）。
 *   CanvasPaginator 零改动复用——流是 paginate 的调用者而非对它的改造（ADR 0009）。
 *
 * 产物 canvases 按帧序 × 页序扁平拼接，warnings 不带帧标识（CompileResult 形状
 * 是 renderPages 工票 07 的消费面，帧标识升格走 spec 版本）。
 *
 * 流链是管线输入（与 dataset 同类，非 wire 内容）；分配期超容复用 paginate 段
 * content_overflow（同一装箱数学同一失败语义），链校验错误为文档期 FlowException
 * （flow_chain_invalid / flow_rows_path_inconsistent，spec §10.4）。
 */
final class DocumentCompiler
{
    public function __construct(
        private readonly ?ExpressionEvaluatorInterface $evaluator = null,
    ) {
    }

    /**
     * @param list<Canvas> $frames 帧序列（设计期独立、版式各异的完整画布，帧序即拼接序）
     * @param mixed $dataset 全帧共享的数据集（null = 未绑，逐帧恒等直通）
     * @param list<array<string, mixed>>|null $flowChain 流链声明节点序列
     *        （每节点 {frame, mode, quota?, omitIfEmpty?}）；null / [] = 纯文档级管线
     *
     * @throws InvalidArgumentException 空 frames（调用方编程错误，不立领域 code，spec §10.4）
     * @throws FlowException        链校验违反（flow_chain_invalid / flow_rows_path_inconsistent）
     * @throws PaginateException    paginate_target_invalid（任意帧 ≥2 顶层表，入口 fail-fast）；
     *                               content_overflow（分配期首行超容 / 帧内 paginate 溢出）
     * @throws \HankChen\CanvasNext\Exception\HydrateException 既有 hydrate 错误语义逐帧原样适用
     */
    public function compile(array $frames, mixed $dataset, ?array $flowChain = null): CompileResult
    {
        if ($frames === []) {
            throw new InvalidArgumentException('frames_empty: 文档编译至少需要一帧，frames 不得为空数组');
        }

        $chain = $flowChain ?? []; // 显式 [] 与 null 同义（spec §10.2）
        if ($chain === []) {
            return $this->compileWithoutFlow($frames, $dataset);
        }

        return $this->compileWithFlow($frames, $dataset, $chain);
    }

    /**
     * 无流路径（工票 10 语义）：先全量 hydrate，后逐帧 paginate（pageHeight=null）
     */
    private function compileWithoutFlow(array $frames, mixed $dataset): CompileResult
    {
        [$hydrated, $warnings] = $this->hydrateAll($frames, $dataset);

        $paginator = new CanvasPaginator();
        $canvases = [];
        foreach ($hydrated as $frame) {
            foreach ($paginator->paginate($frame) as $page) {
                $canvases[] = $page;
            }
        }

        return new CompileResult($canvases, $warnings->all());
    }

    /**
     * 流装箱路径（spec §10.3 时序：0 链校验 → 1 全量 hydrate → 2 分配 → 3 回写+分页）
     *
     * @param list<Canvas> $frames
     * @param list<array<string, mixed>> $chain
     */
    private function compileWithFlow(array $frames, mixed $dataset, array $chain): CompileResult
    {
        // 0 链校验（fail-fast 于入口，先于 hydrate）：返回 frame 下标 => 槽位表图层下标
        $tableIndexByFrame = FlowChainValidator::validate($frames, $chain);

        // 1 全量 hydrate：$index 即全局序号、行高定稿——分配与聚合正确性的来源（ADR 0009）
        [$hydrated, $warnings] = $this->hydrateAll($frames, $dataset);

        // 2 flow 分配（graph 层，与分页同思路）：remaining = 链首帧槽位实例行序列，
        // 逐节点 take 后 array_splice 切份额；未分派帧的实例行丢弃（回写 = 整体替换）
        $graphs = array_map(static fn (Canvas $canvas) => $canvas->graph(), $hydrated);
        $firstFrame = (int) $chain[0]['frame'];
        $remaining = array_values($graphs[$firstFrame]['layers'][$tableIndexByFrame[$firstFrame]]['rows'] ?? []);

        $allocations = [];
        foreach ($chain as $node) {
            $frameIndex = (int) $node['frame'];
            $tableIndex = $tableIndexByFrame[$frameIndex];

            $consumer = FlowConsumerFactory::fromNode($node);
            $capacity = FrameCapacity::of($hydrated[$frameIndex], $graphs[$frameIndex]['layers'][$tableIndex]);
            $taken = $consumer->take($capacity, $remaining);
            $allocations[$frameIndex] = [
                'rows' => array_splice($remaining, 0, $taken),
                'node' => $node,
            ];
        }

        // 3 回写 + 分页 + 4 帧序 × 页序扁平拼接：链上帧灌入分配结果重建后分页
        //（fixed 帧单页、paged 帧页高 = 帧画布高）；链外帧 hydrate 产物直通分页
        $paginator = new CanvasPaginator();
        $canvases = [];
        foreach ($frames as $frameIndex => $frame) {
            if (!isset($allocations[$frameIndex])) {
                array_push($canvases, ...$paginator->paginate($hydrated[$frameIndex]));
                continue;
            }

            ['rows' => $share, 'node' => $node] = $allocations[$frameIndex];
            if ($share === [] && ($node['omitIfEmpty'] ?? false)) {
                continue; // 零行跳帧：不产页、不进产物序列（spec §10.3）
            }

            $graph = $graphs[$frameIndex];
            $tableNode = &$graph['layers'][$tableIndexByFrame[$frameIndex]];
            if (array_key_exists('template', $tableNode)) {
                // 未绑数据集（spec §3 恒等直通在流路径原样适用）：无实例行可分配，
                // 模板帧以声明态零行空壳单页直通——不写 rows（template ⊕ rows 互斥）、
                // 不重建不分页；观测几何与零行矩阵一致（spec §10.3 第 5 条）
                $canvases[] = $hydrated[$frameIndex];
                continue;
            }
            $tableNode['rows'] = array_values($share);
            unset($tableNode);

            $canvas = Canvas::fromGraph($graph);
            $framePaginator = $node['mode'] === 'paged'
                ? new CanvasPaginator($frame->getHeight()) // 页高 = 帧画布高（v1 无覆盖位）
                : new CanvasPaginator();
            array_push($canvases, ...$framePaginator->paginate($canvas));
        }

        return new CompileResult($canvases, $warnings->all());
    }

    /**
     * 全帧共享单一 collector 的全量 hydrate（spec §10.3 步骤 1）：
     * warnings 不带帧标识；既有 hydrate 错误语义逐帧原样适用，任一帧失败即抛
     *
     * @param list<Canvas> $frames
     * @return array{0: list<Canvas>, 1: DiagnosticCollector}
     */
    private function hydrateAll(array $frames, mixed $dataset): array
    {
        $hydrator = new CanvasHydrator($this->evaluator);
        $warnings = new DiagnosticCollector();
        $hydrated = [];
        foreach ($frames as $frame) {
            $hydrated[] = $hydrator->hydrate($frame, $dataset, $warnings);
        }

        return [$hydrated, $warnings];
    }
}
