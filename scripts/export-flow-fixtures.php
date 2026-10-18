<?php

declare(strict_types=1);

/**
 * flow 段三端共享 fixture 导出（spec §10.5，工票 09 钉稿/工票 11 导出）。
 *
 * flow-semantics.json（contract: flow-semantics v1.1）
 * 内容流语义用例（20 规定用例集 + 软诊断用例）：{name, description, frames, dataset, flowChain, expect}
 * 管线 = 模板态 frames graph → Canvas::fromGraph 重建 → DocumentCompiler::compile(frames, dataset, flowChain)
 *        → 帧序×页序 canvas graph 列表 | 单 errorCode
 * expect = {canvases: [canvas graph…], warnings?: [{code, path}]} | {errorCode}
 *
 * 输入不变式：frames 一律为模板态声明 graph（含 data.rowsPath + template）——与 paginate 段
 * 「输入为 hydrate 产物」不同，文档管线自声明态起跑，hydrate 在管线内（spec §10.3 时序）。
 *
 * 用例语义源 = php-canvas-next 单测（tests/Compiler/DocumentCompilerFlowTest.php）逐用例平移；
 * 导出即自检：authored 结构期望（页数、行归属、聚合文案、页几何）与 PHP 实际行为不符直接失败，
 * 防 fixture 与实现漂移。Go 镜像按同名用例对齐（Go paginate/document 实现可滞后，fixture 先钉）。
 *
 * 用法: php scripts/export-flow-fixtures.php [输出目录]
 * 默认输出到 ../go-canvas/paginate/testdata/（与 paginate-semantics.json 同目录，JSON 提交进
 * Go 仓库，经 go-canvas 的 make flow-fixtures 人工触发再生成；Go CI 不装 PHP）。
 */

require __DIR__ . '/../vendor/autoload.php';

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\Diagnostic;
use HankChen\CanvasNext\Compiler\DocumentCompiler;
use HankChen\CanvasNext\Exception\CanvasNextExceptionInterface;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TableRowTemplate;
use HankChen\CanvasNext\Layer\TextLayer;

// ---------------------------------------------------------------------------
// 输入构造 helpers（一律模板态声明 graph：data.rowsPath + template 在场）
// ---------------------------------------------------------------------------

/** 模板表：auto 行 + auto 格 + auto 文案（行高定稿 = 字号，lineHeight 默认 1、无 padding） */
function flowTemplateTable(string $rowsPath = 'order.items', int $fontSize = 20, int $y = 50): TableLayer
{
    $row = (new TableRowTemplate())->setHeight('auto');
    $cell = (new TableCellLayer())->setWidth(320)->setHeight('auto');
    $cell->addTemplateContentLayer(
        TextLayer::make(320, 'auto')->setExpression('{{$index}}.{{row.name}}')->setFont('', $fontSize, '#000')
    );
    $row->addCell($cell);

    $table = TableLayer::make(320, 400, '#fff');
    $table->setPosition(0, $y);
    $table->setRowsPath($rowsPath);
    $table->setTemplate($row);

    return $table;
}

/** V1 固定行表（非模板态）：链校验负例用 */
function flowFixedRowTable(string $rowText = '固定行', int $height = 60, int $y = 50): TableLayer
{
    $table = TableLayer::make(320, 2 * $height, '#fff');
    $table->setPosition(0, $y);
    foreach ([$rowText . '1', $rowText . '2'] as $text) {
        $cell = TableCellLayer::make(320, $height);
        $cell->addContentLayer(TextLayer::make(320, $height)->setText($text));
        $table->addRow(TableRowLayer::make(320, $height)->addCell($cell));
    }

    return $table;
}

/** 帧头聚合文案（根键绑定）：跨帧聚合处处正确的观测点 */
function flowHeader(string $expression = '共 {{itemsTotal}} 条'): TextLayer
{
    return TextLayer::make(200, 30)->setExpression($expression)->setFont('', 12, '#000');
}

/** 软诊断用例模板表：单 auto 文本格、格内容整串求值（缺字段即整串空 → 兜底 + 软诊断） */
function flowFallbackTable(string $rowsPath = 'order.items', int $y = 50): TableLayer
{
    $row = (new TableRowTemplate())->setHeight('auto');
    $cell = (new TableCellLayer())->setWidth(320)->setHeight('auto');
    $cell->addTemplateContentLayer(
        TextLayer::make(320, 'auto')->setExpression('{{row.name}}')->setFont('', 20, '#000')
    );
    $row->addCell($cell);

    $table = TableLayer::make(320, 400, '#fff');
    $table->setPosition(0, $y);
    $table->setRowsPath($rowsPath);
    $table->setTemplate($row);

    return $table;
}

/** name 行序列：['name' => '行1'] … */
function flowNameRows(int $count, string $prefix = '行'): array
{
    return array_map(static fn (int $i) => ['name' => $prefix . $i], range(1, $count));
}

/** 数据集：rows 挂到 $rowsPath 点路径下，$extra 为根级附加键 */
function flowDataset(array $rows, string $rowsPath = 'order.items', array $extra = []): array
{
    $node = $rows;
    foreach (array_reverse(explode('.', $rowsPath)) as $segment) {
        $node = [$segment => $node];
    }

    return array_merge($extra, $node);
}

/** 画布上各表行文案序列（行归属自检用） */
function flowRowTexts(Canvas $canvas): array
{
    $texts = [];
    foreach ($canvas->getLayers() as $layer) {
        if (!$layer instanceof TableLayer) {
            continue;
        }
        foreach ($layer->getRows() as $row) {
            $content = $row->getCells()[0]->getContentLayer();
            $texts[] = $content instanceof TextLayer ? $content->getText() : '';
        }
    }

    return $texts;
}

/** 画布顶层文案层文本序列（聚合文案自检用） */
function flowLayerTexts(Canvas $canvas): array
{
    $texts = [];
    foreach ($canvas->getLayers() as $layer) {
        if ($layer instanceof TextLayer) {
            $texts[] = $layer->getText();
        }
    }

    return $texts;
}

/**
 * 规定用例集（spec §10.5 全表 20 例，工票 11 验收 = 覆盖全表）。
 * authored：errorCode = 预期报错；否则 canvases = 产物页数、rowsPerPage = 每产物行文案、
 * headerTexts = 每产物顶层文案、heights = 每产物画布高
 */
function flowCases(): array
{
    $indexTexts = static fn (int $from, int $to): array => array_map(
        static fn (int $i) => $i . '.行' . $i,
        range($from, $to)
    );

    return [
        // ------------------------------------------------ 正例 1–9
        [ // 1
            'capacity-split-by-row-height',
            '容量切分：行高 auto 定稿 30px、可用区 450−50=400 → 首帧装 13 行（切分点是装箱产物），'
            . '剩余 7 行归 paged 帧一页',
            fn () => [Canvas::make(320, 450, flowTemplateTable(fontSize: 30)), Canvas::make(320, 450, flowTemplateTable(fontSize: 30))],
            flowDataset(flowNameRows(20)),
            [['frame' => 0, 'mode' => 'fixed'], ['frame' => 1, 'mode' => 'paged']],
            ['canvases' => 2, 'rowsPerPage' => [$indexTexts(1, 13), $indexTexts(14, 20)], 'heights' => [450, 450]],
        ],
        [ // 2
            'quota-explicit-split',
            'fixed+quota 显式切分：quota 7 与容量（20 行）无关，退化为声明式切分；剩余 3 行归 paged 帧',
            fn () => [Canvas::make(320, 450, flowTemplateTable()), Canvas::make(320, 450, flowTemplateTable())],
            flowDataset(flowNameRows(10)),
            [['frame' => 0, 'mode' => 'fixed', 'quota' => 7], ['frame' => 1, 'mode' => 'paged']],
            ['canvases' => 2, 'rowsPerPage' => [$indexTexts(1, 7), $indexTexts(8, 10)], 'heights' => [450, 450]],
        ],
        [ // 3
            'quota-zero-declares-no-intake',
            'quota:0 显式不吃行 → 首帧 1 页零行空壳，剩余 3 行全归 paged 帧',
            fn () => [Canvas::make(320, 450, flowTemplateTable()), Canvas::make(320, 450, flowTemplateTable())],
            flowDataset(flowNameRows(3)),
            [['frame' => 0, 'mode' => 'fixed', 'quota' => 0], ['frame' => 1, 'mode' => 'paged']],
            ['canvases' => 2, 'rowsPerPage' => [[], $indexTexts(1, 3)], 'heights' => [450, 450]],
        ],
        [ // 4
            'omit-if-empty-skips-frame',
            'omitIfEmpty 触发：分得零行的帧跳帧——不产页、不进产物序列',
            fn () => [Canvas::make(320, 450, flowTemplateTable()), Canvas::make(320, 450, flowTemplateTable())],
            flowDataset(flowNameRows(3)),
            [['frame' => 0, 'mode' => 'fixed', 'quota' => 0, 'omitIfEmpty' => true], ['frame' => 1, 'mode' => 'paged']],
            ['canvases' => 1, 'rowsPerPage' => [$indexTexts(1, 3)], 'heights' => [450]],
        ],
        [ // 5
            'paged-terminal-multi-page',
            'paged 链尾：页高 = 帧画布高（120）、帧内多页出图。首帧 quota 4，剩 7 行按可用区 120−20=100 装箱 [5][2]',
            fn () => [Canvas::make(320, 450, flowTemplateTable()), Canvas::make(320, 120, flowTemplateTable(y: 20))],
            flowDataset(flowNameRows(11)),
            [['frame' => 0, 'mode' => 'fixed', 'quota' => 4], ['frame' => 1, 'mode' => 'paged']],
            ['canvases' => 3, 'rowsPerPage' => [$indexTexts(1, 4), $indexTexts(5, 9), $indexTexts(10, 11)], 'heights' => [450, 120, 120]],
        ],
        [ // 6
            'global-index-across-frames',
            '跨帧 $index 全局连续：全量 hydrate 在前、分配在后，2+2+1 切分后行文案序号不逐帧重计',
            fn () => [
                Canvas::make(320, 450, flowTemplateTable()),
                Canvas::make(320, 450, flowTemplateTable()),
                Canvas::make(320, 450, flowTemplateTable()),
            ],
            flowDataset(flowNameRows(5)),
            [['frame' => 0, 'mode' => 'fixed', 'quota' => 2], ['frame' => 1, 'mode' => 'fixed', 'quota' => 2], ['frame' => 2, 'mode' => 'paged']],
            ['canvases' => 3, 'rowsPerPage' => [['1.行1', '2.行2'], ['3.行3', '4.行4'], ['5.行5']], 'heights' => [450, 450, 450]],
        ],
        [ // 7
            'cross-frame-aggregation',
            '跨帧聚合：根键绑定「共 n 条」在每帧都取到全数据集聚合值，不因行被切走而失真',
            fn () => [Canvas::make(320, 450, flowHeader(), flowTemplateTable()), Canvas::make(320, 450, flowHeader(), flowTemplateTable())],
            flowDataset(flowNameRows(5), extra: ['itemsTotal' => 5]),
            [['frame' => 0, 'mode' => 'fixed', 'quota' => 2], ['frame' => 1, 'mode' => 'paged']],
            [
                'canvases' => 2,
                'rowsPerPage' => [$indexTexts(1, 2), $indexTexts(3, 5)],
                'headerTexts' => [['共 5 条'], ['共 5 条']],
                'heights' => [450, 450],
            ],
        ],
        [ // 8
            'non-chain-frames-coexist',
            '链外共存：无表帧 + 异 rowsPath 模板帧 + V1 表帧与链帧同文档编译，产物按帧序扁平拼接；'
            . '链外模板帧吃自己的 rowsPath、V1 表帧行原样保留',
            fn () => [
                Canvas::make(320, 100, TextLayer::make(200, 30)->setText('封面')),
                Canvas::make(320, 450, flowTemplateTable('order.items')),
                Canvas::make(320, 450, flowTemplateTable('gift.items')),
                Canvas::make(320, 450, flowFixedRowTable()),
            ],
            flowDataset(flowNameRows(3), extra: ['gift' => ['items' => flowNameRows(2, '赠')]]),
            [['frame' => 1, 'mode' => 'paged']],
            [
                'canvases' => 4,
                'rowsPerPage' => [[], $indexTexts(1, 3), ['1.赠1', '2.赠2'], ['固定行1', '固定行2']],
                'heights' => [100, 450, 450, 450],
            ],
        ],
        [ // 9
            'empty-dataset-matrix',
            '空数据集矩阵（spec §10.3 第 5 条）：全链 0 行 → 每链帧各产 1 页零行空壳，omitIfEmpty 逐节点可关（帧 2 跳帧）',
            fn () => [
                Canvas::make(320, 450, flowTemplateTable()),
                Canvas::make(320, 450, flowTemplateTable()),
                Canvas::make(320, 450, flowTemplateTable()),
            ],
            flowDataset([]),
            [['frame' => 0, 'mode' => 'fixed'], ['frame' => 1, 'mode' => 'fixed', 'quota' => 2], ['frame' => 2, 'mode' => 'paged', 'omitIfEmpty' => true]],
            ['canvases' => 2, 'rowsPerPage' => [[], []], 'heights' => [450, 450]],
        ],
        // ------------------------------------------------ 负例 10–20
        [ // 10
            'chain-frame-out-of-range',
            'frame 越界（2 帧链上 frame:2）→ flow_chain_invalid',
            fn () => [Canvas::make(320, 450, flowTemplateTable()), Canvas::make(320, 450, flowTemplateTable())],
            flowDataset(flowNameRows(3)),
            [['frame' => 2, 'mode' => 'paged']],
            ['errorCode' => 'flow_chain_invalid'],
        ],
        [ // 11
            'chain-frame-duplicated',
            'frame 重复 → flow_chain_invalid',
            fn () => [Canvas::make(320, 450, flowTemplateTable()), Canvas::make(320, 450, flowTemplateTable())],
            flowDataset(flowNameRows(3)),
            [['frame' => 0, 'mode' => 'fixed'], ['frame' => 0, 'mode' => 'paged']],
            ['errorCode' => 'flow_chain_invalid'],
        ],
        [ // 12
            'paged-not-last',
            'paged 非末位 → flow_chain_invalid（paged 是链尾终止符，至多一个）',
            fn () => [Canvas::make(320, 450, flowTemplateTable()), Canvas::make(320, 450, flowTemplateTable())],
            flowDataset(flowNameRows(3)),
            [['frame' => 0, 'mode' => 'paged'], ['frame' => 1, 'mode' => 'fixed']],
            ['errorCode' => 'flow_chain_invalid'],
        ],
        [ // 13
            'paged-with-quota',
            'paged 带 quota → flow_chain_invalid（paged 吃尽剩余，无配额位）',
            fn () => [Canvas::make(320, 450, flowTemplateTable())],
            flowDataset(flowNameRows(3)),
            [['frame' => 0, 'mode' => 'paged', 'quota' => 2]],
            ['errorCode' => 'flow_chain_invalid'],
        ],
        [ // 14
            'unknown-key-rejected',
            '未知键拒绝（封闭数据通道，CompileContext.options 同款）→ flow_chain_invalid',
            fn () => [Canvas::make(320, 450, flowTemplateTable())],
            flowDataset(flowNameRows(3)),
            [['frame' => 0, 'mode' => 'fixed', 'pageHeight' => 100]],
            ['errorCode' => 'flow_chain_invalid'],
        ],
        [ // 15
            'chain-frame-without-template-table',
            '链上帧无模板表：V1 表非模板态 → flow_chain_invalid',
            fn () => [Canvas::make(320, 450, flowFixedRowTable())],
            flowDataset(flowNameRows(3)),
            [['frame' => 0, 'mode' => 'paged']],
            ['errorCode' => 'flow_chain_invalid'],
        ],
        [ // 16
            'chain-order-violated',
            '链序乱序回跳（frame 1 → 0）→ flow_chain_invalid',
            fn () => [Canvas::make(320, 450, flowTemplateTable()), Canvas::make(320, 450, flowTemplateTable())],
            flowDataset(flowNameRows(3)),
            [['frame' => 1, 'mode' => 'fixed'], ['frame' => 0, 'mode' => 'paged']],
            ['errorCode' => 'flow_chain_invalid'],
        ],
        [ // 17
            'rows-path-mismatch-in-chain',
            '链内 rowsPath 不一致 → flow_rows_path_inconsistent',
            fn () => [Canvas::make(320, 450, flowTemplateTable('order.items')), Canvas::make(320, 450, flowTemplateTable('gift.items'))],
            flowDataset(flowNameRows(3)),
            [['frame' => 0, 'mode' => 'fixed'], ['frame' => 1, 'mode' => 'paged']],
            ['errorCode' => 'flow_rows_path_inconsistent'],
        ],
        [ // 18
            'rows-path-collides-off-chain',
            '链外同 rowsPath：同 rowsPath 的表游离链外 = 分配权威被架空（幽灵行）→ flow_rows_path_inconsistent',
            fn () => [Canvas::make(320, 450, flowTemplateTable('order.items')), Canvas::make(320, 450, flowTemplateTable('order.items'))],
            flowDataset(flowNameRows(3)),
            [['frame' => 0, 'mode' => 'paged']],
            ['errorCode' => 'flow_rows_path_inconsistent'],
        ],
        [ // 19
            'multi-table-invalid',
            '任意帧 ≥2 顶层表 → 既有 paginate_target_invalid 原样抛（分页段 PaginateException，不换 code 不换段）',
            fn () => [Canvas::make(320, 450, flowTemplateTable(), flowFixedRowTable())],
            flowDataset(flowNameRows(3)),
            [['frame' => 0, 'mode' => 'paged']],
            ['errorCode' => 'paginate_target_invalid'],
        ],
        [ // 20
            'first-row-oversize-throws',
            '容量型首行超容（单行高 30 > 可用区 200−180=20）→ 分配期 fail-fast 抛 paginate 段 content_overflow',
            fn () => [Canvas::make(320, 200, flowTemplateTable(fontSize: 30, y: 180))],
            flowDataset(flowNameRows(2)),
            [['frame' => 0, 'mode' => 'fixed']],
            ['errorCode' => 'content_overflow'],
        ],
        // ------------------------------------------------ 软诊断（flow v1.1）
        [ // 21
            'text-fallback-warnings-full-hydrate',
            '流路径软诊断面（工票 05）：hydrate 在管线内、warnings 反映分配前的全量 hydrate——'
            . '每帧模板表吃全量 3 行实例，两帧各 3 条按帧序累积、path 不带帧标识；'
            . '行分配（2+1）与诊断序列无关',
            fn () => [
                Canvas::make(320, 450, flowFallbackTable()),
                Canvas::make(320, 450, flowFallbackTable()),
            ],
            flowDataset([['filler' => 0], ['filler' => 1], ['filler' => 2]]),
            [['frame' => 0, 'mode' => 'fixed', 'quota' => 2], ['frame' => 1, 'mode' => 'paged']],
            [
                'canvases' => 2,
                'rowsPerPage' => [['', ''], ['']],
                'heights' => [450, 450],
                'warnings' => array_map(
                    static fn (int $row) => ['code' => 'expression_fallback_empty', 'path' => "layers[0].rows[{$row}].cells[0].content.data.value"],
                    [0, 1, 2, 0, 1, 2]
                ),
            ],
        ],
    ];
}

/**
 * 导出单个 flow 用例并自检：authored 结构期望与 PHP 实际行为不符直接失败。
 * expect.canvases 记录实际产物 graph（锁输出字节面，人审 diff 即双端语义行为 diff）；
 * expect.warnings = 软诊断期望序列（工票 05，断言面只落 code + path、空序列键省略）
 */
function exportFlowCase(array $case): array
{
    [$name, $description, $framesBuilder, $dataset, $chain, $authored] = $case;

    /** @var list<Canvas> $frames 模板态声明帧 */
    $frames = $framesBuilder();
    $frameGraphs = array_map(static fn (Canvas $frame) => $frame->graph(), $frames);

    try {
        $decoded = array_map(static fn (array $graph) => Canvas::fromGraph($graph), $frameGraphs);
        $result = (new DocumentCompiler())->compile($decoded, $dataset, $chain);
        $canvases = $result->canvases;

        if (isset($authored['errorCode'])) {
            throw new RuntimeException("用例 {$name}：预期报错 {$authored['errorCode']} 但编译成功");
        }
        if (count($canvases) !== $authored['canvases']) {
            throw new RuntimeException("用例 {$name}：产物数 " . count($canvases) . " 与预期 {$authored['canvases']} 不符");
        }
        foreach ($authored['rowsPerPage'] as $pageIndex => $expectedTexts) {
            $actualTexts = flowRowTexts($canvases[$pageIndex]);
            if ($actualTexts !== $expectedTexts) {
                throw new RuntimeException(
                    "用例 {$name}：产物 {$pageIndex} 行归属「" . implode(',', $actualTexts)
                    . '」与预期「' . implode(',', $expectedTexts) . '」不符'
                );
            }
        }
        foreach ($authored['headerTexts'] ?? [] as $pageIndex => $expectedTexts) {
            if (flowLayerTexts($canvases[$pageIndex]) !== $expectedTexts) {
                throw new RuntimeException("用例 {$name}：产物 {$pageIndex} 顶层文案与预期不符");
            }
        }
        foreach ($authored['heights'] ?? [] as $pageIndex => $expectedHeight) {
            if ($canvases[$pageIndex]->getHeight() !== $expectedHeight) {
                throw new RuntimeException("用例 {$name}：产物 {$pageIndex} 页高与预期 {$expectedHeight} 不符");
            }
        }
        foreach ($authored['warnings'] ?? [] as $i => $expectedWarning) {
            $actual = $result->warnings[$i] ?? null;
            if (!$actual instanceof Diagnostic
                || $actual->code !== $expectedWarning['code']
                || ($actual->path ?? '') !== $expectedWarning['path']) {
                throw new RuntimeException(
                    "用例 {$name}：诊断[{$i}]「" . ($actual instanceof Diagnostic ? "{$actual->code} @ {$actual->path}" : '缺席')
                    . '」与预期「' . $expectedWarning['code'] . ' @ ' . $expectedWarning['path'] . '」不符'
                );
            }
        }
        if (isset($authored['warnings']) && count($result->warnings) !== count($authored['warnings'])) {
            throw new RuntimeException("用例 {$name}：诊断数 " . count($result->warnings) . ' 与预期 ' . count($authored['warnings']) . ' 不符');
        }
        $expect = ['canvases' => array_map(static fn (Canvas $canvas) => $canvas->graph(), $canvases)];
        $exportedWarnings = array_map(
            static fn (Diagnostic $d) => array_filter(
                ['code' => $d->code, 'path' => $d->path],
                static fn ($v) => $v !== null
            ),
            $result->warnings
        );
        if ($exportedWarnings !== []) {
            $expect['warnings'] = $exportedWarnings;
        }
    } catch (CanvasNextExceptionInterface $e) {
        if (!isset($authored['errorCode']) || $authored['errorCode'] !== $e->getErrorCode()) {
            throw new RuntimeException("用例 {$name}：错误码 {$e->getErrorCode()} 与预期不符");
        }
        $expect = ['errorCode' => $e->getErrorCode()];
    }

    return [
        'name' => $name,
        'description' => $description,
        'frames' => $frameGraphs,
        'dataset' => $dataset,
        'flowChain' => $chain,
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
        'contract' => 'flow-semantics v1.1',
        'generator' => 'php-canvas-next/scripts/export-flow-fixtures.php',
        'notes' => [
            '内容流（flow）语义用例 = spec §10.5 规定用例集 20 例（工票 09 钉稿语义/工票 11 导出）+ 软诊断用例 1 例（工票 05），语义源 php-canvas-next tests/Compiler/DocumentCompilerFlowTest.php 逐用例平移；',
            'v1.1 = 软诊断发射缝语料面（工票 05，纯增用例）：expect 增可选 warnings 键 = 期望诊断序列 [{code, path}]'
            . '（消息人读不入断言面，path 为产物树中立记法 spec/pipeline.md §5；省略 = 空序列），'
            . '现役发射面唯一 = hydrate 文案类表达式兜底空串 expression_fallback_empty，'
            . '流路径 warnings 反映分配前的全量 hydrate、按帧序累积、不带帧标识；',
            'runner 管线 = 模板态 frames graph → 反序列化重建 → DocumentCompiler::compile(frames, dataset, flowChain) → 帧序×页序 canvas graph 列表，与 PHP 导出管线同构（Go 镜像按同名用例对齐）；',
            '输入不变式：frames 一律为模板态声明 graph（含 data.rowsPath + template），文档管线自声明态起跑、hydrate 在管线内（与 paginate 段「输入为 hydrate 产物」不同）；',
            'flowChain 节点 = {frame: int, mode: "fixed"|"paged", quota?: int≥0, omitIfEmpty?: bool}，封闭键面（未知键拒绝）；显式 [] 与 null 同义；',
            'expect = {canvases: [canvas graph…], warnings?} | {errorCode}；document 段 code 全量 = flow_chain_invalid / flow_rows_path_inconsistent（spec §10.4）；分配期超容复用 paginate 段 content_overflow、任意帧 ≥2 顶层表复用 paginate_target_invalid——code 是三端一致性锚点；',
            '再生成: go-canvas 仓 make flow-fixtures（需本机 PHP 8.3）',
        ],
    ],
    'cases' => array_map('exportFlowCase', flowCases()),
];

$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "flow-semantics.json 序列化失败: " . json_last_error_msg() . "\n");
    exit(1);
}
file_put_contents($outDir . '/flow-semantics.json', $json . "\n");
printf("已导出 %d 个用例 → %s/flow-semantics.json\n", count($document['cases']), $outDir);
