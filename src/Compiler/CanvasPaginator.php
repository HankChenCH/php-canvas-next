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
 * 语义（工票 05 钉稿）：
 * - 只拆驱动表的行序列，行是唯一装箱原子、永不切开；表壳每页按声明原位重画，
 *   该页行切片从壳顶重排（行无绝对坐标、绘制期由表格从壳顶堆叠，页间零偏移换算）
 * - 非驱动图层（装饰/水印/背景等）逐页按源坐标原样复制，页可视区外由渲染面位图裁剪自理
 * - 页几何：页宽恒 = 源画布宽；页高 = 构造参数 pageHeight（全页等高，末页留白不收缩）
 * - 溢出判定 = 行序列底边（表顶 y + Σ行高）> 页底，与分页开关无关；分页关时页底 = 画布高，
 *   分页开后唯一残余溢出 = 单行高 > 单页可用区（默认报错，截断选项开启时该行独占一页）
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
    /** @var int|null 页高像素（构造期已验证 > 0）；null = 未启用分页（单元素直通 + 溢出判定） */
    private readonly ?int $pageHeight;

    /** 截断选项（spec §4.2.3，默认关）：开启时超容行独占一页、不报错 */
    private readonly bool $truncate;

    /**
     * @param mixed $pageHeight 页高像素；null = 未启用分页；非 int 或 ≤ 0 抛
     *                          page_geometry_invalid（构造期 fail-fast，spec §4.2.4）
     */
    public function __construct(mixed $pageHeight = null, bool $truncate = false)
    {
        if ($pageHeight !== null && (!is_int($pageHeight) || $pageHeight <= 0)) {
            throw new PaginateException(
                'page_geometry_invalid',
                sprintf('page_geometry_invalid: pageHeight 须为正整数像素（int > 0），得到 %s', json_encode($pageHeight))
            );
        }
        $this->pageHeight = $pageHeight;
        $this->truncate = $truncate;
    }

    /**
     * 编译器 paginateOptions 的承载形态（键：pageHeight / truncate，未知键忽略以留演进空间）
     *
     * @param array{pageHeight?: mixed, truncate?: mixed} $options
     */
    public static function fromOptions(array $options): self
    {
        return new self($options['pageHeight'] ?? null, (bool) ($options['truncate'] ?? false));
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

        // 行装箱：行保序、不切开、无跨页合并；装不下整行即开新页，页数 = 装箱结果不预设
        // （行高读取与流装箱同源：TableGeometry::rowHeight，spec §10.3 防数学漂移）
        $pageRowIndexes = [];
        $current = [];
        $currentHeight = 0;
        foreach ($rowGraphs as $j => $rowGraph) {
            $rowHeight = TableGeometry::rowHeight($rowGraph);

            if ($rowHeight > $available) {
                // 唯一残余溢出 = 单行高 > 单页可用区（spec §4.2.3）：默认报错，截断开则独占一页
                if (!$this->truncate) {
                    throw new PaginateException(
                        'content_overflow',
                        sprintf(
                            'content_overflow: layers[%d] 行[%d] 高 %d > 单页可用区 %d',
                            $driverIndex,
                            $j,
                            $rowHeight,
                            $available
                        )
                    );
                }
                if ($current !== []) {
                    $pageRowIndexes[] = $current;
                    $current = [];
                    $currentHeight = 0;
                }
                $pageRowIndexes[] = [$j];
                continue;
            }

            if ($currentHeight + $rowHeight > $available) {
                $pageRowIndexes[] = $current;
                $current = [];
                $currentHeight = 0;
            }
            $current[] = $j;
            $currentHeight += $rowHeight;
        }
        if ($current !== []) {
            $pageRowIndexes[] = $current;
        }
        if ($pageRowIndexes === []) {
            $pageRowIndexes[] = []; // 零行驱动表 = 1 页空壳（spec §4.2.4）
        }

        // 每页组装：非驱动图层逐页原样复制（源坐标不动，页界外位图裁剪自理），
        // 驱动表壳保留声明、行切片替换——PHP 数组值语义天然隔离各页 graph，
        // fromGraph 重建保证页间零共享可变结构
        $pages = [];
        foreach ($pageRowIndexes as $rowIndexes) {
            $pageTableGraph = $tableGraph;
            $pageTableGraph['rows'] = array_map(static fn (int $index) => $rowGraphs[$index], $rowIndexes);

            $pageLayers = $graph['layers'];
            $pageLayers[$driverIndex] = $pageTableGraph;

            $pages[] = Canvas::fromGraph([
                'canvas' => ['width' => $canvas->getWidth(), 'height' => $this->pageHeight],
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
