<?php

namespace HankChen\CanvasNext\Compiler;

use HankChen\CanvasNext\Layer\TextLayer;

/**
 * 逐行拆分纯函数（提案 003，paginate-semantics v2.0）：模板表行在页界按内容行切开的
 * 可拆判定 / 拆分求界 / 片段构造三件套；装箱决策表在 CanvasPaginator。
 * 零 I/O、零渲染依赖，与 hydrate 同源消费定稿行 graph（前置不变：高度已定稿）。
 *
 * 语义权威 = submodule 语料 corpus/paginate-semantics/v2.0/ + spec/pipeline.md §3/§7；
 * 适用域 = 默认启发式度量栈（行数与片段内容由定稿图给定，语料以显式 \n 硬断行钉面，
 * 不依赖断行度量）；反例域 = 增强度量注入与字素切分缝（编译期行数漂移由渲染面位图
 * 裁剪兜底，不 fail-fast）。格间 lineHeightPx 异质行、非零声明余量（P ≠ 0）为未钉
 * 不承诺区，公式照提案逐字实现、不顺手扩展。
 */
final class RowSplitter
{
    /**
     * 行的可拆统计：行内所有格 content 均为 TextLayer 时返回最大内容行数与行级行高
     * （M_i = TextLayer::fromGraph 重建取 getLines() 长度，\n 为硬断行、不新增度量注入面；
     * L 取首格内容层 lineHeightPx()，v1 语料只钉全行同质 L）；
     * 任一格 content 缺失或非 TextLayer（图片/QR 单体格）→ null = 不可拆。
     *
     * @param array<string, mixed> $rowGraph 行图层 graph
     * @return array{maxLines: int, lineHeightPx: int}|null
     */
    public static function lineStats(array $rowGraph): ?array
    {
        $cells = $rowGraph['cells'] ?? null;
        if (!is_array($cells) || $cells === []) {
            return null;
        }

        $maxLines = 0;
        $lineHeightPx = 0;
        foreach (array_values($cells) as $i => $cell) {
            $content = is_array($cell) ? ($cell['content'] ?? null) : null;
            if (!is_array($content) || ($content['type'] ?? null) !== TextLayer::TYPE) {
                return null;
            }

            $text = TextLayer::fromGraph($content);
            $maxLines = max($maxLines, count($text->getLines()));
            if ($i === 0) {
                $lineHeightPx = $text->lineHeightPx();
            }
        }

        return ['maxLines' => $maxLines, 'lineHeightPx' => $lineHeightPx];
    }

    /**
     * 拆分界 K = min(floor(剩余空间 / L), M_max − 1)（剩余空间 = available − currentHeight，
     * 由调用方传入）；K < 1（含非全文本格、M_max ≤ 1、P < 0 的不一致 graph、L ≤ 0 非法字号）
     * 返回 0 = 不拆，整行走原子路径。防线与 Go 侧 splitBound 成对平移（守卫是几何契约的一部分）。
     *
     * @param array<string, mixed> $rowGraph 行图层 graph
     */
    public static function splitBound(array $rowGraph, int $remaining): int
    {
        $stats = self::lineStats($rowGraph);
        if ($stats === null) {
            return 0;
        }

        $lineHeightPx = $stats['lineHeightPx'];
        if ($lineHeightPx <= 0) {
            return 0;
        }

        // 声明余量 P = 行高 − M_max × L（padding/声明差；v1 语料恒 0）：
        // P < 0 = 行高连内容行数都装不下的不一致 graph，视为不可拆（防线用例钉）
        $declared = TableGeometry::rowHeight($rowGraph) - $stats['maxLines'] * $lineHeightPx;
        if ($declared < 0) {
            return 0;
        }

        $bound = min(intdiv($remaining, $lineHeightPx), $stats['maxLines'] - 1);

        return $bound < 1 ? 0 : $bound;
    }

    /**
     * 行片段构造（Σ 高恒等 = 原行高，装箱总量守恒）：前段行高 = K × L + P、
     * 续段行高 = 原行高 − 前段行高（≤ 0 即不可拆，防线）；两片段所有格盒高与内容层
     * spec.height = 所在片段行高（独立边框盒）；格内容值按 \n 硬断行切片——前段取前
     * min(M_i, K) 段、续段承余段（无余段 = 空串，格盒仍画满续段高）；其余字段逐字段
     * 克隆源节点，不新增不删除键（wire 键形纪律）。
     * 返回 null = 不可拆（k < 1 / 非全文本格 / P < 0 / 续段 ≤ 0）。
     *
     * 注：hydrate 产物 TextLayer data 恒 StaticValue（表达式已在 hydrate 定稿求值），
     * 内容值切片写 data.value；直调 paginate 传入未定稿 ExpressionValue 属未钉域。
     *
     * @param array<string, mixed> $rowGraph 行图层 graph
     * @return array{front: array<string, mixed>, cont: array<string, mixed>}|null
     */
    public static function splitRowGraph(array $rowGraph, int $k): ?array
    {
        if ($k < 1) {
            return null;
        }

        $stats = self::lineStats($rowGraph);
        if ($stats === null) {
            return null;
        }

        $lineHeightPx = $stats['lineHeightPx'];
        $rowHeight = TableGeometry::rowHeight($rowGraph);
        $declared = $rowHeight - $stats['maxLines'] * $lineHeightPx;
        if ($declared < 0) {
            return null;
        }

        $frontHeight = $k * $lineHeightPx + $declared;
        $contHeight = $rowHeight - $frontHeight;
        if ($contHeight <= 0) {
            return null;
        }

        $front = $rowGraph;
        $cont = $rowGraph;
        $front['spec']['shape']['height'] = $frontHeight;
        $cont['spec']['shape']['height'] = $contHeight;

        $frontCells = [];
        $contCells = [];
        foreach ($rowGraph['cells'] as $cell) {
            $frontCell = $cell;
            $contCell = $cell;
            // 格独立分片：所有格盒高 = 所在片段行高（独立边框盒，css-break 先例）
            $frontCell['spec']['shape']['height'] = $frontHeight;
            $contCell['spec']['shape']['height'] = $contHeight;

            $content = $cell['content'] ?? null;
            if (is_array($content)) {
                $text = TextLayer::fromGraph($content);
                $take = min(count($text->getLines()), $k);
                $segments = explode("\n", (string) ($content['data']['value'] ?? ''));

                $frontContent = $content;
                $frontContent['spec']['shape']['height'] = $frontHeight;
                $frontContent['data']['value'] = implode("\n", array_slice($segments, 0, $take));

                $contContent = $content;
                $contContent['spec']['shape']['height'] = $contHeight;
                $contContent['data']['value'] = implode("\n", array_slice($segments, $take));

                $frontCell['content'] = $frontContent;
                $contCell['content'] = $contContent;
            }

            $frontCells[] = $frontCell;
            $contCells[] = $contCell;
        }
        $front['cells'] = $frontCells;
        $cont['cells'] = $contCells;

        return ['front' => $front, 'cont' => $cont];
    }
}
