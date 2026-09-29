<?php

namespace HankChen\CanvasNext\Compiler\Flow;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Exception\FlowException;
use HankChen\CanvasNext\Exception\PaginateException;
use HankChen\CanvasNext\Layer\TableLayer;

/**
 * 流链校验（spec §10.2，fail-fast 于入口执行，先于全量 hydrate）：
 *
 * 1. frame 索引合法（0 ≤ idx < 帧数）且不重复；
 * 2. paged 节点至多一个且须在链尾；paged 不得携带 quota；
 * 3. 链上帧须恰有 1 张顶层 TableLayer 且为模板态；
 * 4. 链序与帧序一致递增（可跳过无表帧，不可乱序回跳）；
 * 5. 未知键拒绝（封闭数据通道，CompileContext.options 同款）。
 * 以上违反 → flow_chain_invalid。
 *
 * rowsPath 一致性（flow_rows_path_inconsistent）：链上各帧槽位表 rowsPath 全链一致；
 * 链外帧若有顶层表，其 rowsPath 不得与链相同——同 rowsPath 的表游离链外 =
 * 分配权威被架空（幽灵行）。
 *
 * 任意帧顶层 TableLayer ≥ 2 → 既有 paginate_target_invalid 原样抛（分页段
 * PaginateException，不换 code 不换段）。核心不变量：链 rowsPath 的行只有一条权威分配路径。
 */
final class FlowChainValidator
{
    /** 节点封闭键面（spec §10.2：节点是可序列化声明数据，过 wire、进 fixture、被 Go 镜像） */
    private const NODE_KEYS = ['frame', 'mode', 'quota', 'omitIfEmpty'];

    private const MODE_FIXED = 'fixed';
    private const MODE_PAGED = 'paged';

    /**
     * @param list<Canvas> $frames 模板态帧序列（声明态，校验不求值不填充）
     * @param list<array<string, mixed>> $chain 流链声明节点序列
     *
     * @return array<int, int> frame 下标 => 该帧顶层槽位表在图层序列中的下标（编译器回写定位用）
     *
     * @throws FlowException flow_chain_invalid / flow_rows_path_inconsistent
     * @throws PaginateException paginate_target_invalid（任意帧 ≥2 顶层表）
     */
    public static function validate(array $frames, array $chain): array
    {
        $frameCount = count($frames);

        // 规则 1/2/4/5 + 节点类型面：索引、重复、mode/quota/omitIfEmpty、链序、封闭键
        $seenFrames = [];
        $previousFrame = null;
        foreach ($chain as $node) {
            if (!is_array($node)) {
                throw new FlowException('flow_chain_invalid', 'flow_chain_invalid: 流链节点须为对象形态，得到 ' . get_debug_type($node));
            }
            foreach (array_keys($node) as $key) {
                if (!in_array($key, self::NODE_KEYS, true)) {
                    throw new FlowException('flow_chain_invalid', "flow_chain_invalid: 未知键 {$key}（封闭键面 " . implode('/', self::NODE_KEYS) . '）');
                }
            }

            $frame = $node['frame'] ?? null;
            if (!is_int($frame) || $frame < 0 || $frame >= $frameCount) {
                throw new FlowException('flow_chain_invalid', sprintf('flow_chain_invalid: frame 索引越界或非整数（%s，帧数 %d）', json_encode($frame), $frameCount));
            }
            if (isset($seenFrames[$frame])) {
                throw new FlowException('flow_chain_invalid', "flow_chain_invalid: frame {$frame} 在链上重复");
            }
            $seenFrames[$frame] = true;

            $mode = $node['mode'] ?? null;
            if ($mode !== self::MODE_FIXED && $mode !== self::MODE_PAGED) {
                throw new FlowException('flow_chain_invalid', sprintf("flow_chain_invalid: mode 须为 %s|%s，得到 %s", self::MODE_FIXED, self::MODE_PAGED, json_encode($mode)));
            }
            if (array_key_exists('quota', $node)) {
                // quota 仅 fixed 合法且允许 0（non-negative-int，显式不吃行的声明位）
                if ($mode === self::MODE_PAGED) {
                    throw new FlowException('flow_chain_invalid', 'flow_chain_invalid: paged 节点不得携带 quota');
                }
                if (!is_int($node['quota']) || $node['quota'] < 0) {
                    throw new FlowException('flow_chain_invalid', sprintf('flow_chain_invalid: quota 须为非负整数，得到 %s', json_encode($node['quota'])));
                }
            }
            if (array_key_exists('omitIfEmpty', $node) && !is_bool($node['omitIfEmpty'])) {
                throw new FlowException('flow_chain_invalid', sprintf('flow_chain_invalid: omitIfEmpty 须为布尔，得到 %s', json_encode($node['omitIfEmpty'])));
            }

            if ($previousFrame !== null && $frame <= $previousFrame) {
                throw new FlowException('flow_chain_invalid', "flow_chain_invalid: 链序须按帧序一致递增（{$previousFrame} → {$frame} 乱序回跳）");
            }
            $previousFrame = $frame;
        }

        // 规则 2：paged 至多一个且须在链尾（链尾判定同时封住双 paged）
        $lastIndex = count($chain) - 1;
        foreach ($chain as $index => $node) {
            if ($node['mode'] === self::MODE_PAGED && $index !== $lastIndex) {
                throw new FlowException('flow_chain_invalid', sprintf('flow_chain_invalid: paged 节点须在链尾（节点 %d/%d）', $index, $lastIndex));
            }
        }

        // 任意帧 ≥2 顶层表 → 既有 paginate_target_invalid 原样抛（先于规则 3：
        // 「恰有 1 张」的 2 表特例由本规则接管，链上链外一体适用）
        foreach ($frames as $frameIndex => $frame) {
            $topLevelTables = self::topLevelTables($frame);
            if (count($topLevelTables) >= 2) {
                throw new PaginateException(
                    'paginate_target_invalid',
                    sprintf('paginate_target_invalid: 分页要求画布上唯一顶层 TableLayer，帧 %d 实际 %d 张', $frameIndex, count($topLevelTables))
                );
            }
        }

        // 规则 3 + rowsPath 链内一致：链上帧恰有 1 张顶层模板表，取其 rowsPath 对齐全链
        $tableIndexByFrame = [];
        $chainRowsPath = null;
        foreach ($chain as $node) {
            $frameIndex = $node['frame'];
            $tables = self::topLevelTables($frames[$frameIndex]);
            if ($tables === []) {
                throw new FlowException('flow_chain_invalid', "flow_chain_invalid: 链上帧 {$frameIndex} 无顶层 TableLayer（须恰有 1 张模板态槽位表）");
            }

            $tableIndex = array_key_first($tables);
            $table = $tables[$tableIndex];
            if ($table->getTemplate() === null) {
                throw new FlowException('flow_chain_invalid', "flow_chain_invalid: 链上帧 {$frameIndex} 的顶层表非模板态（链槽位表须为模板态）");
            }

            $rowsPath = $table->getRowsPath();
            if ($chainRowsPath !== null && $rowsPath !== $chainRowsPath) {
                throw new FlowException('flow_rows_path_inconsistent', "flow_rows_path_inconsistent: 链内 rowsPath 不一致（{$chainRowsPath} vs {$rowsPath}，帧 {$frameIndex}）");
            }
            $chainRowsPath ??= $rowsPath;
            $tableIndexByFrame[$frameIndex] = $tableIndex;
        }

        // rowsPath 链外碰撞：链外帧顶层表与链同 rowsPath → 幽灵行，拒绝（spec §10.2
        // 「链外帧若有顶层表，其 rowsPath 不得与链相同」，不限定模板态）
        foreach ($frames as $frameIndex => $frame) {
            if (isset($tableIndexByFrame[$frameIndex])) {
                continue;
            }
            foreach (self::topLevelTables($frame) as $table) {
                if ($table->getRowsPath() === $chainRowsPath) {
                    throw new FlowException('flow_rows_path_inconsistent', "flow_rows_path_inconsistent: 链外帧 {$frameIndex} 顶层表与链同 rowsPath（{$chainRowsPath}），分配权威被架空");
                }
            }
        }

        return $tableIndexByFrame;
    }

    /**
     * 帧的顶层表枚举（图层下标 => 表实例）：与 graph()['layers'] 同序，
     * 下标可直接用于产物图层级回写定位
     *
     * @return array<int, TableLayer>
     */
    private static function topLevelTables(Canvas $frame): array
    {
        $tables = [];
        foreach ($frame->getLayers() as $index => $layer) {
            if ($layer instanceof TableLayer) {
                $tables[$index] = $layer;
            }
        }

        return $tables;
    }
}
