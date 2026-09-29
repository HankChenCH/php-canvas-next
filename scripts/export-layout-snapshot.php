<?php

declare(strict_types=1);

/**
 * 双端布局快照导出：对固定用例集输出布局快照 JSON，作为 PHP/Go 两端的单一事实源
 * （go-canvas 工单 10）。
 *
 * 产物 = 每个用例的 graph（输入）+ 绘制原语记录（绘制坐标，经真实 AbstractRenderer
 * 模板录制）。Go 侧测试解码快照 graph 重建画布、驱动同构渲染模板，逐记录断言：
 * 文本行 y 以 expectedYDiff 标注 GD 基线魔数偏移（ADR-0003 预期差异字段），
 * 其余字段逐字段一致。
 *
 * 用法: php scripts/export-layout-snapshot.php [输出路径]
 * 默认输出到 ../go-canvas/renderer/testdata/layout-snapshot.json（快照提交进 Go 仓库，
 * 经 go-canvas 的 make layout-snapshot 人工触发再生成；Go CI 不装 PHP）。
 *
 * 用例集在本脚本内单点定义；改动用例集或任何布局可观察面（断行/度量/锚点/表格
 * add 副作用/默认值）后须重导，并人审快照 diff（diff 即双端布局行为 diff）。
 */

require __DIR__ . '/../vendor/autoload.php';

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Layer\AbstractLayer;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TableRowTemplate;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Renderer\AbstractRenderer;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Runtime\NullCancellation;

/**
 * 录制渲染器：把绘制原语调用录成数组，驱动真实渲染模板（物化→遍历→分派→容器下钻），
 * 保证快照坐标与 PHP 渲染面完全一致
 */
final class RecordingRenderer extends AbstractRenderer
{
    /** @var array<int, array<string, mixed>> 绘制原语记录（paint 序） */
    public array $records = [];

    public function __construct()
    {
        parent::__construct(new ResourceResolver());
    }

    protected function begin(int $width, int $height): void
    {
    }

    protected function end(): mixed
    {
        return null;
    }

    protected function drawRect(int $x, int $y, int $width, int $height, ?string $bgColor, array $border): void
    {
        $this->records[] = ['op' => 'rect', 'x' => $x, 'y' => $y, 'w' => $width, 'h' => $height];
    }

    protected function drawImage(string $src, int $x, int $y, int $width, int $height): void
    {
        // src 是机器本地缓存路径，不进快照；坐标与盒子（缩放铺放目标）才是布局事实
        $this->records[] = ['op' => 'image', 'x' => $x, 'y' => $y, 'w' => $width, 'h' => $height];
    }

    protected function drawText(
        string $line,
        int $x,
        int $y,
        string $fontFile,
        int $fontSize,
        string $fontColor,
        string $horizontalAlign,
        string $verticalAlign,
        int $angle
    ): void {
        // expectedYDiff 由导出流程按图层归属事后标注（绘制原语收不到图层上下文）
        $this->records[] = ['op' => 'text', 'line' => $line, 'x' => $x, 'y' => $y];
    }
}

/**
 * 文本图层相对纯对齐锚点 y 的预期差异 = getTextOrigin bottom+autowrap 分支的
 * GD 基线魔数 - round(fontSize*0.1)（ADR-0003：Go 不移植，Go 断言 goY == y + expectedYDiff）。
 * 其余分支双端公式一致，差异恒为 0。
 */
function expectedYDiff(TextLayer $layer): int
{
    if ($layer->getVerticalAlign() === 'bottom' && $layer->getAutowrap()) {
        return (int) round($layer->getFontSize() * 0.1);
    }

    return 0;
}

/**
 * 前序遍历收集文本图层（表→行→单元格→内容层下钻序与渲染模板一致，
 * 根图层由调用方按 priority 序传入），用于给 text 记录标注 expectedYDiff
 */
function collectTextLayers(AbstractLayer $layer, array &$out): void
{
    if ($layer instanceof TableLayer) {
        foreach ($layer->getRows() as $row) {
            collectTextLayers($row, $out);
        }

        return;
    }
    if ($layer instanceof TableRowLayer) {
        foreach ($layer->getCells() as $cell) {
            collectTextLayers($cell, $out);
        }

        return;
    }
    if ($layer instanceof TableCellLayer) {
        if ($layer->getContentLayer() !== null) {
            collectTextLayers($layer->getContentLayer(), $out);
        }

        return;
    }
    if ($layer instanceof TextLayer) {
        $out[] = $layer;
    }
}

/**
 * 单个用例 → {name, description, graph, records}；渲染 + 文本记录标注，
 * 并自检文本行序列与图层遍历严格同步（防标注错位）
 */
function exportCase(string $name, string $description, Canvas $canvas): array
{
    // 归一化重放一次(构造态 → wire → 重建态),graph 与绘制记录均取重建态。
    // 理由:单元格 auto 采纳内容高后,内容层自身仍保留 auto/0 高,重建时被压平——
    // 构造态 graph 非往返不动点(fromGraph→graph ≠ graph,PHP/Go 两端同款语义),
    // 重建态才是双端一致的规范形。fixture 以规范形为单一事实源:输入 graph、
    // Go 解码后再序列化、绘制记录三者同源,逐字段可比
    $canvas = Canvas::fromGraph($canvas->graph());

    $renderer = new RecordingRenderer();
    $renderer->render(new NullCancellation(), $canvas);
    $records = $renderer->records;

    $textLayers = [];
    foreach ($canvas->getLayers() as $rootLayer) {
        // 契约:visible=false 的根图层渲染跳过(layer-panel-ux 工单 01),不产生绘制记录,
        // 文本标注收集须与渲染模板同门(容器子层无显隐语义,不下钻过滤)
        if (!$rootLayer->isVisible()) {
            continue;
        }
        collectTextLayers($rootLayer, $textLayers);
    }

    $expectedLines = [];
    foreach ($textLayers as $textLayer) {
        $diff = expectedYDiff($textLayer);
        foreach ($textLayer->getLines() as $line) {
            $expectedLines[] = [$line, $diff];
        }
    }

    $textCount = count(array_filter($records, static fn (array $r) => $r['op'] === 'text'));
    if ($textCount !== count($expectedLines)) {
        throw new RuntimeException("用例 {$name}: text 记录数({$textCount}) 与文本图层行数(" . count($expectedLines) . ')不一致');
    }

    $index = 0;
    foreach ($records as &$record) {
        if ($record['op'] !== 'text') {
            continue;
        }
        [$line, $diff] = $expectedLines[$index++];
        if ($line !== $record['line']) {
            throw new RuntimeException("用例 {$name}: 第 {$index} 个 text 记录与图层遍历序不同步");
        }
        $record['expectedYDiff'] = $diff;
    }
    unset($record);

    return [
        'name' => $name,
        'description' => $description,
        'graph' => $canvas->graph(),
        'records' => $records,
    ];
}

/**
 * 固定用例集：覆盖 CJK 断行、禁则、词边界、emoji、对齐锚点全分支、九锚点、
 * 表格嵌套与溢出摆放。用例须让 PHP 有 ext-intl（字素簇切分）与 Go 默认
 * 码点切分两条路径断行一致——emoji 只用单码点字素（ZWJ 组合序列的切分差异
 * 属注入缝，由 Go 单测的字素桩与增强实现锁定，不进快照）
 */
function buildCases(): array
{
    return [
        exportCase(
            'cjk-autowrap',
            'CJK 逐字断行 + 显式换行保留空行 + padding + 行高 1.4 + autoHeight；默认 bottom+autowrap 走预期差异分支(expectedYDiff=2)',
            Canvas::make(120, 60,
                TextLayer::make(100, 'auto')
                    ->setText("画布渲染库快照用例第一段超长文本\n\n第二段落继续自动断行")
                    ->setFont('', 16, '#333333')
                    ->setLineHeight(1.4)
                    ->setPadding(4)
                    ->setAutowrap(true)
            )
        ),
        exportCase(
            'kinsoku-word-boundary',
            '行首禁则标点上移、行末禁则标点下移、空格词边界、连字符保留行尾、无边界长词硬断、断点行尾空白剔除',
            Canvas::make(120, 60,
                TextLayer::make(120, 'auto')
                    // 盒宽 120px、字号 12:每行恰 10 个全角字符。"，"排在第 11 位触发上移(行溢出 1 字),
                    // "（"排在第 2 行第 10 位触发下移;中段覆盖空格词边界,末段(aaaa-bbbbb-…)在
                    // 连字符处溢出,验证连字符保留行尾;无边界长词硬断见 superlongword…
                    ->setText('一二三四五六七八九十，甲乙丙丁戊己庚辛壬（癸 latin words wrap at spaces, '
                        . 'hyphen-ated and co-op terms, superlongwordwithoutboundary breaks hard'
                        . "\naaaa-bbbbb-cccccdddddeeeee tail")
                    ->setFont('', 12, '#000000')
                    ->setAutowrap(true)
            )
        ),
        exportCase(
            'emoji-mixed',
            '单码点 emoji 与中文混排断行（字素簇与码点两条切分路径行为一致）',
            Canvas::make(80, 40,
                TextLayer::make(80, 'auto')
                    ->setText('表情👍与中文😀混排🎉断行试试')
                    ->setFont('', 12, '#000000')
                    ->setAutowrap(true)
            )
        ),
        exportCase(
            'align-origin-grid',
            '水平×垂直九种对齐组合（固定高，center 分支含 padding 内容盒）+ center×autoHeight 分支 + bottom×autowrap 多行预期差异分支',
            buildAlignOriginGrid()
        ),
        exportCase(
            'anchors-containers',
            '九锚点摆位 + 负偏移溢出 + priority 渲染次序 + 表格嵌套（行堆叠/单元格横排/单元格 content:null/表格内容层）',
            buildAnchorsAndContainers()
        ),
        exportCase(
            'table-template-wire',
            'TableLayer V2 模板态 wire 三端 parity（go-canvas 工票 12）：template 键 + data.rowsPath + TableRowTemplate 类型'
            . ' + 表达式标记（Text 恒写 expression 三键 / Image 条件两键）；模板态渲染 = 空壳（表壳按声明画，行区零高），V1 具体行表同画布对照',
            buildTemplateWire()
        ),
        exportCase(
            'name-visible-contract',
            '图层 name/visible 契约（layer-panel-ux 工单 01）：name 仅非空写键、visible 仅 false 写键，'
            . '缺省图层字节面与无字段版本一致；hidden 根层渲染跳过（不产生绘制记录），named+hidden 与'
            . ' named+visible 与无名默认三层对照',
            buildNameVisibleContract()
        ),
    ];
}

/**
 * name/visible 契约用例（layer-panel-ux 工单 01）：同画布三层对照——
 * named+visible（name 键在场）、named+hidden（name + visible:false 双键、渲染跳过）、
 * 无名默认（两键均省略）。空引用图片层零物化只画盒
 */
function buildNameVisibleContract(): Canvas
{
    $namedVisible = TextLayer::make(80, 24)
        ->setText('named visible')
        ->setFont('', 12, '#000000')
        ->setDisplayName('标题层')
        ->setPosition(0, 0);

    $namedHidden = ImageLayer::make(60, 40, '#ff0000')
        ->setDisplayName('隐藏图')
        ->setVisible(false)
        ->setPosition(0, 40);

    $plain = (new QrCodeLayer())
        ->setWidth(40)
        ->setHeight(24)
        ->setPosition(100, 0);

    return Canvas::make(160, 80, $namedVisible, $namedHidden, $plain);
}

/**
 * 模板表声明态 wire 用例（TableLayer V2，spec §2）：模板表与 V1 表同画布对照。
 * 模板行 = auto 文本格（表达式标记，value 镜像原文）+ 固定空 src 图片格
 * （渲染期惰性物化按需，空引用跳过不下载）
 */
function buildTemplateWire(): Canvas
{
    $row = (new TableRowTemplate())->setHeight('auto');
    $textCell = (new TableCellLayer())->setWidth(160)->setHeight('auto');
    $textCell->addTemplateContentLayer(
        TextLayer::make(160, 'auto')->setExpression('姓名：{{row.name}}')->setFont('', 12, '#000000')
    );
    $imageCell = (new TableCellLayer())->setWidth(160)->setHeight(40);
    $imageCell->addTemplateContentLayer(ImageLayer::make(160, 40));
    $row->addCell($textCell)->addCell($imageCell);

    $templateTable = TableLayer::make(320, 200, '#ffffff');
    $templateTable->setRowsPath('order.items');
    $templateTable->setTemplate($row);

    $v1Cell = (new TableCellLayer())->setWidth(320)->setHeight(30);
    $v1Cell->addContentLayer(
        TextLayer::make(320, 30)->setText('V1 具体行')->setFont('', 12, '#000000')
    );
    $v1Table = TableLayer::make(320, 100, '#f0f0f0')
        ->setPosition(0, 220)
        ->addRow(TableRowLayer::make(320, 30)->addCell($v1Cell));

    return Canvas::make(320, 320, $templateTable, $v1Table);
}

/** 对齐锚点用例：九宫格对齐组合 + 两个特殊分支图层 */
function buildAlignOriginGrid(): Canvas
{
    $layers = [];
    $verticals = ['top', 'center', 'bottom'];
    $horizontals = ['left', 'center', 'right'];
    foreach ($verticals as $row => $vertical) {
        foreach ($horizontals as $col => $horizontal) {
            $layers[] = TextLayer::make(60, 40)
                ->setText('对齐锚点')
                ->setFont('', 12, '#000000')
                ->setPadding(4)
                ->setHorizontalAlign($horizontal)
                ->setVerticalAlign($vertical)
                ->setPosition($col * 90, $row * 80);
        }
    }

    // center×autoHeight:origin y = lineHeightPx/2（内容盒即单行盒）
    $layers[] = TextLayer::make(60, 'auto')
        ->setText('自适应')
        ->setFont('', 12, '#000000')
        ->setPadding(4)
        ->setHorizontalAlign('center')
        ->setVerticalAlign('center')
        ->setPosition(0, 240);

    // bottom×autowrap 固定高多行:预期差异分支(expectedYDiff=1)
    $layers[] = TextLayer::make(60, 40)
        ->setText('底部对齐多行文本')
        ->setFont('', 12, '#000000')
        ->setAutowrap(true)
        ->setPosition(90, 240);

    return Canvas::make(280, 280, ...$layers);
}

/** 九锚点 + 表格嵌套用例：见用例 description */
function buildAnchorsAndContainers(): Canvas
{
    // 九锚点摆位:同为 40×24 盒,position 偏移全零,坐标差即锚点差;类型轮换覆盖三种叶子图层
    $anchorLayers = [];
    $anchors = [
        ['top-left', 'text'], ['top', 'image'], ['top-right', 'qr'],
        ['left', 'image'], ['center', 'qr'], ['right', 'text'],
        ['bottom-left', 'qr'], ['bottom', 'text'], ['bottom-right', 'image'],
    ];
    foreach ($anchors as [$anchor, $type]) {
        $anchorLayers[] = match ($type) {
            'text' => TextLayer::make(40, 24)
                ->setText('锚')
                ->setFont('', 12, '#000000')
                ->setPosition(0, 0, $anchor),
            'image' => ImageLayer::make(40, 24)->setPosition(0, 0, $anchor),
            // 空内容二维码:两端物化均跳过,只画盒
            'qr' => (new QrCodeLayer())->setWidth(40)->setHeight(24)->setPosition(0, 0, $anchor),
        };
    }

    // 溢出摆放:负偏移不钳位,绝对坐标为负
    $overflow = TextLayer::make(60, 24)
        ->setText('溢出')
        ->setFont('', 12, '#000000')
        ->setPosition(-8, -6)
        ->setPriority(-3);

    // 表格嵌套:auto 高度传播(行←单元格←文本内容)、固定高压平(图片内容)、
    // 空内容单元格(content:null)、表内容层为嵌套表
    $textCell = (new TableCellLayer())
        ->setWidth(100)
        ->setHeight('auto')
        ->addContentLayer(
            TextLayer::make(100, 'auto')
                ->setText('表格内文本自动断行排版')
                ->setFont('', 12, '#000000')
                ->setPadding(4)
                ->setAutowrap(true)
        );
    $imageCell = (new TableCellLayer())
        ->setWidth(100)
        ->setHeight(40)
        ->addContentLayer(ImageLayer::make(100, 40));
    $row1 = TableRowLayer::make(200, 'auto')->addCell($textCell)->addCell($imageCell);

    $innerTable = TableLayer::make(200, 60)
        ->addRow(
            TableRowLayer::make(200, 30)
                ->addCell((new TableCellLayer())->setWidth(60)->setHeight(30))
                ->addCell((new TableCellLayer())->setWidth(140)->setHeight(30))
        )
        ->addRow(
            TableRowLayer::make(200, 30)->addCell(
                (new TableCellLayer())->setWidth(200)->setHeight(30)
            )
        );
    $nestedCell = (new TableCellLayer())
        ->setWidth(200)
        ->setHeight(60)
        ->addContentLayer($innerTable);
    $row2 = TableRowLayer::make(200, 60)->addCell($nestedCell);

    // priority 5 越大越先渲染(垫底),录制序验证排序
    $table = TableLayer::make(200, 'auto')
        ->setPosition(40, 180)
        ->setPriority(5)
        ->addRow($row1)
        ->addRow($row2);

    return Canvas::make(320, 280, $table, ...$anchorLayers, ...[$overflow]);
}

$outPath = $argv[1] ?? __DIR__ . '/../../go-canvas/renderer/testdata/layout-snapshot.json';

$snapshot = [
    'meta' => [
        'contract' => 'layout-snapshot v1',
        'generator' => 'php-canvas-next/scripts/export-layout-snapshot.php',
        'notes' => [
            'PHP 侧对固定用例集导出的双端布局快照(go-canvas 工单 10),Go 测试逐字段断言;',
            'graph 为输入(与 php-canvas-next Canvas::graph() 键级一致),records 为渲染模板绘制原语记录(paint 序,即 priority 降序);',
            '文本行 y 是预期差异字段:expectedYDiff = getTextOrigin bottom+autowrap 分支的 GD 基线魔数 round(fontSize*0.1),',
            'Go 按 ADR-0003 纯对齐锚点语义断言 goY == y + expectedYDiff;其余坐标、行数、行内容逐字段严格一致;',
            '用例集保证 ext-intl 字素簇切分与 Go 默认码点切分断行一致(emoji 仅单码点字素,ZWJ 序列不进快照);',
            '再生成: go-canvas 仓库 make layout-snapshot(需本机 PHP 8.3 + ext-intl),流程见 go-canvas/docs/layout-snapshot.md',
        ],
    ],
    'cases' => buildCases(),
];

$json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "快照序列化失败: " . json_last_error_msg() . "\n");
    exit(1);
}

$dir = dirname($outPath);
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    fwrite(STDERR, "输出目录不可创建: {$dir}\n");
    exit(1);
}
file_put_contents($outPath, $json . "\n");

$totalRecords = array_sum(array_map(static fn (array $case) => count($case['records']), $snapshot['cases']));
printf("已导出 %d 个用例、%d 条绘制记录 → %s\n", count($snapshot['cases']), $totalRecords, $outPath);
