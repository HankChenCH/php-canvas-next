<?php

namespace HankChen\CanvasNext\Tests;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\CanvasBackground;
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

    // ------------------------------------------------------------------
    // 画布背景 wire（ADR 0022 / spec §3）：条件写键 + 往返恒等
    // ------------------------------------------------------------------

    public function testCanvasSectionWithoutBackgroundKeepsLegacyShape(): void
    {
        // 两分量皆空/未设置 → background 整键不落：canvas 段与现状逐字节一致（name/visible 先例）
        $plain = Canvas::make(100, 80);
        $this->assertSame(['width' => 100, 'height' => 80], $plain->graph()['canvas']);

        $emptyComponents = Canvas::make(100, 80)->setBackground(new CanvasBackground('', ''));
        $this->assertSame(['width' => 100, 'height' => 80], $emptyComponents->graph()['canvas']);

        $nullBackground = Canvas::make(100, 80)->setBackground(null);
        $this->assertSame(['width' => 100, 'height' => 80], $nullBackground->graph()['canvas']);
    }

    public function testBackgroundKeysWrittenConditionallyInPinnedOrder(): void
    {
        // 落键仅非空分量落键；键序钉死：canvas{width,height,background} / background{color,image}
        $both = Canvas::make(100, 80)
            ->setBackground(new CanvasBackground('#ffffff', 'https://cdn.example.com/bg.png'));
        $this->assertSame([
            'width' => 100,
            'height' => 80,
            'background' => ['color' => '#ffffff', 'image' => 'https://cdn.example.com/bg.png'],
        ], $both->graph()['canvas']);

        $colorOnly = Canvas::make(100, 80)->setBackground(new CanvasBackground('#ffffff'));
        $this->assertSame([
            'width' => 100,
            'height' => 80,
            'background' => ['color' => '#ffffff'],
        ], $colorOnly->graph()['canvas']);

        $imageOnly = Canvas::make(100, 80)->setBackground(new CanvasBackground(null, 'bg.png'));
        $this->assertSame([
            'width' => 100,
            'height' => 80,
            'background' => ['image' => 'bg.png'],
        ], $imageOnly->graph()['canvas']);
    }

    public function testBackgroundRoundtripIdentityOnBothStates(): void
    {
        // 落键态：decode → encode 键级恒等，分量读回一致
        $withBackground = Canvas::make(100, 80, ImageLayer::make(10, 10, '#f00'))
            ->setBackground(new CanvasBackground('#ffffff', 'https://cdn.example.com/bg.png'));
        $rebuilt = Canvas::fromGraph($withBackground->graph());

        $this->assertSame($withBackground->graph(), $rebuilt->graph());
        $this->assertSame('#ffffff', $rebuilt->getBackground()?->getColor());
        $this->assertSame('https://cdn.example.com/bg.png', $rebuilt->getBackground()?->getImage());

        // 不落键态：宽容读缺键回填空，往返同样恒等
        $withoutBackground = Canvas::fromGraph([
            'canvas' => ['width' => 10, 'height' => 10],
            'layers' => [],
        ]);
        $this->assertNull($withoutBackground->getBackground());
        $this->assertSame($withoutBackground->graph(), Canvas::fromGraph($withoutBackground->graph())->graph());
    }
}
