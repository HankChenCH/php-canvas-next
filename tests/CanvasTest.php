<?php

namespace HankChen\CanvasNext\Tests;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

class CanvasTest extends CanvasTestCase
{
    public function testMakeStoresDeclaredSize(): void
    {
        $canvas = Canvas::make(100, 80);

        $this->assertSame(100, $canvas->getWidth());
        $this->assertSame(80, $canvas->getHeight());
    }

    public function testLayersSortedByPriorityDescWithStableInsertion(): void
    {
        $lowA = ImageLayer::make(1, 1)->setPriority(1);
        $high = ImageLayer::make(1, 1)->setPriority(5);
        $lowB = ImageLayer::make(1, 1)->setPriority(1);

        $canvas = Canvas::make(10, 10, $lowA)->addLayer($high)->addLayer($lowB);

        $this->assertSame([$high, $lowA, $lowB], $canvas->getLayers());
    }

    public function testGraphIsRepeatableAndNonDestructive(): void
    {
        // 旧库 SplPriorityQueue 会被 graph()/render() 排干，新库为普通数组
        $canvas = Canvas::make(10, 10, ImageLayer::make(1, 1)->setPriority(3));

        $first = $canvas->graph();
        $this->assertSame($first, $canvas->graph());
        $this->assertCount(1, $canvas->getLayers());
    }

    public function testGraphContainsCanvasSizeAndLayerSpecs(): void
    {
        $canvas = Canvas::make(100, 80, ImageLayer::make(10, 10)->setPriority(2));

        $graph = $canvas->graph();

        $this->assertSame(['width' => 100, 'height' => 80], $graph['canvas']);
        $this->assertSame('ImageLayer', $graph['layers'][0]['type']);
        $this->assertSame(2, $graph['layers'][0]['priority']);
    }

    public function testFromGraphRoundtripWithNestedTable(): void
    {
        $row = TableRowLayer::make(100, 'auto');
        $cell = TableCellLayer::make(100, 20, '#eee');
        $cell->addContentLayer(TextLayer::make(100, 'auto')->setText('表格文本')->setFont('', 10, '#000'));
        $row->addCell($cell);

        $canvas = Canvas::make(100, 100,
            ImageLayer::make(100, 100, '#f00')->setImage('a.png')->setPriority(1),
            TextLayer::make(100, 30)->setText('标题')->setFont('', 12, '#000')->setPriority(3),
            QrCodeLayer::make(20, 20)->setText('https://example.com')->setPriority(2),
            TableLayer::make(100, 20, '#fff')->addRow($row)->setPriority(0)
        );

        $rebuilt = Canvas::fromGraph($canvas->graph());

        $this->assertSame($canvas->graph(), $rebuilt->graph());
        $this->assertCount(4, $rebuilt->getLayers());
    }

    public function testFromGraphRejectsUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Canvas::fromGraph([
            'canvas' => ['width' => 10, 'height' => 10],
            'layers' => [['type' => 'VideoLayer', 'priority' => 0, 'spec' => []]],
        ]);
    }
}
