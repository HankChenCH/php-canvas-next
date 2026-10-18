<?php

namespace HankChen\CanvasNext\Tests\Hydrate;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\CanvasBackground;
use HankChen\CanvasNext\Compiler\Diagnostic;
use HankChen\CanvasNext\Compiler\DiagnosticCollector;
use HankChen\CanvasNext\Exception\HydrateException;
use HankChen\CanvasNext\Hydrate\CanvasHydrator;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TableRowTemplate;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * hydrate 步骤（数据填充，spec §3）：编译期纯结构步骤 hydrate(Canvas, dataset): Canvas。
 * 全画布标记字段求值 + 模板表实例化，产出具体树（无模板、无表达式、高度定稿）；
 * 填充产物与源图分离，源 graph 永不回写
 */
class CanvasHydratorTest extends CanvasTestCase
{
    private CanvasHydrator $hydrator;

    protected function setUp(): void
    {
        $this->hydrator = new CanvasHydrator();
    }

    private function templateTable(): TableLayer
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

    private function dataset(): array
    {
        return [
            'orderNo' => 'A2026-001',
            'order' => ['items' => [
                ['name' => '张三', 'avatar' => '/tmp/a.png'],
                ['name' => '李四', 'avatar' => '/tmp/b.png'],
            ]],
        ];
    }

    public function testHydrateTemplateTableInstantiatesRows(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());

        $hydrated = $this->hydrator->hydrate($canvas, $this->dataset());

        /** @var TableLayer $table */
        $table = $hydrated->getLayers()[0];
        $this->assertInstanceOf(TableLayer::class, $table);

        // 行实例化：两行数据，模板消失，data 键消失
        $this->assertCount(2, $table->getRows());
        $this->assertNull($table->getTemplate());
        $graph = $table->graph();
        $this->assertArrayHasKey('rows', $graph);
        $this->assertArrayNotHasKey('template', $graph);
        $this->assertArrayNotHasKey('data', $graph);
    }

    public function testRowContextEvaluationAndOneBasedIndex(): void
    {
        $hydrated = $this->hydrator->hydrate(Canvas::make(320, 400, $this->templateTable()), $this->dataset());

        /** @var TableLayer $table */
        $table = $hydrated->getLayers()[0];

        /** @var TextLayer $text1 */
        $text1 = $table->getRows()[0]->getCells()[0]->getContentLayer();
        $this->assertSame('姓名：张三（1）', $text1->getText());
        $this->assertNull($text1->getExpression());

        /** @var TextLayer $text2 */
        $text2 = $table->getRows()[1]->getCells()[0]->getContentLayer();
        $this->assertSame('姓名：李四（2）', $text2->getText());

        /** @var ImageLayer $image */
        $image = $table->getRows()[1]->getCells()[1]->getContentLayer();
        $this->assertSame('/tmp/b.png', $image->getImage());
    }

    public function testHydratedHeightsFinalizedByV1CouplingReplay(): void
    {
        $hydrated = $this->hydrator->hydrate(Canvas::make(320, 400, $this->templateTable()), $this->dataset());

        /** @var TableLayer $table */
        $table = $hydrated->getLayers()[0];
        $row = $table->getRows()[0];

        // 宽在声明态同步：行宽 = 表宽
        $this->assertSame(320, $row->getWidth());

        // 高在填充态定稿：行高取最高格（auto 文本格按求值后内容 12，固定格 40）
        $this->assertSame(40, $row->getHeight());
        $this->assertFalse($row->graph()['spec']['shape']['autoHeight']);

        /** @var TextLayer $text */
        $text = $row->getCells()[0]->getContentLayer();
        $this->assertSame(12, $row->getCells()[0]->getHeight());
        $this->assertSame(12, $text->getHeight());

        // 固定格内容压平（V1 耦合重放）
        $image = $row->getCells()[1]->getContentLayer();
        $this->assertSame(40, $image->getHeight());
        $this->assertFalse($image->graph()['spec']['shape']['autoHeight']);
    }

    public function testHydrateDoesNotPolluteSourceCanvas(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());
        $graphBefore = $canvas->graph();

        $this->hydrator->hydrate($canvas, $this->dataset());

        // 填充产物与源图分离：源仍是声明态
        $this->assertSame($graphBefore, $canvas->graph());
        $this->assertInstanceOf(TableRowTemplate::class, $canvas->getLayers()[0]->getTemplate());
    }

    public function testIndependentLayersEvaluateAtRootContext(): void
    {
        $canvas = Canvas::make(
            320,
            100,
            TextLayer::make(200, 30)->setExpression('订单号：{{orderNo}}')->setFont('', 12, '#000'),
            ImageLayer::make(60, 60)->setExpression('{{orderNo}}'),
            (new QrCodeLayer())->setWidth(60)->setHeight(60)->setExpression('{{$root.orderNo}}')
        );

        $hydrated = $this->hydrator->hydrate($canvas, $this->dataset());

        /** @var TextLayer $text */
        $text = $hydrated->getLayers()[0];
        $this->assertSame('订单号：A2026-001', $text->getText());
        $this->assertNull($text->getExpression());

        /** @var ImageLayer $image */
        $image = $hydrated->getLayers()[1];
        $this->assertSame('A2026-001', $image->getImage());
        $this->assertNull($image->getExpression());

        /** @var QrCodeLayer $qr */
        $qr = $hydrated->getLayers()[2];
        $this->assertSame('A2026-001', $qr->getText());
        $this->assertNull($qr->getExpression());
    }

    public function testFitPassesThroughHydrateUntouched(): void
    {
        // fit 是静态声明（ADR 0023）：不进求值面与表达式白名单，随 graph 改写原样透传
        $canvas = Canvas::make(
            320,
            100,
            ImageLayer::make(60, 60)->setExpression('{{orderNo}}')->setFit(ImageLayer::FIT_CONTAIN),
            ImageLayer::make(60, 60)->setImage('/tmp/static.png')->setFit('fill')
        );

        $hydrated = $this->hydrator->hydrate($canvas, $this->dataset());

        $graph = $hydrated->graph();
        /** @var ImageLayer $marked */
        $marked = $hydrated->getLayers()[0];
        $this->assertSame('A2026-001', $marked->getImage());
        $this->assertSame('contain', $marked->getFit());
        $this->assertSame('contain', $graph['layers'][0]['spec']['fit']);
        $this->assertSame(['valueType' => 'StaticValue', 'value' => 'A2026-001'], $graph['layers'][0]['data']);

        /** @var ImageLayer $static */
        $static = $hydrated->getLayers()[1];
        $this->assertSame('fill', $static->getFit());
        $this->assertSame('fill', $graph['layers'][1]['spec']['fit']);
    }

    public function testFitPassesThroughTemplateInstantiation(): void
    {
        // 模板表实例化路径（expand 语义同门）：模板子树格内容层的 fit 随行实例原样透传
        $cell = (new TableCellLayer())->setWidth(160)->setHeight(40);
        $cell->addTemplateContentLayer(
            ImageLayer::make(160, 40)->setExpression('{{row.avatar}}')->setFit(ImageLayer::FIT_CONTAIN)
        );
        $row = (new TableRowTemplate())->setHeight(40);
        $row->addCell($cell);
        $table = TableLayer::make(320, 100, '#fff');
        $table->setRowsPath('order.items');
        $table->setTemplate($row);

        $hydrated = $this->hydrator->hydrate(Canvas::make(320, 100, $table), $this->dataset());

        $rows = $hydrated->getLayers()[0]->getRows();
        $this->assertCount(2, $rows);
        foreach ($rows as $rowNode) {
            /** @var ImageLayer $image */
            $image = $rowNode->getCells()[0]->getContentLayer();
            $this->assertSame('contain', $image->getFit());
        }
        $this->assertSame('contain', $hydrated->graph()['layers'][0]['rows'][0]['cells'][0]['content']['spec']['fit']);
    }

    public function testV1TableMarkedContentEvaluatesAtRootScope(): void
    {
        $table = TableLayer::make(320, 100, '#fff');
        $row = TableRowLayer::make(320, 'auto');
        $cell = TableCellLayer::make(320, 'auto', '#fff');
        $cell->addContentLayer(
            TextLayer::make(320, 'auto')->setExpression('{{orderNo}}')->setFont('', 12, '#000')
        );
        $row->addCell($cell);
        $table->addRow($row);

        $hydrated = $this->hydrator->hydrate(Canvas::make(320, 400, $table), $this->dataset());

        /** @var TableLayer $hydratedTable */
        $hydratedTable = $hydrated->getLayers()[0];
        $this->assertCount(1, $hydratedTable->getRows());
        /** @var TextLayer $text */
        $text = $hydratedTable->getRows()[0]->getCells()[0]->getContentLayer();
        $this->assertSame('A2026-001', $text->getText());
    }

    public function testNullDatasetIsIdentityEvenWithBindings(): void
    {
        // 未绑数据集：不跑求值器，全字面（标记字段按 value 镜像显示原文）
        $canvas = Canvas::make(320, 400, $this->templateTable());

        $hydrated = $this->hydrator->hydrate($canvas, null);

        $this->assertSame($canvas, $hydrated);
    }

    public function testCanvasWithoutBindingsIsIdentity(): void
    {
        $canvas = Canvas::make(320, 100, TextLayer::make(100, 30)->setText('字面 {{orderNo}} 文案'));

        $hydrated = $this->hydrator->hydrate($canvas, $this->dataset());

        // 无标记无模板：恒等直通（存量文案里的字面 {{ 不误伤）
        $this->assertSame($canvas, $hydrated);
    }

    public function testEmptyRowArrayYieldsEmptyShell(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());
        $dataset = ['order' => ['items' => []]];

        $hydrated = $this->hydrator->hydrate($canvas, $dataset);

        /** @var TableLayer $table */
        $table = $hydrated->getLayers()[0];
        // 空数组 = 合法零行，空壳渲染（表壳按声明画，行区零高）
        $this->assertSame([], $table->getRows());
        $this->assertNull($table->getTemplate());
    }

    public function testRowsPathMissingKeyThrows(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());

        try {
            $this->hydrator->hydrate($canvas, ['orderNo' => 'A2026-001']);
            $this->fail('rowsPath 指向缺键必须报错');
        } catch (HydrateException $e) {
            $this->assertSame('rows_path_invalid', $e->getErrorCode());
        }
    }

    public function testRowsPathNonArrayThrows(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());

        try {
            $this->hydrator->hydrate($canvas, ['order' => ['items' => 'not-an-array']]);
            $this->fail('rowsPath 指向非数组必须报错');
        } catch (HydrateException $e) {
            $this->assertSame('rows_path_invalid', $e->getErrorCode());
        }
    }

    public function testReservedRootKeyRowThrows(): void
    {
        $canvas = Canvas::make(
            320,
            100,
            TextLayer::make(100, 30)->setExpression('{{orderNo}}')->setFont('', 12, '#000')
        );

        try {
            $this->hydrator->hydrate($canvas, ['row' => ['name' => 'x']]);
            $this->fail('根级键名 row 必须报错');
        } catch (HydrateException $e) {
            $this->assertSame('reserved_root_key', $e->getErrorCode());
        }
    }

    public function testReservedRootKeyDollarPrefixThrows(): void
    {
        $canvas = Canvas::make(
            320,
            100,
            TextLayer::make(100, 30)->setExpression('{{orderNo}}')->setFont('', 12, '#000')
        );

        try {
            $this->hydrator->hydrate($canvas, ['$meta' => 1, 'orderNo' => 'A']);
            $this->fail('$ 前缀根级键名必须报错');
        } catch (HydrateException $e) {
            $this->assertSame('reserved_root_key', $e->getErrorCode());
        }
    }

    public function testEmptyResourceExpressionThrows(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());
        $dataset = ['order' => ['items' => [['name' => '张三']]]]; // avatar 缺失

        try {
            $this->hydrator->hydrate($canvas, $dataset);
            $this->fail('资源类字段求值空串必须报错');
        } catch (HydrateException $e) {
            $this->assertSame('expression_empty_resource', $e->getErrorCode());
        }
    }

    /** 资源单值门合法形态（ADR 0020）：恰一个 {{...}} 闭合片段 + 裸点路径内容 */
    private function imageTemplateTable(string $expression): TableLayer
    {
        $row = (new TableRowTemplate())->setHeight(40);
        $cell = (new TableCellLayer())->setWidth(160)->setHeight(40);
        $cell->addTemplateContentLayer(ImageLayer::make(160, 40)->setExpression($expression));
        $row->addCell($cell);

        $table = TableLayer::make(320, 200);
        $table->setRowsPath('order.items');
        $table->setTemplate($row);

        return $table;
    }

    public function testResourceSingleBarePathIsLegal(): void
    {
        // 五类形态之合法单路径：根上下文裸名放行（{{row.avatar}} 行上下文形态由主用例覆盖）
        $canvas = Canvas::make(320, 100, ImageLayer::make(60, 60)->setExpression('{{orderNo}}'));

        $hydrated = $this->hydrator->hydrate($canvas, ['orderNo' => 'A2026-001']);

        /** @var ImageLayer $image */
        $image = $hydrated->getLayers()[0];
        $this->assertSame('A2026-001', $image->getImage());
        $this->assertNull($image->getExpression());
    }

    public function testResourceIndexPathLegalInRowContext(): void
    {
        // $index 保留名同为裸点路径，行上下文放行（自 1 起）
        $hydrated = $this->hydrator->hydrate(
            Canvas::make(320, 400, $this->imageTemplateTable('{{$index}}')),
            $this->dataset()
        );

        /** @var TableLayer $table */
        $table = $hydrated->getLayers()[0];
        /** @var ImageLayer $image1 */
        $image1 = $table->getRows()[0]->getCells()[0]->getContentLayer();
        $this->assertSame('1', $image1->getImage());
        /** @var ImageLayer $image2 */
        $image2 = $table->getRows()[1]->getCells()[0]->getContentLayer();
        $this->assertSame('2', $image2->getImage());
    }

    /** @return array<string, array{string}> 五类违例形态（issue 01）+ 边角（空白片段/未闭合） */
    public static function resourceViolationProvider(): array
    {
        return [
            '多片段拼接' => ['{{orderNo}}{{orderNo}}'],
            '片段外字面（尾缀）' => ['{{orderNo}}.png'],
            '片段外字面（前缀）' => ['https://cdn/{{orderNo}}'],
            '函数调用' => ['{{number(orderNo, 2)}}'],
            '空片段' => ['{{}}'],
            '纯空白片段' => ['{{  }}'],
            '未闭合片段' => ['{{orderNo'],
        ];
    }

    /**
     * @dataProvider resourceViolationProvider
     */
    public function testResourceExpressionViolationFailsFast(string $expression): void
    {
        $canvas = Canvas::make(320, 100, ImageLayer::make(60, 60)->setExpression($expression));

        try {
            $this->hydrator->hydrate($canvas, $this->dataset());
            $this->fail("资源表达式「{$expression}」违反单值裸路径必须报错");
        } catch (HydrateException $e) {
            $this->assertSame('expression_resource_single_ref', $e->getErrorCode());
        }
    }

    public function testResourceViolationPrecedesEmptyResourceCheck(): void
    {
        // 顺序钉定：校验在求值前——违例形态即使求值必空也报 single_ref，而非 empty_resource
        $canvas = Canvas::make(320, 100, ImageLayer::make(60, 60)->setExpression('{{missing}}.png'));

        try {
            $this->hydrator->hydrate($canvas, ['orderNo' => 'A']);
            $this->fail('违例形态必须在求值前报错');
        } catch (HydrateException $e) {
            $this->assertSame('expression_resource_single_ref', $e->getErrorCode());
        }
    }

    public function testQrCodeViolationFailsFast(): void
    {
        // 二维码静默错编是该门的首要防线（渲染零报错、扫码才暴露的实测缺陷）
        $canvas = Canvas::make(320, 100, (new QrCodeLayer())->setWidth(60)->setHeight(60)->setExpression('{{orderNo}}-qr'));

        try {
            $this->hydrator->hydrate($canvas, $this->dataset());
            $this->fail('二维码违例表达式必须报错');
        } catch (HydrateException $e) {
            $this->assertSame('expression_resource_single_ref', $e->getErrorCode());
        }
    }

    public function testTextLayerInterpolationUnchangedBySingleRefGate(): void
    {
        // 门只管资源两类：文本层多片段拼接 + 字面混排照旧插值（spec 裁决 3：插值语义不动）
        $canvas = Canvas::make(
            320,
            100,
            TextLayer::make(200, 30)->setExpression('单号 {{orderNo}} 与 {{orderNo}}')->setFont('', 12, '#000')
        );

        $hydrated = $this->hydrator->hydrate($canvas, ['orderNo' => 'A2026-001']);

        /** @var TextLayer $text */
        $text = $hydrated->getLayers()[0];
        $this->assertSame('单号 A2026-001 与 A2026-001', $text->getText());
    }

    public function testUnmarkedResourceLiteralPassthroughUnchanged(): void
    {
        // 未标记字面直通不动：含 {{ 的字面 src 在其他图层有绑定时也不被求值、不被校验
        $canvas = Canvas::make(
            320,
            100,
            ImageLayer::make(60, 60)->setImage('https://cdn/{{orderNo}}.png'),
            TextLayer::make(200, 30)->setExpression('{{orderNo}}')->setFont('', 12, '#000')
        );

        $hydrated = $this->hydrator->hydrate($canvas, ['orderNo' => 'A2026-001']);

        /** @var ImageLayer $image */
        $image = $hydrated->getLayers()[0];
        $this->assertSame('https://cdn/{{orderNo}}.png', $image->getImage());
    }

    public function testTypeMismatchInRowContextThrows(): void
    {
        $templateRow = (new TableRowTemplate())->setHeight('auto');
        $cell = (new TableCellLayer())->setWidth(320)->setHeight('auto');
        $cell->addTemplateContentLayer(
            TextLayer::make(320, 'auto')->setExpression('{{row.tags}}')->setFont('', 12, '#000')
        );
        $templateRow->addCell($cell);

        $table = TableLayer::make(320, 200);
        $table->setRowsPath('items');
        $table->setTemplate($templateRow);

        $canvas = Canvas::make(320, 400, $table);

        try {
            $this->hydrator->hydrate($canvas, ['items' => [['tags' => ['a', 'b']]]]);
            $this->fail('数组落到标量位必须报错');
        } catch (HydrateException $e) {
            $this->assertSame('expression_type_mismatch', $e->getErrorCode());
        }
    }

    /** 文案标记整串落空的最小模板表 fixture（工票 04：{{row.missing}} 在行上下文兜底空串） */
    private function fallbackTemplateTable(): TableLayer
    {
        $row = (new TableRowTemplate())->setHeight('auto');
        $textCell = (new TableCellLayer())->setWidth(160)->setHeight('auto');
        $textCell->addTemplateContentLayer(
            TextLayer::make(160, 'auto')->setExpression('{{row.missing}}')->setFont('', 12, '#000')
        );
        $row->addCell($textCell);

        $table = TableLayer::make(320, 200, '#fff');
        $table->setRowsPath('order.items');
        $table->setTemplate($row);

        return $table;
    }

    public function testTextFallbackEmitsDiagnosticIntoCollector(): void
    {
        // 工票 04 / spec §3.3：文案类兜底空串（策略性软失败）经收集器发射 Diagnostic，
        // 求值边界协议不变（缺字段信号 = 空串），兜底行为本身零变化
        $collector = new DiagnosticCollector();
        $canvas = Canvas::make(
            320,
            400,
            $this->fallbackTemplateTable(),
            TextLayer::make(160, 30)->setExpression('{{missingRoot}}')->setFont('', 12, '#000')
        );

        $hydrated = $this->hydrator->hydrate($canvas, ['order' => ['items' => [['name' => '张三']]]], $collector);

        // 兜底行为不变：产物仍是无标记的具体树
        /** @var TableLayer $table */
        $table = $hydrated->getLayers()[0];
        /** @var TextLayer $rowText */
        $rowText = $table->getRows()[0]->getCells()[0]->getContentLayer();
        $this->assertSame('', $rowText->getText());
        /** @var TextLayer $rootText */
        $rootText = $hydrated->getLayers()[1];
        $this->assertSame('', $rootText->getText());

        // 每次兜底恰一条诊断：带表达式原文与定位路径（行内 = 实例化后路径）
        $warnings = $collector->all();
        $this->assertCount(2, $warnings);
        $this->assertContainsOnlyInstancesOf(Diagnostic::class, $warnings);

        $this->assertSame('expression_fallback_empty', $warnings[0]->code);
        $this->assertStringContainsString('{{row.missing}}', $warnings[0]->message);
        $this->assertSame('layers[0].rows[0].cells[0].content.data.value', $warnings[0]->path);

        $this->assertSame('expression_fallback_empty', $warnings[1]->code);
        $this->assertStringContainsString('{{missingRoot}}', $warnings[1]->message);
        $this->assertSame('layers[1].data.value', $warnings[1]->path);
    }

    public function testCollectorUntouchedWhenDatasetUnbound(): void
    {
        // 未绑数据集：不跑求值器，全画布恒等直通，收集器恒空
        $collector = new DiagnosticCollector();
        $canvas = Canvas::make(320, 400, $this->fallbackTemplateTable());

        $this->hydrator->hydrate($canvas, null, $collector);

        $this->assertSame([], $collector->all());
    }

    public function testCollectorUntouchedWithoutBindings(): void
    {
        // 无标记无模板：已绑数据集也恒等直通（存量文案里的字面 {{ 不误伤），收集器恒空
        $collector = new DiagnosticCollector();
        $canvas = Canvas::make(320, 100, TextLayer::make(200, 30)->setText('字面 {{orderNo}} 文案'));

        $hydrated = $this->hydrator->hydrate($canvas, ['orderNo' => 'A2026-001'], $collector);

        $this->assertSame($canvas, $hydrated);
        $this->assertSame([], $collector->all());
    }

    // ------------------------------------------------------------------
    // 画布背景透传（ADR 0022 / spec §3）：背景静态，不进 hydrate 改写面与表达式白名单
    // ------------------------------------------------------------------

    public function testBackgroundPassedThroughUntouchedWhileLayersAreHydrated(): void
    {
        // 图层标记求值照常进行；背景含 mustache 形态也原样透传（数据驱动底图走图层级，
        // 两形态并存合法——同形引用在层上被求值、在背景上保持字面）
        $canvas = Canvas::make(
            320,
            100,
            ImageLayer::make(320, 40)->setExpression('{{order.avatar}}'),
        )->setBackground(new CanvasBackground('{{order.color}}', '{{order.avatar}}'));

        $hydrated = $this->hydrator->hydrate($canvas, ['order' => ['avatar' => '/tmp/a.png', 'color' => '#ff0000']]);

        $this->assertSame('/tmp/a.png', $hydrated->getLayers()[0]->getImage());
        $this->assertSame(['color' => '{{order.color}}', 'image' => '{{order.avatar}}'], $hydrated->graph()['canvas']['background']);
        $this->assertSame($canvas->graph()['canvas']['background'], $hydrated->graph()['canvas']['background']);
    }
}
