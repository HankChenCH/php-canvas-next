<?php

namespace HankChen\CanvasNext\Compiler;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Contracts\ExpressionEvaluatorInterface;
use HankChen\CanvasNext\Hydrate\CanvasHydrator;
use InvalidArgumentException;

/**
 * 文档级编译入口（spec §10.1，ADR 0009）：N 帧 × 1 dataset 的文档管线——
 * 工票 07 renderPages 的生产端对称物，亦是流语义（工票 11）的参照基底。
 *
 * 独立类：与单画布 CanvasCompiler 共享内部件（CanvasHydrator / CanvasPaginator），
 * 不共享模板方法——§2 预留的「画布序列 → 画布序列」语义形态由本入口承接。
 *
 * 无流路径（本票语义）：逐帧 hydrate（全帧共享单一 warnings collector）→
 * 逐帧 paginate（pageHeight=null：单页 + 溢出判定，spec §10.6 链外帧规则）；
 * 产物 canvases 按帧序 × 页序扁平拼接，warnings 不带帧标识（CompileResult 形状
 * 是 renderPages 工票 07 的消费面，帧标识升格走 spec 版本）。
 *
 * 流链是管线输入（与 dataset 同类，非 wire 内容）；flowChain 非空路径随工票 11
 * 接入——入口形状一次到位，本实现先显式拒绝，绝不静默当无流处理。
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
     * @param list<array<string, mixed>>|null $flowChain 流链声明节点序列；null / [] = 纯文档级管线
     *
     * @throws InvalidArgumentException 空 frames（调用方编程错误，不立领域 code，spec §10.4）；
     *                                  或 flowChain 非空（工票 11 接入前先拒绝）
     */
    public function compile(array $frames, mixed $dataset, ?array $flowChain = null): CompileResult
    {
        if ($frames === []) {
            throw new InvalidArgumentException('frames_empty: 文档编译至少需要一帧，frames 不得为空数组');
        }
        if ($flowChain !== null && $flowChain !== []) {
            throw new InvalidArgumentException('flow_chain_unsupported: 内容流分配随工票 11 落地，当前仅支持无流编译（flowChain 为 null 或 []）');
        }

        // 先全量 hydrate（spec §10.3 时序，流路径的参照基底）：全帧共享单一 collector，
        // warnings 不带帧标识；既有 hydrate 错误语义逐帧原样适用，任一帧失败即抛、无产物逃逸
        $hydrator = new CanvasHydrator($this->evaluator);
        $warnings = new DiagnosticCollector();
        $hydrated = [];
        foreach ($frames as $frame) {
            $hydrated[] = $hydrator->hydrate($frame, $dataset, $warnings);
        }

        // 后逐帧 paginate：链外帧一律 pageHeight=null（单页 + 溢出判定，spec §10.6）——
        // 流路径下 fixed 帧/链外帧同款，paged 帧页高 = 帧画布高随工票 11 接入
        $paginator = new CanvasPaginator();
        $canvases = [];
        foreach ($hydrated as $frame) {
            foreach ($paginator->paginate($frame) as $page) {
                $canvases[] = $page;
            }
        }

        return new CompileResult($canvases, $warnings->all());
    }
}
