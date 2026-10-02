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

    public function testAutoWidthStaysNoop(): void
    {
        // autoWidth 仅 TextLayer 有义：QrCode 无自然宽概念（任意尺寸合法），
        // 宽度求值不覆盖、按宽兜底正方形取的仍是声明宽，flag 维持无义
        $layer = QrCodeLayer::make('auto', 'auto')->setText('https://example.com');

        $this->assertSame(0, $layer->getWidth());
        $this->assertSame(0, $layer->getHeight());
        $this->assertTrue($layer->graph()['spec']['shape']['autoWidth']);
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
