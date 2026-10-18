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

    public function testAutoWidthStaysNoop(): void
    {
        // autoWidth 仅 TextLayer 有义：Image 自然尺寸需物化资源才可知（ADR 0005
        // 物化红线，物化不前移到布局期），宽度求值不覆盖，flag 维持无义
        $layer = ImageLayer::make('auto', 40)->setImage('https://example.com/a.png');

        $this->assertSame(0, $layer->getWidth());
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

    public function testFitOmittedByDefault(): void
    {
        // fit 缺省 = cover：整键不落，getFit() 回 null（缺省态字节面与无字段版本一致）
        $layer = ImageLayer::make(100, 100)->setImage('https://example.com/a.png');

        $this->assertNull($layer->getFit());
        $this->assertArrayNotHasKey('fit', $layer->graph()['spec']);
    }

    public function testFitContainWrittenLastInSpec(): void
    {
        // 条件写键 + 键序钉死：仅 fit≠cover 落键，殿后于 shape/align/position（spec §3）
        $layer = ImageLayer::make(100, 100)->setImage('https://example.com/a.png')->setFit(ImageLayer::FIT_CONTAIN);

        $spec = $layer->graph()['spec'];

        $this->assertSame('contain', $spec['fit']);
        $this->assertSame(['shape', 'align', 'position', 'fit'], array_keys($spec));
    }

    public function testFitRoundtripIdentity(): void
    {
        $layer = ImageLayer::make(30, 20)->setImage('https://example.com/a.png')->setFit(ImageLayer::FIT_CONTAIN);

        $rebuilt = ImageLayer::fromGraph($layer->graph());

        $this->assertSame('contain', $rebuilt->getFit());
        $this->assertSame($layer->graph(), $rebuilt->graph());
    }

    public function testExplicitCoverWireNormalizedToKeyless(): void
    {
        // 解码宽容：第三方写入显式 cover 与缺省同归一（name="" 先例），再序列化整键不落
        $graph = ImageLayer::make(10, 10)->setImage('x')->graph();
        $graph['spec']['fit'] = ImageLayer::FIT_COVER;

        $rebuilt = ImageLayer::fromGraph($graph);

        $this->assertNull($rebuilt->getFit());
        $this->assertArrayNotHasKey('fit', $rebuilt->graph()['spec']);
    }

    public function testUnknownFitValuePassesThrough(): void
    {
        // 未知值契约面原样透传（往返恒等）；渲染面按 cover 降级属渲染臂职责
        $graph = ImageLayer::make(10, 10)->setImage('x')->graph();
        $graph['spec']['fit'] = 'fill';

        $rebuilt = ImageLayer::fromGraph($graph);

        $this->assertSame('fill', $rebuilt->getFit());
        $this->assertSame('fill', $rebuilt->graph()['spec']['fit']);
    }

    public function testFitSurvivesSrcRewriteAndExpressionMarking(): void
    {
        // fit 与 src 正交：改图、打标、解除标记均不扰动适配声明
        $layer = ImageLayer::make(10, 10)
            ->setFit(ImageLayer::FIT_CONTAIN)
            ->setImage('https://example.com/a.png')
            ->setExpression('{{row.photo}}');

        $this->assertSame('contain', $layer->getFit());
        $this->assertSame('contain', ImageLayer::fromGraph($layer->graph())->getFit());

        $layer->setImage('https://example.com/b.png');
        $this->assertSame('contain', $layer->getFit());
    }

    /**
     * contain 收缩矩形纯计算矩阵（ADR 0023）：等比收缩长边贴内容盒 + align/padding 放置，
     * 公式镜像 getImageOrigin 同门（center 取整盒中点、right/bottom 贴整盒对边）
     */
    public function testContainRectMatrix(): void
    {
        // 方图进横盒：高贴边、水平居中
        $this->assertSame([25, 0, 50, 50], ImageLayer::make(100, 50)->getContainRect(40, 40));
        // 4:1 宽图进横盒：宽贴边、垂直居中（y 取 int(12.5)）
        $this->assertSame([0, 12, 100, 25], ImageLayer::make(100, 50)->getContainRect(200, 50));
        // 1:4 高图进横盒：高贴边、宽收缩（w 取 int(12.5)，居中按收缩后宽）
        $this->assertSame([44, 0, 12, 50], ImageLayer::make(100, 50)->getContainRect(100, 400));

        // 对齐分量：left/top 贴内容盒原点侧，right/bottom 贴对侧
        $this->assertSame([0, 0, 100, 25], ImageLayer::make(100, 50)->setHorizontalAlign('left')->setVerticalAlign('top')->getContainRect(200, 50));
        $this->assertSame([0, 25, 100, 25], ImageLayer::make(100, 50)->setHorizontalAlign('left')->setVerticalAlign('bottom')->getContainRect(200, 50));

        // padding：收缩在内容盒（去 padding）上进行
        $this->assertSame([35, 10, 30, 30], ImageLayer::make(100, 50)->setPadding(10)->getContainRect(40, 40));
        // 非对称 padding：center 仍按整盒中点（同门公式），left/top 取各自 padding 分量
        $asym = ImageLayer::make(100, 50)->setPadding(20, 10, 5, 0)->getContainRect(100, 100); // 内容盒 90×25 → 25×25
        $this->assertSame([37, 12, 25, 25], $asym);
        $this->assertSame([0, 12, 25, 25], ImageLayer::make(100, 50)->setPadding(20, 10, 5, 0)->setHorizontalAlign('left')->getContainRect(100, 100));
        $this->assertSame([37, 20, 25, 25], ImageLayer::make(100, 50)->setPadding(20, 10, 5, 0)->setVerticalAlign('top')->getContainRect(100, 100));
    }
}
