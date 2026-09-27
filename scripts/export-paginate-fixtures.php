<?php

declare(strict_types=1);

/**
 * paginate 段三端共享 fixture 导出（spec §4.2.7，工票 05 钉稿/工票 06 导出）。
 *
 * paginate-semantics.json（contract: paginate-semantics v1）
 * 分页语义用例（12 规定用例集）：{name, description, graph, options, expect}
 * 管线 = graph → Canvas::fromGraph 重建 → paginate(pageHeight, truncate?) → list<页 graph>
 * options = {pageHeight: int, truncate?: bool}；缺 pageHeight = 分页关（单元素直通 + 溢出判定）
 * expect = {pages: [页 graph…]} | {errorCode}（paginate 段 code，spec §5.2）
 *
 * 输入不变式：graph 一律为 hydrate 后产物（无标记、无模板、高度定稿的具体树）——
 * 分页不重演填充，两段 fixture 各锁各的语义。
 *
 * 用例语义源 = php-canvas-next 单测（tests/Compiler/CanvasPaginatorTest.php）逐用例平移；
 * 导出即自检：authored 结构期望（页数、行归属、直通字节等价）与 PHP 实际行为不符直接失败，
 * 防 fixture 与实现漂移。Go 镜像按同名用例对齐（Go 实现另 effort）。
 *
 * 用法: php scripts/export-paginate-fixtures.php [输出目录]
 * 默认输出到 ../go-canvas/paginate/testdata/（JSON 提交进 Go 仓库，
 * 经 go-canvas 的 make paginate-fixtures 人工触发再生成；Go CI 不装 PHP）。
 */

require __DIR__ . '/../vendor/autoload.php';

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\CanvasPaginator;
use HankChen\CanvasNext\Exception\CanvasNextExceptionInterface;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TextLayer;

// ---------------------------------------------------------------------------
// 输入构造 helpers（一律 hydrate 后产物形态：具体行、具体行高、无标记无模板）
// ---------------------------------------------------------------------------

/** 固定行高单格文本行 */
function paginateRow(string $text, int $height): TableRowLayer
{
    $cell = TableCellLayer::make(320, $height);
    $cell->addContentLayer(TextLayer::make(320, $height)->setText($text));

    return TableRowLayer::make(320, $height)->addCell($cell);
}

/** 驱动表：y=50、宽 320、#fff 壳（行高 Σ 与声明高由用例给定） */
function paginateTable(int $declaredHeight, TableRowLayer ...$rows): TableLayer
{
    $table = TableLayer::make(320, $declaredHeight, '#fff');
    $table->setPosition(0, 50);
    foreach ($rows as $row) {
        $table->addRow($row);
    }

    return $table;
}

/** 标准四行表：行高 150+50+100+100（Σ=400） */
function paginateStandardTable(int $declaredHeight = 400): TableLayer
{
    return paginateTable(
        $declaredHeight,
        paginateRow('行1', 150),
        paginateRow('行2', 50),
        paginateRow('行3', 100),
        paginateRow('行4', 100),
    );
}

/** 标准画布 320×450 = 表 + 页眉装饰（y=5）+ 落款（y=420，页可视区外） */
function paginateStandardCanvas(): Canvas
{
    return Canvas::make(
        320,
        450,
        paginateStandardTable(),
        TextLayer::make(120, 30)->setText('页眉装饰')->setPosition(10, 5),
        TextLayer::make(120, 30)->setText('落款')->setPosition(10, 420),
    );
}

/** 页上驱动表行文本序列（行归属自检用） */
function paginateRowTexts(Canvas $page): array
{
    $texts = [];
    foreach ($page->getLayers() as $layer) {
        if ($layer instanceof TableLayer) {
            foreach ($layer->getRows() as $row) {
                $content = $row->getCells()[0]->getContentLayer();
                $texts[] = $content instanceof TextLayer ? $content->getText() : '';
            }
        }
    }

    return $texts;
}

// ---------------------------------------------------------------------------
// 规定用例集（spec §4.2.7 十二例，工票 06 实现验收 = 覆盖全表）
// authoredExpect：errorCode = 预期报错；否则 pages = 页数、rowsPerPage = 每页行文本、
// identity = 单页且页 graph 与输入字节等价
// ---------------------------------------------------------------------------

function paginateCases(): array
{
    $standardGraph = paginateStandardCanvas()->graph();
    $noDecorationGraph = Canvas::make(320, 450, paginateStandardTable())->graph();

    return [
        [ // 1
            'passthrough-no-page-height',
            '未给页高 = 分页关：单元素直通，页 graph 与输入字节等价（溢出判定仍执行但本例不越界）',
            $standardGraph,
            [],
            ['pages' => 1, 'identity' => true],
        ],
        [ // 2
            'single-page-fit',
            '内容单页装下 = 1 页恒等（pageHeight = 源画布高，装箱不切分）',
            $standardGraph,
            ['pageHeight' => 450],
            ['pages' => 1, 'identity' => true],
        ],
        [ // 3
            'row-packing-split',
            '多页切分：页高 250、表顶 y=50 → 可用区 200，行保序装箱为 [150+50][100+100]，'
            . '表壳每页按声明重画、行切片从壳顶重排',
            $noDecorationGraph,
            ['pageHeight' => 250],
            ['pages' => 2, 'rowsPerPage' => [['行1', '行2'], ['行3', '行4']]],
        ],
        [ // 4
            'non-table-layers-replicated',
            '非驱动图层逐页原样复制：页眉（y=5）与落款（y=420，页可视区外、位图裁剪自理）'
            . '都按源坐标出现在每一页',
            $standardGraph,
            ['pageHeight' => 250],
            ['pages' => 2, 'rowsPerPage' => [['行1', '行2'], ['行3', '行4']]],
        ],
        [ // 5
            'exact-fill-no-trailing-page',
            '行恰好填满可用区（100+100 = 页高 250 − 表顶 50）= 恰好 1 页，不产尾随空页',
            Canvas::make(320, 450, paginateTable(200, paginateRow('行1', 100), paginateRow('行2', 100)))->graph(),
            ['pageHeight' => 250],
            ['pages' => 1, 'rowsPerPage' => [['行1', '行2']]],
        ],
        [ // 6
            'zero-rows-empty-shell',
            '零行驱动表 = 1 页空壳：壳按声明画、行区零高',
            Canvas::make(320, 450, paginateTable(400))->graph(),
            ['pageHeight' => 250],
            ['pages' => 1, 'rowsPerPage' => [[]]],
        ],
        [ // 7
            'overflow-off-throws',
            '分页关 + 行序列底边越画布底（表顶 50 + Σ400 = 450 > 画布 400）→ content_overflow',
            Canvas::make(320, 400, paginateStandardTable())->graph(),
            [],
            ['errorCode' => 'content_overflow'],
        ],
        [ // 8
            'single-row-oversize-throws',
            '分页开 + 单行超页容量（行高 300 > 可用区 200）且截断关 → content_overflow',
            Canvas::make(320, 450, paginateTable(300, paginateRow('大行', 300)))->graph(),
            ['pageHeight' => 250],
            ['errorCode' => 'content_overflow'],
        ],
        [ // 9
            'truncate-option-isolates-row',
            '截断开：超容行独占一页、无报错（页1=[行1] 页2=[行2]独占 页3=[行3]）',
            Canvas::make(
                320,
                550,
                paginateTable(500, paginateRow('行1', 100), paginateRow('行2', 300), paginateRow('行3', 100))
            )->graph(),
            ['pageHeight' => 250, 'truncate' => true],
            ['pages' => 3, 'rowsPerPage' => [['行1'], ['行2'], ['行3']]],
        ],
        [ // 10
            'multi-table-invalid',
            '分页开且顶层 TableLayer ≥ 2 张 → paginate_target_invalid',
            Canvas::make(
                320,
                450,
                paginateTable(200, paginateRow('甲', 100)),
                paginateTable(200, paginateRow('乙', 100))
            )->graph(),
            ['pageHeight' => 250],
            ['errorCode' => 'paginate_target_invalid'],
        ],
        [ // 11
            'no-table-passthrough',
            '顶层表 0 张 + 分页开 = 无内容可分，单页直通',
            Canvas::make(320, 100, TextLayer::make(200, 30)->setText('仅装饰'))->graph(),
            ['pageHeight' => 250],
            ['pages' => 1, 'identity' => true],
        ],
        [ // 12
            'page-geometry-invalid',
            'pageHeight ≤ 0 → page_geometry_invalid（构造期 fail-fast）',
            $standardGraph,
            ['pageHeight' => 0],
            ['errorCode' => 'page_geometry_invalid'],
        ],
    ];
}

/**
 * 导出单个分页用例并自检：authored 结构期望与 PHP 实际行为不符直接失败。
 * expect.pages 记录实际页 graph（锁输出字节面，人审 diff 即双端语义行为 diff）
 */
function exportPaginateCase(array $case): array
{
    [$name, $description, $graph, $options, $authored] = $case;

    try {
        $decoded = Canvas::fromGraph($graph);
        $pages = (CanvasPaginator::fromOptions($options))->paginate($decoded);

        if (isset($authored['errorCode'])) {
            throw new RuntimeException("用例 {$name}：预期报错 {$authored['errorCode']} 但分页成功");
        }
        if (count($pages) !== $authored['pages']) {
            throw new RuntimeException("用例 {$name}：页数 " . count($pages) . " 与预期 {$authored['pages']} 不符");
        }
        if (isset($authored['identity']) && $authored['identity']) {
            if (count($pages) !== 1 || $pages[0]->graph() !== $graph) {
                throw new RuntimeException("用例 {$name}：直通页 graph 与输入字节不等价");
            }
        }
        if (isset($authored['rowsPerPage'])) {
            foreach ($authored['rowsPerPage'] as $pageIndex => $expectedTexts) {
                $actualTexts = paginateRowTexts($pages[$pageIndex]);
                if ($actualTexts !== $expectedTexts) {
                    throw new RuntimeException(
                        "用例 {$name}：页 {$pageIndex} 行归属「" . implode(',', $actualTexts)
                        . '」与预期「' . implode(',', $expectedTexts) . '」不符'
                    );
                }
            }
        }
        $expect = ['pages' => array_map(static fn (Canvas $page) => $page->graph(), $pages)];
    } catch (CanvasNextExceptionInterface $e) {
        if (!isset($authored['errorCode']) || $authored['errorCode'] !== $e->getErrorCode()) {
            throw new RuntimeException("用例 {$name}：错误码 {$e->getErrorCode()} 与预期不符");
        }
        $expect = ['errorCode' => $e->getErrorCode()];
    }

    return [
        'name' => $name,
        'description' => $description,
        'graph' => $graph,
        'options' => $options,
        'expect' => $expect,
    ];
}

// ---------------------------------------------------------------------------
// 输出
// ---------------------------------------------------------------------------

$outDir = $argv[1] ?? __DIR__ . '/../../go-canvas/paginate/testdata';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "输出目录不可创建: {$outDir}\n");
    exit(1);
}

$document = [
    'meta' => [
        'contract' => 'paginate-semantics v1',
        'generator' => 'php-canvas-next/scripts/export-paginate-fixtures.php',
        'notes' => [
            '分页（paginate）语义用例 = spec §4.2.7 十二规定用例集（工票 05 钉稿语义/工票 06 导出），语义源 php-canvas-next tests/Compiler/CanvasPaginatorTest.php 逐用例平移；',
            'runner 管线 = graph → 反序列化重建 → paginate(pageHeight, truncate?) → list<页 graph>，与 PHP 导出管线同构（Go: canvas.FromGraph → paginate），Go 镜像按同名用例对齐（Go 实现另 effort）；',
            '输入不变式：graph 一律为 hydrate 后产物（无标记、无模板、高度定稿的具体树），分页不重演填充；',
            'options = {pageHeight: int, truncate?: bool}：页宽恒 = 源画布宽、全页等高、末页留白不收缩；缺 pageHeight = 分页关（单元素直通 + 溢出判定，页底 = 源画布高）；',
            'expect = {pages: [页 graph…]} | {errorCode}；paginate 段 code 全量 = content_overflow / paginate_target_invalid / page_geometry_invalid（spec §5.2），三端一致性锚点；',
            '再生成: go-canvas 仓 make paginate-fixtures（需本机 PHP 8.3）',
        ],
    ],
    'cases' => array_map('exportPaginateCase', paginateCases()),
];

$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "paginate-semantics.json 序列化失败: " . json_last_error_msg() . "\n");
    exit(1);
}
file_put_contents($outDir . '/paginate-semantics.json', $json . "\n");
printf("已导出 %d 个用例 → %s/paginate-semantics.json\n", count($document['cases']), $outDir);
