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

    public function testContentSideIsMinOfContentDims(): void
    {
        // 内切正方形边长 = min(内容区宽, 内容区高)：padding 内缩后向零截断
        $layer = QrCodeLayer::make(80, 40)->setPadding(5, 7)->setText('https://example.com');

        $this->assertSame(30, $layer->getContentSide());
    }

    public function testContentSidePassesThroughNegative(): void
    {
        // padding 吃满内容区：内容区负值直通（与 Image 家族一致，intval 不钳零），
        // 绘制端 drawImage ≤0 防护自然只画盒
        $layer = QrCodeLayer::make(40, 40)->setPadding(25)->setText('https://example.com');

        $this->assertSame(-10, $layer->getContentSide());
    }

    public function testQrOriginDefaultLeftTopAnchorsPadding(): void
    {
        // left/top 缺省（PHP 无覆写）：锚定 padding 原点，quiet zone = padding
        $layer = QrCodeLayer::make(100, 100)->setPadding(20)->setText('https://example.com');

        $this->assertSame([20, 20], $layer->getQrOrigin());
    }

    public function testQrOriginCenterCentersSquareInBox(): void
    {
        // center 镜像 getImageOrigin：在整盒内居中，padding 只缩内容尺寸
        $layer = QrCodeLayer::make(100, 60)->setText('https://example.com')
            ->setHorizontalAlign('center')->setVerticalAlign('center');

        $this->assertSame(60, $layer->getContentSide());
        $this->assertSame([20, 0], $layer->getQrOrigin());
    }

    public function testQrOriginRightBottomHugsEdge(): void
    {
        // right/bottom 臂 = 盒尺寸 - 边长（镜像 getImageOrigin：贴盒边，
        // padding 全落对侧）；side = min(80, 60) = 60
        $layer = QrCodeLayer::make(100, 80)->setPadding(10)->setText('https://example.com')
            ->setHorizontalAlign('right')->setVerticalAlign('bottom');

        $this->assertSame(60, $layer->getContentSide());
        $this->assertSame([40, 20], $layer->getQrOrigin());
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
