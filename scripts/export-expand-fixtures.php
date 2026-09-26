<?php

declare(strict_types=1);

/**
 * TableLayer V2 三端共享 fixture 导出（spec §7 / go-canvas 工票 12）。
 * 两个一致性面各一份 JSON，Go 侧 expand 包测试为 runner，canvas-web 适配后可复用：
 *
 * - expression-eval.json（contract: expression-eval v1）
 *   默认求值器（受限插值）语义用例：{name, description, template, context, expect}
 *   expect = 求值结果串 | {errorCode}（spec §5.2 稳定 code，三端一致性抓手）
 *
 * - expand-semantics.json（contract: expand-semantics v1）
 *   展开步骤语义用例：{name, description, graph, dataset, expect}
 *   管线 = graph → Canvas::fromGraph → CanvasExpander::expand → graph
 *   （解码期错误 template_rows_conflict / rows_path_missing 同管线可表达）；
 *   expect = {graph: 展开后 graph（含 V1 高度耦合重放结果）} | {errorCode}
 *
 * 用例语义源 = php-canvas-next 单测（tests/Expression + tests/Expand）逐用例平移；
 * 导出即自检：PHP 实际行为与 authored 期望不符直接失败，防 fixture 与实现漂移。
 *
 * 用法: php scripts/export-expand-fixtures.php [输出目录]
 * 默认输出到 ../go-canvas/expand/testdata/（JSON 提交进 Go 仓库，
 * 经 go-canvas 的 make expand-fixtures 人工触发再生成；Go CI 不装 PHP）。
 */

require __DIR__ . '/../vendor/autoload.php';

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Exception\CanvasNextExceptionInterface;
use HankChen\CanvasNext\Expand\CanvasExpander;
use HankChen\CanvasNext\Expression\InterpolationEvaluator;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TableRowTemplate;
use HankChen\CanvasNext\Layer\TextLayer;

// ---------------------------------------------------------------------------
// expression-eval v1
// ---------------------------------------------------------------------------

/** 行上下文：行命名空间 + 全局根键混合（语义源 InterpolationEvaluatorTest::rowContext） */
function fixtureRowContext(): array
{
    return [
        'row' => ['name' => '张三', 'user' => ['city' => '杭州'], 'score' => 98.5],
        '$index' => 3,
        'orderNo' => 'A2026-001',
    ];
}

/** 独立图层上下文 = 全局数据集根（语义源 ::rootContext） */
function fixtureRootContext(): array
{
    return ['orderNo' => 'A2026-001', 'nested' => ['k' => 'v']];
}

/**
 * 表达式求值用例集。[name, description, template, context, expect]
 * expect 为串 = 求值成功预期；为 ['errorCode' => ...] = 预期报错
 */
function expressionCases(): array
{
    $row = fixtureRowContext();
    $root = fixtureRootContext();

    return [
        ['row-field-and-literal-mix', '行字段与字面混排 + $index 插值',
            '姓名：{{row.name}}（{{$index}}）', $row, '姓名：张三（3）'],
        ['multiple-fragments-concatenate', '多片段拼接（根键 + 行字段）',
            '{{orderNo}}-{{row.name}}', $row, 'A2026-001-张三'],
        ['bare-name-reads-root-key', '裸名只读全局根键（命名空间分离，无遮蔽）',
            '{{orderNo}}', $row, 'A2026-001'],
        ['nested-dot-path-row', '行字段嵌套点路径下钻',
            '{{row.user.city}}', $row, '杭州'],
        ['nested-dot-path-root', '根键嵌套点路径下钻',
            '{{nested.k}}', $root, 'v'],
        ['root-escape-equals-bare-name', '$root 显式取根与裸名等价',
            '{{$root.orderNo}}', $row, 'A2026-001'],
        ['whitespace-inside-braces-tolerated', '花括号内空白容忍',
            '{{ row.name }}', $row, '张三'],
        ['numeric-float-stringified', '浮点字符串化（常规区间）',
            '{{row.score}}', $row, '98.5'],
        ['index-stringified', '$index 整数字符串化',
            '{{$index}}', $row, '3'],
        ['fragmentless-marked-string-literal', '不含 {{ 的标记串 = 整串字面（合法无意义）',
            '纯文本', $row, '纯文本'],
        ['unclosed-brace-stays-literal', '未闭合 {{ 剩余全字面（含空白片段起点）',
            '价格 {{ 未知', $row, '价格 {{ 未知'],
        ['unclosed-brace-no-close-stays-literal', '未闭合 {{ 只有开标记时全字面',
            '价格 {{row.name', $row, '价格 {{row.name'],
        ['missing-row-field-empty-signal', '行缺字段 = 空串信号（字段类策略由展开器施加）',
            '姓名：{{row.nickname}}', $row, '姓名：'],
        ['missing-root-field-empty-signal', '根缺字段 = 空串信号',
            '{{nickname}}', ['other' => 'x'], ''],
        ['null-mid-path-empty-signal', '中途 null = 空串信号',
            '{{row.a}}', ['row' => ['a' => null, 'b' => ['c' => null]], 'orderNo' => 'x'], ''],
        ['null-terminal-empty-signal', '末端 null = 空串信号',
            '{{row.b.c}}', ['row' => ['a' => null, 'b' => ['c' => null]], 'orderNo' => 'x'], ''],
        ['type-mismatch-array-at-scalar', '数组落到标量位 = 报错',
            '{{row.user}}', $row, ['errorCode' => 'expression_type_mismatch']],
        ['type-mismatch-scalar-drill', '标量中途下钻 = 报错',
            '{{row.name.x}}', $row, ['errorCode' => 'expression_type_mismatch']],
        ['row-prefix-outside-row-context', 'row. 前缀出现在非行上下文 = 报错（模板复制到表外）',
            '{{row.name}}', $root, ['errorCode' => 'expression_row_outside_loop']],
        ['empty-fragment-syntax-error', '空表达式片段 = 语法错误',
            '名：{{}}', $row, ['errorCode' => 'expression_syntax_error']],
        ['malformed-path-syntax-error', '路径含空段 = 语法错误',
            '{{row..name}}', $row, ['errorCode' => 'expression_syntax_error']],
    ];
}

/** 导出单个求值用例并自检：PHP 实际行为与 authored 期望不符直接失败 */
function exportExpressionCase(array $case): array
{
    [$name, $description, $template, $context, $expect] = $case;

    try {
        $result = (new InterpolationEvaluator())->evaluate($template, $context);
        $got = is_array($expect) ? "预期报错 {$expect['errorCode']} 但求值成功（{$result}）" : null;
        if ($got !== null) {
            throw new RuntimeException("用例 {$name}：{$got}");
        }
        if ($result !== $expect) {
            throw new RuntimeException("用例 {$name}：求值结果「{$result}」与预期「{$expect}」不符");
        }
        $expectOut = $expect;
    } catch (CanvasNextExceptionInterface $e) {
        if (!is_array($expect) || $expect['errorCode'] !== $e->getErrorCode()) {
            throw new RuntimeException("用例 {$name}：错误码 {$e->getErrorCode()} 与预期不符");
        }
        $expectOut = ['errorCode' => $e->getErrorCode()];
    }

    return [
        'name' => $name,
        'description' => $description,
        'template' => $template,
        'context' => $context,
        'expect' => $expectOut,
    ];
}

// ---------------------------------------------------------------------------
// expand-semantics v1
// ---------------------------------------------------------------------------

/**
 * 主模板表（语义源 CanvasExpanderTest::templateTable）：
 * auto 文本格（行上下文求值 + $index）+ 固定图片格（资源类求值），rowsPath = order.items
 */
function fixtureTemplateTable(): TableLayer
{
    $row = (new TableRowTemplate())->setHeight('auto');

    $textCell = (new TableCellLayer())->setWidth(160)->setHeight('auto');
    $textCell->addTemplateContentLayer(
        TextLayer::make(160, 'auto')->setExpression('姓名：{{row.name}}（{{$index}}）')->setFont('', 12, '#000')
    );
    $imageCell = (new TableCellLayer())->setWidth(160)->setHeight(40);
    $imageCell->addTemplateContentLayer(ImageLayer::make(160, 'auto')->setExpression('{{row.avatar}}'));

    $row->addCell($textCell)->addCell($imageCell);

    $table = TableLayer::make(320, 200, '#fff');
    $table->setRowsPath('order.items');
    $table->setTemplate($row);

    return $table;
}

/** 主数据集（语义源 ::dataset，含两行） */
function fixtureDataset(): array
{
    return [
        'orderNo' => 'A2026-001',
        'order' => ['items' => [
            ['name' => '张三', 'avatar' => '/tmp/a.png'],
            ['name' => '李四', 'avatar' => '/tmp/b.png'],
        ]],
    ];
}

/** 单文本格模板表 helper：rowsPath 可变，用于取数路径/类型不匹配等聚焦用例 */
function singleTextTemplateTable(string $expression, string $rowsPath): TableLayer
{
    $row = (new TableRowTemplate())->setHeight('auto');
    $cell = (new TableCellLayer())->setWidth(320)->setHeight('auto');
    $cell->addTemplateContentLayer(
        TextLayer::make(320, 'auto')->setExpression($expression)->setFont('', 12, '#000')
    );
    $row->addCell($cell);

    $table = TableLayer::make(320, 200);
    $table->setRowsPath($rowsPath);
    $table->setTemplate($row);

    return $table;
}

/** QR 绑定面用例表：锁 expression 条件写键（求值后键移除） */
function qrTemplateTable(): TableLayer
{
    $row = (new TableRowTemplate())->setHeight(40);
    $cell = (new TableCellLayer())->setWidth(320)->setHeight(40);
    $cell->addTemplateContentLayer(
        (new QrCodeLayer())->setWidth(320)->setHeight(40)->setExpression('{{row.code}}')
    );
    $row->addCell($cell);

    $table = TableLayer::make(320, 200);
    $table->setRowsPath('items');
    $table->setTemplate($row);

    return $table;
}

/** 嵌套模板表：内层 rowsPath 行相对（以当前行数据为取数范围），行字段指向最内层行 */
function nestedTemplateTable(): TableLayer
{
    $innerTemplate = (new TableRowTemplate())->setHeight('auto');
    $innerCell = (new TableCellLayer())->setWidth(200)->setHeight('auto');
    $innerCell->addTemplateContentLayer(
        TextLayer::make(200, 'auto')->setExpression('{{row.name}}@{{$index}}')->setFont('', 12, '#000')
    );
    $innerTemplate->addCell($innerCell);

    $innerTable = TableLayer::make(200, 100);
    $innerTable->setRowsPath('sub');
    $innerTable->setTemplate($innerTemplate);

    $outerTemplate = (new TableRowTemplate())->setHeight('auto');
    $outerCell = (new TableCellLayer())->setWidth(200)->setHeight(100);
    $outerCell->addTemplateContentLayer($innerTable);
    $outerTemplate->addCell($outerCell);

    $outerTable = TableLayer::make(200, 200);
    $outerTable->setRowsPath('items');
    $outerTable->setTemplate($outerTemplate);

    return $outerTable;
}

/** 展开语义用例集。管线 = graph → fromGraph → expand；错误码可 authored 自检 */
function expandCases(): array
{
    // 用例 graph 一律画布级（Canvas::fromGraph 的输入形态，与 layout-snapshot 同构）
    $mainGraph = Canvas::make(320, 400, fixtureTemplateTable())->graph();
    $mainDataset = fixtureDataset();

    // 手写非法 wire（API 构造不出来，spec §2.2 XOR 由解码期拦截）
    $conflictGraph = $mainGraph;
    $conflictGraph['layers'][0]['rows'] = [];
    $missingPathGraph = $mainGraph;
    unset($missingPathGraph['layers'][0]['data']);

    $qrGraph = Canvas::make(320, 240, qrTemplateTable())->graph();
    $nestedGraph = Canvas::make(240, 400, nestedTemplateTable())->graph();

    $v1Table = TableLayer::make(320, 100, '#fff');
    $v1Cell = (new TableCellLayer())->setWidth(320)->setHeight('auto');
    $v1Cell->addContentLayer(
        TextLayer::make(320, 'auto')->setExpression('{{orderNo}}')->setFont('', 12, '#000')
    );
    $v1Table->addRow(TableRowLayer::make(320, 'auto')->addCell($v1Cell));
    $v1Graph = Canvas::make(320, 400, $v1Table)->graph();

    return [
        [$mainGraph, $mainDataset, null,
            'template-table-instantiation',
            '主用例：两行实例化 + 行上下文求值 + $index 自 1 起 + 标记解除（valueType→StaticValue）'
            . ' + V1 高度耦合重放（行高定稿/固定格内容压平）+ template/data 键消失'],
        [$qrGraph, ['items' => [['code' => 'CODE-A'], ['code' => 'CODE-B']]], null,
            'qr-marked-content',
            'QR 绑定面：求值后 expression 键移除（条件写键，对齐未标记两键形态）'],
        [Canvas::make(
            320,
            100,
            TextLayer::make(200, 30)->setExpression('订单号：{{orderNo}}')->setFont('', 12, '#000'),
            ImageLayer::make(60, 60)->setExpression('{{orderNo}}.png'),
            (new QrCodeLayer())->setWidth(60)->setHeight(60)->setExpression('{{orderNo}}-qr')
        )->graph(), $mainDataset, null,
            'independent-layers-root-context',
            '独立图层（非行循环）根上下文求值：全绑定面 Text/Image/Qr'],
        [$v1Graph, $mainDataset, null,
            'v1-table-marked-content-root-scope',
            'V1 表（rows only）标记内容在根作用域求值：行/格/内容同作用域传递'],
        [$mainGraph, null, null,
            'null-dataset-identity',
            'dataset null = 未绑数据集恒等直通（含模板表不展开，渲染按零行空壳呈现）'],
        [Canvas::make(320, 100, TextLayer::make(100, 30)->setText('字面 {{orderNo}} 文案'))->graph(),
            $mainDataset, null,
            'no-bindings-identity',
            '无标记无模板画布恒等直通（存量文案里的字面 {{ 不误伤）'],
        [$mainGraph, ['order' => ['items' => []]], null,
            'empty-row-array-empty-shell',
            '空数组 = 合法零行：rows 键存在且为空数组，template/data 消失（空壳渲染）'],
        [$nestedGraph, ['items' => [
            ['sub' => [['name' => '甲']]],
            ['sub' => [['name' => '乙'], ['name' => '丙']]],
        ]], null,
            'nested-template-table-row-relative',
            '嵌套模板表：内层 rowsPath 行相对（以当前行数据为取数范围），$index 内层自 1 起'],
        [$mainGraph, ['orderNo' => 'A2026-001'], 'rows_path_invalid',
            'rows-path-missing-key',
            'rowsPath 指向缺键 = 报错（结构性错误 fail-fast）'],
        [$mainGraph, ['order' => ['items' => 'not-an-array']], 'rows_path_invalid',
            'rows-path-non-array',
            'rowsPath 指向非数组 = 报错'],
        [Canvas::make(320, 100, TextLayer::make(100, 30)->setExpression('{{orderNo}}')->setFont('', 12, '#000'))->graph(),
            ['row' => ['name' => 'x']], 'reserved_root_key',
            'reserved-root-key-row',
            '数据集根级键名 row = 报错（{{row.x}} 的 row 段歧义）'],
        [Canvas::make(320, 100, TextLayer::make(100, 30)->setExpression('{{orderNo}}')->setFont('', 12, '#000'))->graph(),
            ['$meta' => 1, 'orderNo' => 'A'], 'reserved_root_key',
            'reserved-root-key-dollar',
            '数据集根级 $ 前缀键名 = 报错'],
        [$mainGraph, ['order' => ['items' => [['name' => '张三']]]], 'expression_empty_resource',
            'empty-resource-expression',
            '资源类字段（Image src）求值空串 = 报错（空 URL 无意义，fail-fast 优于错误后移到物化）'],
        [Canvas::make(320, 400, singleTextTemplateTable('{{row.tags}}', 'items'))->graph(),
            ['items' => [['tags' => ['a', 'b']]]], 'expression_type_mismatch',
            'type-mismatch-in-row-context',
            '行上下文中数组落到标量位 = 报错'],
        [$conflictGraph, $mainDataset, 'template_rows_conflict',
            'decode-template-rows-conflict',
            '解码期：template 与 rows 键同现 = 非法 wire（手写，API 构造不出来）'],
        [$missingPathGraph, $mainDataset, 'rows_path_missing',
            'decode-rows-path-missing',
            '解码期：模板态缺 data.rowsPath = 非法 wire（先紧后松，补默认值是非破坏性变更）'],
    ];
}

/** 导出单个展开用例：管线 = graph → fromGraph → expand，authored 错误码自检 */
function exportExpandCase(array $case): array
{
    [$graph, $dataset, $expectedErrorCode, $name, $description] = $case;

    try {
        $decoded = Canvas::fromGraph($graph);
        $expanded = (new CanvasExpander())->expand($decoded, $dataset);
        if ($expectedErrorCode !== null) {
            throw new RuntimeException("用例 {$name}：预期报错 {$expectedErrorCode} 但展开成功");
        }
        $expect = ['graph' => $expanded->graph()];
    } catch (CanvasNextExceptionInterface $e) {
        if ($expectedErrorCode !== null && $e->getErrorCode() !== $expectedErrorCode) {
            throw new RuntimeException("用例 {$name}：错误码 {$e->getErrorCode()} 与预期 {$expectedErrorCode} 不符");
        }
        $expect = ['errorCode' => $e->getErrorCode()];
    }

    return [
        'name' => $name,
        'description' => $description,
        'graph' => $graph,
        'dataset' => $dataset,
        'expect' => $expect,
    ];
}

// ---------------------------------------------------------------------------
// 输出
// ---------------------------------------------------------------------------

$outDir = $argv[1] ?? __DIR__ . '/../../go-canvas/expand/testdata';
if (!is_dir($outDir) && !mkdir($outDir, 0755, true)) {
    fwrite(STDERR, "输出目录不可创建: {$outDir}\n");
    exit(1);
}

$documents = [
    'expression-eval.json' => [
        'meta' => [
            'contract' => 'expression-eval v1',
            'generator' => 'php-canvas-next/scripts/export-expand-fixtures.php',
            'notes' => [
                '默认求值器（受限插值）语义用例，语义源 php-canvas-next tests/Expression/InterpolationEvaluatorTest.php 逐用例平移；',
                'context 形状为契约层钉定（spec §3.2）：row 键 = 行命名空间、裸名 = 数据集根键、$index（自 1 起）/$root 保留名；',
                '缺字段/null = 空串信号（字段类兜底/报错策略由展开器施加，spec §3.4）；数组/对象落到标量位 = expression_type_mismatch；',
                'expect = 求值结果串 | {errorCode}；code 清单见 spec §5.2，三端一致性抓手；',
                '数值字符串化只锁常规区间（整数/常规小数）；极端浮点（科学计数法阈值）的格式化语义归求值器 spec effort，不入 fixture；',
                '再生成: go-canvas 仓 make expand-fixtures（需本机 PHP 8.3）',
            ],
        ],
        'cases' => array_map('exportExpressionCase', expressionCases()),
    ],
    'expand-semantics.json' => [
        'meta' => [
            'contract' => 'expand-semantics v1',
            'generator' => 'php-canvas-next/scripts/export-expand-fixtures.php',
            'notes' => [
                '展开步骤语义用例，语义源 php-canvas-next tests/Expand/CanvasExpanderTest.php 逐用例平移（另含 QR/嵌套两个扩覆盖用例）；',
                'runner 管线 = graph → 反序列化重建 → expand(dataset) → graph，与 PHP 导出管线同构（Go: canvas.FromGraph → expand）；',
                '解码期错误（template_rows_conflict/rows_path_missing）与展开期错误同管线表达，expect = {graph} | {errorCode}（spec §5.2）；',
                'dataset null = 未绑数据集恒等直通（含模板表）；无标记无模板画布恒等直通；',
                '展开产物 graph 已含 V1 高度耦合重放结果（行高定稿/固定格内容压平），展开永不回写源 graph；',
                '再生成: go-canvas 仓 make expand-fixtures（需本机 PHP 8.3）',
            ],
        ],
        'cases' => array_map('exportExpandCase', expandCases()),
    ],
];

foreach ($documents as $filename => $document) {
    $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        fwrite(STDERR, "{$filename} 序列化失败: " . json_last_error_msg() . "\n");
        exit(1);
    }
    file_put_contents($outDir . '/' . $filename, $json . "\n");
    printf("已导出 %d 个用例 → %s/%s\n", count($document['cases']), $outDir, $filename);
}
