<?php

namespace HankChen\CanvasNext\Tests\Layer;

use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

class TableLayersTest extends CanvasTestCase
{
    private function rowWithCell(int $cellWidth, int $cellHeight, string $bg): TableRowLayer
    {
        $row = TableRowLayer::make($cellWidth, 'auto');
        $row->addCell(TableCellLayer::make($cellWidth, $cellHeight, $bg));

        return $row;
    }

    public function testAddRowSyncsRowWidthToTableWidth(): void
    {
        $table = TableLayer::make(200, 100);
        $row = $this->rowWithCell(50, 10, '#fff');

        $table->addRow($row);

        $this->assertSame(200, $row->getWidth());
    }

    public function testIsOverHeightTracksAccumulatedRowHeights(): void
    {
        $table = TableLayer::make(200, 25);
        $table->addRow($this->rowWithCell(200, 10, '#fff'));

        $this->assertFalse($table->isOverHeight($this->rowWithCell(200, 15, '#fff')));
        $this->assertTrue($table->isOverHeight($this->rowWithCell(200, 16, '#fff')));
    }

    public function testAddCellGrowsRowHeightToTallest(): void
    {
        $row = TableRowLayer::make(100, 'auto');
        $row->addCell(TableCellLayer::make(50, 12, '#fff'));
        $row->addCell(TableCellLayer::make(50, 30, '#fff'));

        $this->assertSame(30, $row->getHeight());
    }

    public function testFixedCellFlattensContentHeight(): void
    {
        $cell = TableCellLayer::make(100, 40, '#fff');
        $text = TextLayer::make(100, 'auto')->setText('内容');

        $cell->addContentLayer($text);

        $this->assertSame(40, $text->getHeight());
        $this->assertFalse($text->graph()['spec']['shape']['autoHeight']);
    }

    public function testAutoCellAdoptsContentHeight(): void
    {
        $cell = TableCellLayer::make(100, 'auto', '#fff');

        $cell->addContentLayer(TextLayer::make(100, 'auto')->setText('内容')->setFont('', 12, '#000'));

        $this->assertSame(12, $cell->getHeight());
    }

    public function testGraphContainsFullRowsNotOnlyFirst(): void
    {
        // 无损：graph 携带全部行（旧库只输出首行 rowTemplate）
        $table = TableLayer::make(100, 40);
        $table->addRow($this->rowWithCell(100, 10, '#f00'));
        $table->addRow($this->rowWithCell(100, 30, '#0f0'));

        $graph = $table->graph();

        $this->assertCount(2, $graph['rows']);
        $this->assertSame('#f00', $graph['rows'][0]['cells'][0]['spec']['shape']['backgroundColor']);
        $this->assertSame('#0f0', $graph['rows'][1]['cells'][0]['spec']['shape']['backgroundColor']);
        $this->assertCount(1, $graph['rows'][0]['cells']);
    }

    public function testTableFromGraphRoundtrip(): void
    {
        $table = TableLayer::make(100, 40, '#fff')->setPriority(2);
        $row = TableRowLayer::make(100, 'auto');
        $cell = TableCellLayer::make(50, 20, '#eee');
        $cell->addContentLayer(TextLayer::make(50, 'auto')->setText('单元格')->setFont('', 10, '#000'));
        $row->addCell($cell);
        $table->addRow($row);

        $rebuilt = TableLayer::fromGraph($table->graph());

        $this->assertSame($table->graph(), $rebuilt->graph());
        $content = $rebuilt->getRows()[0]->getCells()[0]->getContentLayer();
        $this->assertInstanceOf(TextLayer::class, $content);
        $this->assertSame('单元格', $content->getText());
    }
}
