<?php

namespace HankChen\CanvasNext\Tests\Layer;

use HankChen\CanvasNext\Exception\DecodeException;
use HankChen\CanvasNext\Layer\LayerFactory;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TableRowTemplate;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * TableLayer V2 模板态契约（spec §2）：template ⊕ rows XOR、条件写键、
 * 宽度耦合沿用、高度耦合全豁免、往返恒等对象 = 声明态
 */
class TableTemplateTest extends CanvasTestCase
{
    private function template(): TableRowTemplate
    {
        $row = (new TableRowTemplate())->setWidth(320)->setHeight('auto');

        $autoCell = (new TableCellLayer())->setWidth(160)->setHeight('auto');
        $autoCell->addTemplateContentLayer(
            TextLayer::make(160, 'auto')->setText('姓名：{{row.name}}')->setFont('', 12, '#000')
        );

        $fixedCell = (new TableCellLayer())->setWidth(160)->setHeight(40);
        $fixedCell->addTemplateContentLayer(TextLayer::make(160, 'auto')->setText('{{row.avatar}}')->setFont('', 12, '#000'));

        $row->addCell($autoCell)->addCell($fixedCell);

        return $row;
    }

    private function templateTable(): TableLayer
    {
        $table = TableLayer::make(320, 200, '#fff');
        $table->setRowsPath('order.items');
        $table->setTemplate($this->template());

        return $table;
    }

    public function testTemplateStateRoundtripIdentity(): void
    {
        $table = $this->templateTable();

        // 恒等对象 = 声明态：template 态 graph → fromGraph → graph 恒等
        $rebuilt = TableLayer::fromGraph($table->graph());

        $this->assertSame($table->graph(), $rebuilt->graph());
    }

    public function testTemplateStateWritesConditionalKeysOnly(): void
    {
        $graph = $this->templateTable()->graph();

        // 模板态：写 data/template，不写 rows（XOR 条件写键，保三端字节面）
        $this->assertArrayNotHasKey('rows', $graph);
        $this->assertSame(['rowsPath' => 'order.items'], $graph['data']);
        $this->assertSame('TableRowTemplate', $graph['template']['type']);
        $this->assertCount(2, $graph['template']['cells']);
    }

    public function testV1TableGraphKeepsLegacyBytes(): void
    {
        $table = TableLayer::make(100, 40);
        $row = TableRowLayer::make(100, 'auto');
        $row->addCell(TableCellLayer::make(100, 10, '#f00'));
        $table->addRow($row);

        $graph = $table->graph();

        // V1 现状：恒写 rows，无 template/data 键，字节面零差异
        $this->assertArrayHasKey('rows', $graph);
        $this->assertArrayNotHasKey('template', $graph);
        $this->assertArrayNotHasKey('data', $graph);
    }

    public function testTemplateStateRowsAccessorEmptyAndTemplateAccessible(): void
    {
        $table = $this->templateTable();

        // V1 访问器不越界：模板态 getRows() 返回 []
        $this->assertSame([], $table->getRows());
        $this->assertInstanceOf(TableRowTemplate::class, $table->getTemplate());
        $this->assertSame('order.items', $table->getRowsPath());
    }

    public function testTemplateAndRowsConflictThrows(): void
    {
        $graph = $this->templateTable()->graph();
        $graph['rows'] = [];

        try {
            TableLayer::fromGraph($graph);
            $this->fail('template 与 rows 双键同现必须抛错');
        } catch (DecodeException $e) {
            $this->assertSame('template_rows_conflict', $e->getErrorCode());
        }
    }

    public function testAddRowOnTemplateTableThrows(): void
    {
        $table = $this->templateTable();

        $this->expectException(DecodeException::class);
        $this->expectExceptionMessage('template_rows_conflict');

        $table->addRow(TableRowLayer::make(320, 'auto'));
    }

    public function testSetTemplateClearsExistingRows(): void
    {
        $table = TableLayer::make(320, 200);
        $row = TableRowLayer::make(320, 'auto');
        $row->addCell(TableCellLayer::make(320, 10, '#f00'));
        $table->addRow($row);

        $table->setTemplate($this->template());

        // API 侧互斥：清空切换语义（spec §2.2，实现细化）
        $this->assertSame([], $table->getRows());
        $this->assertArrayNotHasKey('rows', $table->graph());
    }

    public function testTemplateStateRequiresRowsPath(): void
    {
        $graph = $this->templateTable()->graph();
        unset($graph['data']);

        try {
            TableLayer::fromGraph($graph);
            $this->fail('模板态缺 rowsPath 必须抛错');
        } catch (DecodeException $e) {
            $this->assertSame('rows_path_missing', $e->getErrorCode());
        }
    }

    public function testSetTemplateSyncsRowWidthOnly(): void
    {
        $template = $this->template();
        $table = TableLayer::make(320, 200, '#fff');
        $table->setTemplate($template);

        // 宽度耦合沿用：行宽 = 表宽（关 autoWidth）
        $this->assertSame(320, $template->getWidth());
        $this->assertFalse($template->graph()['spec']['shape']['autoWidth']);

        $cells = $template->getCells();
        // 内容宽 = 格宽
        $this->assertSame(160, $cells[0]->getContentLayer()->getWidth());
        $this->assertSame(160, $cells[1]->getContentLayer()->getWidth());
    }

    public function testTemplateDecodeExemptsHeightCoupling(): void
    {
        $rebuilt = TableLayer::fromGraph($this->templateTable()->graph());
        $template = $rebuilt->getTemplate();

        // 高度耦合全豁免：声明高与 autoHeight 标志原样保留（弹性信息留给展开）
        $this->assertTrue($template->graph()['spec']['shape']['autoHeight']);

        $autoCell = $template->getCells()[0];
        $autoCellGraph = $autoCell->graph()['spec']['shape'];
        $this->assertTrue($autoCellGraph['autoHeight']);
        $this->assertSame(0, $autoCellGraph['height']);

        $content = $autoCell->getContentLayer();
        $contentGraph = $content->graph()['spec']['shape'];
        $this->assertTrue($contentGraph['autoHeight']);

        // 固定格内容不压平：auto 标志不被固化
        $fixedContent = $template->getCells()[1]->getContentLayer();
        $this->assertTrue($fixedContent->graph()['spec']['shape']['autoHeight']);
    }

    public function testTableRowTemplateRegisteredInFactory(): void
    {
        $layer = LayerFactory::fromGraph([
            'type' => 'TableRowTemplate',
            'spec' => ['shape' => ['width' => 320, 'height' => 0, 'autoHeight' => true]],
            'cells' => [],
        ]);

        $this->assertInstanceOf(TableRowTemplate::class, $layer);
        $this->assertSame('TableRowTemplate', $layer->graph()['type']);
    }

    public function testUnknownLayerTypeCarriesStableCode(): void
    {
        try {
            LayerFactory::fromGraph(['type' => 'NopeLayer']);
            $this->fail('未知 type 必须抛错');
        } catch (DecodeException $e) {
            $this->assertSame('unknown_layer_type', $e->getErrorCode());
            $this->assertSame('unknown_layer_type: NopeLayer', $e->getMessage());
        }
    }
}
