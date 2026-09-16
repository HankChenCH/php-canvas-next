<?php

namespace HankChen\CanvasNext\Tests\Layer;

use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

class QrCodeLayerTest extends CanvasTestCase
{
    public function testDeclaredHeightWinsWhenNotAuto(): void
    {
        $layer = QrCodeLayer::make(80, 40)->setText('https://example.com');

        $this->assertSame(40, $layer->getHeight());
    }

    public function testHeightFallsBackToWidth(): void
    {
        $layer = QrCodeLayer::make(60, 'auto')->setText('https://example.com');

        $this->assertSame(60, $layer->getHeight());
    }

    public function testGraphAlwaysCarriesValue(): void
    {
        // 无损：value 与是否已物化无关
        $layer = QrCodeLayer::make(60, 60);

        $this->assertSame('', $layer->graph()['data']['value']);

        $layer->setText('https://example.com/中文');
        $this->assertSame('https://example.com/中文', $layer->graph()['data']['value']);
    }

    public function testFromGraphRoundtrip(): void
    {
        $layer = QrCodeLayer::make(60, 60)->setText('https://example.com')->setPosition(2, 2);

        $rebuilt = QrCodeLayer::fromGraph($layer->graph());

        $this->assertSame($layer->graph(), $rebuilt->graph());
        $this->assertSame('https://example.com', $rebuilt->getText());
    }
}
