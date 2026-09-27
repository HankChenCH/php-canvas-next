<?php

namespace HankChen\CanvasNext\Tests\Hydrate;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\Diagnostic;
use HankChen\CanvasNext\Compiler\DiagnosticCollector;
use HankChen\CanvasNext\Exception\HydrateException;
use HankChen\CanvasNext\Hydrate\CanvasHydrator;
use HankChen\CanvasNext\Layer\ImageLayer;
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
            ImageLayer::make(60, 60)->setExpression('{{orderNo}}.png')
        );

        $hydrated = $this->hydrator->hydrate($canvas, $this->dataset());

        /** @var TextLayer $text */
        $text = $hydrated->getLayers()[0];
        $this->assertSame('订单号：A2026-001', $text->getText());
        $this->assertNull($text->getExpression());

        /** @var ImageLayer $image */
        $image = $hydrated->getLayers()[1];
        $this->assertSame('A2026-001.png', $image->getImage());
        $this->assertNull($image->getExpression());
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
}
