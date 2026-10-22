<?php

namespace HankChen\CanvasNext\Compiler;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Exception\PaginateException;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Renderer\PositionResolver;
use InvalidArgumentException;

/**
 * paginate 步骤实现（分页，spec §4.2）：Canvas → Canvas[] 编译期纯函数，
 * 无 I/O、无求值、零新依赖；消费 hydrate 后置不变量（高度定稿），前置违反属调用期误用
 *
 * 语义（工票 05 钉稿；拆分模式分支由提案 003 增补，paginate-semantics v2.0）：
 * - 只拆驱动表的行序列；表壳每页按声明原位重画，该页行切片从壳顶重排
 *   （行无绝对坐标、绘制期由表格从壳顶堆叠，页间零偏移换算）
 * - 原子模式（省略 split）：行是唯一装箱原子、永不切开，装不下整行即整行下页
 * - 拆分模式（split: "lines"）：可拆行（全文本格且内容行数 ≥ 2）在页界即拆——
 *   拆分界 K = min(floor(剩余空间/L), M_max−1) ≥ 1 时前段入当前页、封页，
 *   续段等价新行重入装箱（可再拆；片段内容行数严格递减 → 必然收敛），
 *   行退为最终原子；判定与片段构造在 RowSplitter（纯函数，本类只承载决策）
 * - 非驱动图层（装饰/水印/背景等）逐页按源坐标原样复制，页可视区外由渲染面位图裁剪自理
 * - 页几何：页宽恒 = 源画布宽；页高 = 构造参数 pageHeight（全页等高，末页留白不收缩）
 * - 溢出判定 = 行序列底边（表顶 y + Σ行高）> 页底，与分页开关无关；分页关时页底 = 画布高；
 *   分页开后唯一残余溢出 = 不可拆行高 > 单页可用区（原子模式 = 单行高，拆分模式收窄：
 *   拆分判定先于溢出/truncate，truncate 同步收窄为仅对不可拆行生效）
 * - options 键 split：枚举闭集 v1 仅 "lines"，键在而值 ∉ 闭集 = paginate_option_invalid
 *   构造期 fail-fast（与分页开关正交恒执行）；未知键仍忽略（开键面纪律不变）
 * - 顶层表 0 张 = 单页直通；≥ 2 张 = paginate_target_invalid；嵌套表不驱动分页；
 *   零行 = 1 页空壳；永不产空页
 *
 * 每页是独立 Canvas：经 graph 层重组 + Canvas::fromGraph 重建，页间零共享可变结构，
 * 且每页过 graph()/fromGraph() 往返无损（pass 边界 wire 可表达，spec §2）
 *
 * 锚点解析复用 PositionResolver（纯函数，与渲染期 paintTable 同一表顶语义）；
 * 分页关时表顶按源画布解析、分页开时按页画布（页高 = pageHeight）解析，
 * 均与各页渲染时的锚点解析基准一致
 */
class CanvasPaginator
{
    /** 拆分模式枚举闭集 v1（提案 003）：仅 "lines" = 按内容行逐行拆分 */
    private const SPLIT_LINES = 'lines';

    /** @var int|null 页高像素（构造期已验证 > 0）；null = 未启用分页（单元素直通 + 溢出判定） */
    private readonly ?int $pageHeight;

    /** 截断选项（spec §4.2.3，默认关）：开启时不可拆超容行独占一页、不报错 */
    private readonly bool $truncate;

    /** @var string|null 拆分模式；null = 原子模式（省略 split，行为零变化） */
    private readonly ?string $split;

    /**
     * @param mixed $pageHeight 页高像素；null = 未启用分页；非 int 或 ≤ 0 抛
     *                          page_geometry_invalid（构造期 fail-fast，spec §4.2.4）
     * @param mixed $split      拆分模式；null = 原子模式；非 "lines" 抛
     *                          paginate_option_invalid（枚举守卫与分页开关正交，构造期恒执行）
     */
    public function __construct(mixed $pageHeight = null, bool $truncate = false, mixed $split = null)
    {
        if ($pageHeight !== null && (!is_int($pageHeight) || $pageHeight <= 0)) {
            throw new PaginateException(
                'page_geometry_invalid',
                sprintf('page_geometry_invalid: pageHeight 须为正整数像素（int > 0），得到 %s', json_encode($pageHeight))
            );
        }
        if ($split !== null && (!is_string($split) || $split !== self::SPLIT_LINES)) {
            throw new PaginateException(
                'paginate_option_invalid',
                sprintf('paginate_option_invalid: options.split 枚举闭集 v1 仅 "lines"，得到 %s', json_encode($split))
            );
        }
        $this->pageHeight = $pageHeight;
        $this->truncate = $truncate;
        $this->split = $split;
    }

    /**
     * 编译器 paginateOptions 的承载形态（键：pageHeight / truncate / split，
     * 未知键忽略以留演进空间）
     *
     * @param array{pageHeight?: mixed, truncate?: mixed, split?: mixed} $options
     */
    public static function fromOptions(array $options): self
    {
        return new self(
            $options['pageHeight'] ?? null,
            (bool) ($options['truncate'] ?? false),
            $options['split'] ?? null
        );
    }

    /**
     * @return list<Canvas> 页序列（未启用分页 = 单元素，与入参同一实例）
     */
    public function paginate(Canvas $canvas): array
    {
        if ($this->pageHeight === null) {
            // 分页关（默认直通）：溢出判定仍执行，页底 = 源画布高（spec §2 表/§4.2.3）
            $this->assertRowSequencesWithinBottom($canvas);

            return [$canvas];
        }

        return $this->split($canvas);
    }

    /**
     * 分页关的溢出判定（spec §4.2.3）：所有顶层表同规则——行序列底边（表顶 y + Σ行高）
     * 越画布底即报错；装饰层盒子与表壳声明高不判（隐式裁剪现状不变），嵌套表不判（已折进行高）
     */
    private function assertRowSequencesWithinBottom(Canvas $canvas): void
    {
        foreach ($canvas->getLayers() as $i => $layer) {
            if (!$layer instanceof TableLayer) {
                continue;
            }

            [$anchor, $x, $y] = $layer->getPosition();
            [, $anchorY] = PositionResolver::resolve(
                $anchor,
                $canvas->getWidth(),
                $canvas->getHeight(),
                $layer->getWidth(),
                $layer->getHeight()
            );

            $bottom = $anchorY + $y;
            foreach ($layer->getRows() as $row) {
                $bottom += $row->getHeight();
            }

            if ($bottom > $canvas->getHeight()) {
                throw new PaginateException(
                    'content_overflow',
                    sprintf('content_overflow: layers[%d] 底边 %d > 页高 %d', $i, $bottom, $canvas->getHeight())
                );
            }
        }
    }

    /**
     * 分页开：行装箱切页（spec §4.2.2）。实现走 graph 层（与 CanvasHydrator 同思路）：
     * 源 graph 一次序列化，逐页替换驱动表的 rows 键后经 Canvas::fromGraph 重建
     *
     * @return list<Canvas>
     */
    private function split(Canvas $canvas): array
    {
        $graph = $canvas->graph();

        $driverIndexes = [];
        foreach ($graph['layers'] as $i => $node) {
            if (($node['type'] ?? null) === TableLayer::TYPE) {
                $driverIndexes[] = $i;
            }
        }

        if ($driverIndexes === []) {
            return [$canvas]; // 顶层表 0 张：无内容可分，单页直通不报错（spec §4.2.2）
        }
        if (count($driverIndexes) > 1) {
            // 多表各自装箱页数不相容，静默任选属隐式裁剪语义（spec §4.2.2）
            throw new PaginateException(
                'paginate_target_invalid',
                sprintf('paginate_target_invalid: 分页要求画布上唯一顶层 TableLayer，实际 %d 张', count($driverIndexes))
            );
        }

        $driverIndex = $driverIndexes[0];
        $tableGraph = $graph['layers'][$driverIndex];
        if (isset($tableGraph['template'])) {
            // 前置违反（paginate 消费 hydrate 产物，spec §2）：模板态表行序列未定稿，无法装箱。
            // API 误用归调用期错误（先例 TableLayer::addRow），不占 wire 错误 code
            throw new InvalidArgumentException('paginate_requires_hydrated_canvas: 驱动表仍处模板态，须先经 hydrate 实例化');
        }

        $rowGraphs = array_values($tableGraph['rows'] ?? []);
        // §4.2.5 结构预留：页首/页尾固定行落地时，在此扣减其行高
        // （可用区 = 页高 − 页首行 − 页尾行），装箱语义本身不变
        $available = $this->pageHeight - $this->shellTopOnPage($tableGraph, $canvas->getWidth());

        // 行装箱决策表（提案 003，按序判定、每行恰走一支）：
        // ① 装得下 → 入当前页；② 拆分模式且可拆且 K ≥ 1 → 前段入当前页、封页，
        //    续段重入装箱（等价新行，可再拆）；③ 不可拆残余超高 → truncate 独占页 / 报错；
        // ④ 否则整行移下页。拆分分支先于 truncate/overflow 判定（工票 04 决策 5）——
        // 拆分模式下 truncate 只达不可拆行，rowHeight > available 的可拆行照拆不报错。
        // 装箱单位改行 graph（片段无源下标），origin 保留源行号供错误消息定位；
        // 续段 unshift 回队首 = 保序消费。收敛：每次拆分 K ≥ 1、片段内容行数严格递减
        // → 步进必前进、总片段数 ≤ Σ行内容数，无 0 步进（RowSplitterTest 钉结构，
        // 语料 split-lines-row-across-three-pages 钉多页行为）
        $worklist = [];
        foreach ($rowGraphs as $j => $rowGraph) {
            $worklist[] = ['graph' => $rowGraph, 'origin' => $j];
        }

        $pageRowGraphs = [];
        $current = [];
        $currentHeight = 0;
        while ($worklist !== []) {
            ['graph' => $rowGraph, 'origin' => $origin] = array_shift($worklist);
            $rowHeight = TableGeometry::rowHeight($rowGraph);

            if ($currentHeight + $rowHeight <= $available) {
                // ① 装入当前页（与现役同）
                $current[] = $rowGraph;
                $currentHeight += $rowHeight;
                continue;
            }

            if ($this->split !== null) {
                // ② 拆分模式：可拆性/K=0/防线（P<0、续段≤0）都收拢在 RowSplitter 返回值里
                $bound = RowSplitter::splitBound($rowGraph, $available - $currentHeight);
                $fragments = $bound >= 1 ? RowSplitter::splitRowGraph($rowGraph, $bound) : null;
                if ($fragments !== null) {
                    // 前段高 = K×L + P ≤ 剩余（P ≠ 0 的非零声明差为未钉区，语料 v1 恒 0）
                    $current[] = $fragments['front'];
                    $currentHeight += TableGeometry::rowHeight($fragments['front']);
                    $pageRowGraphs[] = $current;
                    $current = [];
                    $currentHeight = 0;
                    array_unshift($worklist, ['graph' => $fragments['cont'], 'origin' => $origin]);
                    continue;
                }
            }

            if ($rowHeight > $available) {
                // ③ 唯一残余溢出 = 不可拆行高 > 单页可用区（v2.0 收窄）：默认报错，截断开则独占一页
                if (!$this->truncate) {
                    throw new PaginateException(
                        'content_overflow',
                        sprintf(
                            'content_overflow: layers[%d] 行[%d] 高 %d > 单页可用区 %d',
                            $driverIndex,
                            $origin,
                            $rowHeight,
                            $available
                        )
                    );
                }
                if ($current !== []) {
                    $pageRowGraphs[] = $current;
                    $current = [];
                    $currentHeight = 0;
                }
                $pageRowGraphs[] = [$rowGraph];
                continue;
            }

            // ④ 整行移下页开新页（现役原子移动；truncate 不涉）
            if ($current !== []) {
                $pageRowGraphs[] = $current;
            }
            $current = [$rowGraph];
            $currentHeight = $rowHeight;
        }
        if ($current !== []) {
            $pageRowGraphs[] = $current;
        }
        if ($pageRowGraphs === []) {
            $pageRowGraphs[] = []; // 零行驱动表 = 1 页空壳（spec §4.2.4）
        }

        // 每页组装：非驱动图层逐页原样复制（源坐标不动，页界外位图裁剪自理），
        // 驱动表壳保留声明、行切片替换——PHP 数组值语义天然隔离各页 graph，
        // fromGraph 重建保证页间零共享可变结构（片段即普通行 graph，复用同一组装）。
        // canvas 键手工重组：背景必须显式携带每页（ADR 0022 易漏点 1）
        $pages = [];
        foreach ($pageRowGraphs as $rows) {
            $pageTableGraph = $tableGraph;
            $pageTableGraph['rows'] = $rows;

            $pageLayers = $graph['layers'];
            $pageLayers[$driverIndex] = $pageTableGraph;

            $pageCanvas = [
                'width' => $canvas->getWidth(),
                'height' => $this->pageHeight,
            ];
            if (isset($graph['canvas']['background'])) {
                $pageCanvas['background'] = $graph['canvas']['background'];
            }

            $pages[] = Canvas::fromGraph([
                'canvas' => $pageCanvas,
                'layers' => $pageLayers,
            ]);
        }

        return $pages;
    }

    /**
     * 驱动表壳在页上的顶 y：锚点按页画布解析（页宽 = 源画布宽、页高 = pageHeight），
     * 与该页渲染期 paint 的锚点解析基准一致；每页可用区同构（spec §4.2.2）。
     * 数学委托 TableGeometry::shellTop 共享实现（流装箱 FrameCapacity 同源，spec §10.3）
     */
    private function shellTopOnPage(array $tableGraph, int $pageWidth): int
    {
        return TableGeometry::shellTop($tableGraph, $pageWidth, $this->pageHeight);
    }
}
