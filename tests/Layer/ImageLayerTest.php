<?php

namespace HankChen\CanvasNext\Tests\Layer;

use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

class ImageLayerTest extends CanvasTestCase
{
    public function testSetImageStoresRawValueOnly(): void
    {
        // 新架构下 setter 不做任何下载 I/O，资源物化属于渲染前的 Resolver
        $layer = ImageLayer::make(100, 100)->setImage('https://example.com/a.png');

        $this->assertSame('https://example.com/a.png', $layer->getImage());
    }

    public function testEmptyImageValueIsIgnored(): void
    {
        $layer = ImageLayer::make(100, 100)->setImage('');

        $this->assertNull($layer->getImage());
        $this->assertNull($layer->graph()['data']['value']);
    }

    public function testResolvedSrcPreferredOverRaw(): void
    {
        $layer = ImageLayer::make(100, 100)->setImage('https://example.com/a.png');
        $this->assertSame('https://example.com/a.png', $layer->getResolvedSrc());

        $layer->setResolvedSrc('/tmp/local-a.png');
        $this->assertSame('/tmp/local-a.png', $layer->getResolvedSrc());
    }

    public function testGraphKeepsRawValue(): void
    {
        $graph = ImageLayer::make(10, 10)->setImage('https://example.com/a.png')->graph();

        $this->assertSame('ImageLayer', $graph['type']);
        $this->assertSame(['valueType' => 'StaticValue', 'value' => 'https://example.com/a.png'], $graph['data']);
    }

    public function testFromGraphRoundtrip(): void
    {
        $layer = ImageLayer::make(30, 20, '#fff')
            ->setImage('https://example.com/a.png')
            ->setPosition(1, 2);

        $rebuilt = ImageLayer::fromGraph($layer->graph());

        $this->assertSame($layer->graph(), $rebuilt->graph());
        $this->assertSame('https://example.com/a.png', $rebuilt->getImage());
    }
}
