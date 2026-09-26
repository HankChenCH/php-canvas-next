<?php

namespace HankChen\CanvasNext\Tests\Expand;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Exception\ExpandException;
use HankChen\CanvasNext\Expand\CanvasExpander;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TableRowTemplate;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 展开步骤（spec §4.2）：渲染前的独立纯结构步骤 expand(Canvas, dataset): Canvas。
 * 全画布标记字段求值 + 模板表实例化，产出具体树（无模板、无表达式、高度定稿）；
 * 展开产物与源图分离，源 graph 永不回写
 */
class CanvasExpanderTest extends CanvasTestCase
{
    private CanvasExpander $expander;

    protected function setUp(): void
    {
        $this->expander = new CanvasExpander();
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

    public function testExpandTemplateTableInstantiatesRows(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());

        $expanded = $this->expander->expand($canvas, $this->dataset());

        /** @var TableLayer $table */
        $table = $expanded->getLayers()[0];
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
        $expanded = $this->expander->expand(Canvas::make(320, 400, $this->templateTable()), $this->dataset());

        /** @var TableLayer $table */
        $table = $expanded->getLayers()[0];

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

    public function testExpandedHeightsFinalizedByV1CouplingReplay(): void
    {
        $expanded = $this->expander->expand(Canvas::make(320, 400, $this->templateTable()), $this->dataset());

        /** @var TableLayer $table */
        $table = $expanded->getLayers()[0];
        $row = $table->getRows()[0];

        // 宽在声明态同步：行宽 = 表宽
        $this->assertSame(320, $row->getWidth());

        // 高在展开态定稿：行高取最高格（auto 文本格按求值后内容 12，固定格 40）
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

    public function testExpandDoesNotPolluteSourceCanvas(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());
        $graphBefore = $canvas->graph();

        $this->expander->expand($canvas, $this->dataset());

        // 展开产物与源图分离：源仍是声明态
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

        $expanded = $this->expander->expand($canvas, $this->dataset());

        /** @var TextLayer $text */
        $text = $expanded->getLayers()[0];
        $this->assertSame('订单号：A2026-001', $text->getText());
        $this->assertNull($text->getExpression());

        /** @var ImageLayer $image */
        $image = $expanded->getLayers()[1];
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

        $expanded = $this->expander->expand(Canvas::make(320, 400, $table), $this->dataset());

        /** @var TableLayer $expandedTable */
        $expandedTable = $expanded->getLayers()[0];
        $this->assertCount(1, $expandedTable->getRows());
        /** @var TextLayer $text */
        $text = $expandedTable->getRows()[0]->getCells()[0]->getContentLayer();
        $this->assertSame('A2026-001', $text->getText());
    }

    public function testNullDatasetIsIdentityEvenWithBindings(): void
    {
        // 未绑数据集：不跑求值器，全字面（标记字段按 value 镜像显示原文）
        $canvas = Canvas::make(320, 400, $this->templateTable());

        $expanded = $this->expander->expand($canvas, null);

        $this->assertSame($canvas, $expanded);
    }

    public function testCanvasWithoutBindingsIsIdentity(): void
    {
        $canvas = Canvas::make(320, 100, TextLayer::make(100, 30)->setText('字面 {{orderNo}} 文案'));

        $expanded = $this->expander->expand($canvas, $this->dataset());

        // 无标记无模板：恒等直通（存量文案里的字面 {{ 不误伤）
        $this->assertSame($canvas, $expanded);
    }

    public function testEmptyRowArrayYieldsEmptyShell(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());
        $dataset = ['order' => ['items' => []]];

        $expanded = $this->expander->expand($canvas, $dataset);

        /** @var TableLayer $table */
        $table = $expanded->getLayers()[0];
        // 空数组 = 合法零行，空壳渲染（表壳按声明画，行区零高）
        $this->assertSame([], $table->getRows());
        $this->assertNull($table->getTemplate());
    }

    public function testRowsPathMissingKeyThrows(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());

        try {
            $this->expander->expand($canvas, ['orderNo' => 'A2026-001']);
            $this->fail('rowsPath 指向缺键必须报错');
        } catch (ExpandException $e) {
            $this->assertSame('rows_path_invalid', $e->getErrorCode());
        }
    }

    public function testRowsPathNonArrayThrows(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());

        try {
            $this->expander->expand($canvas, ['order' => ['items' => 'not-an-array']]);
            $this->fail('rowsPath 指向非数组必须报错');
        } catch (ExpandException $e) {
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
            $this->expander->expand($canvas, ['row' => ['name' => 'x']]);
            $this->fail('根级键名 row 必须报错');
        } catch (ExpandException $e) {
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
            $this->expander->expand($canvas, ['$meta' => 1, 'orderNo' => 'A']);
            $this->fail('$ 前缀根级键名必须报错');
        } catch (ExpandException $e) {
            $this->assertSame('reserved_root_key', $e->getErrorCode());
        }
    }

    public function testEmptyResourceExpressionThrows(): void
    {
        $canvas = Canvas::make(320, 400, $this->templateTable());
        $dataset = ['order' => ['items' => [['name' => '张三']]]]; // avatar 缺失

        try {
            $this->expander->expand($canvas, $dataset);
            $this->fail('资源类字段求值空串必须报错');
        } catch (ExpandException $e) {
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
            $this->expander->expand($canvas, ['items' => [['tags' => ['a', 'b']]]]);
            $this->fail('数组落到标量位必须报错');
        } catch (ExpandException $e) {
            $this->assertSame('expression_type_mismatch', $e->getErrorCode());
        }
    }
}
