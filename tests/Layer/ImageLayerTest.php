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

    public function testFitFillTileWrittenLastInSpec(): void
    {
        // fill/tile 转正（ADR 0024）同落键纪律：值是透传字符串，wire 字节面零扩展
        $fill = ImageLayer::make(100, 100)->setImage('https://example.com/a.png')->setFit(ImageLayer::FIT_FILL);
        $tile = ImageLayer::make(100, 100)->setImage('https://example.com/a.png')->setFit(ImageLayer::FIT_TILE);

        $this->assertSame('fill', $fill->graph()['spec']['fit']);
        $this->assertSame('tile', $tile->graph()['spec']['fit']);
        $this->assertSame(['shape', 'align', 'position', 'fit'], array_keys($fill->graph()['spec']));
        $this->assertSame(['shape', 'align', 'position', 'fit'], array_keys($tile->graph()['spec']));
    }

    public function testFitRoundtripIdentity(): void
    {
        $layer = ImageLayer::make(30, 20)->setImage('https://example.com/a.png')->setFit(ImageLayer::FIT_CONTAIN);

        $rebuilt = ImageLayer::fromGraph($layer->graph());

        $this->assertSame('contain', $rebuilt->getFit());
        $this->assertSame($layer->graph(), $rebuilt->graph());
    }

    public function testFitFillTileRoundtripIdentity(): void
    {
        foreach ([ImageLayer::FIT_FILL, ImageLayer::FIT_TILE] as $fit) {
            $layer = ImageLayer::make(30, 20)->setImage('https://example.com/a.png')->setFit($fit);

            $rebuilt = ImageLayer::fromGraph($layer->graph());

            $this->assertSame($fit, $rebuilt->getFit());
            $this->assertSame($layer->graph(), $rebuilt->graph());
        }
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
        // 未知值契约面原样透传（往返恒等）；渲染面按 cover 降级属渲染臂职责。
        // fill/tile 已转正（ADR 0024），白名单外示例改用从未入册的值
        $graph = ImageLayer::make(10, 10)->setImage('x')->graph();
        $graph['spec']['fit'] = 'flip';

        $rebuilt = ImageLayer::fromGraph($graph);

        $this->assertSame('flip', $rebuilt->getFit());
        $this->assertSame('flip', $rebuilt->graph()['spec']['fit']);
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

    // ------------------------------------------------------------------
    // 裁剪形状 clip（ADR 0026）：wire 条件写键——缺省（矩形）整键不落、
    // 殿后 spec 末位 fit 之后（键序 shape/align/position/fit/clip）、
    // 显式 rect 归一缺省（cover 归一先例）、未知值宽容透传保往返恒等
    // ------------------------------------------------------------------

    public function testClipOmittedByDefault(): void
    {
        // 缺省 = 矩形不裁：整键不落，getClip() 回 null（缺省态字节面与无字段版本一致）
        $layer = ImageLayer::make(100, 100)->setImage('https://example.com/a.png');

        $this->assertNull($layer->getClip());
        $this->assertArrayNotHasKey('clip', $layer->graph()['spec']);
    }

    public function testClipCircleWrittenLastInSpecAfterFit(): void
    {
        // 条件写键 + 键序钉死：clip 殿后于 shape/align/position/fit（ADR 0026）
        $layer = ImageLayer::make(100, 100)
            ->setImage('https://example.com/a.png')
            ->setFit(ImageLayer::FIT_CONTAIN)
            ->setClip(ImageLayer::CLIP_CIRCLE);

        $spec = $layer->graph()['spec'];

        $this->assertSame('circle', $spec['clip']);
        $this->assertSame(['shape', 'align', 'position', 'fit', 'clip'], array_keys($spec));
    }

    public function testClipCircleWithoutFitStillWrittenLast(): void
    {
        // 未声明 fit 时 clip 同样殿后 spec 末位
        $layer = ImageLayer::make(100, 100)->setImage('https://example.com/a.png')->setClip(ImageLayer::CLIP_CIRCLE);

        $this->assertSame(['shape', 'align', 'position', 'clip'], array_keys($layer->graph()['spec']));
    }

    public function testExplicitRectWireNormalizedToKeyless(): void
    {
        // 解码宽容：第三方写入显式 rect 与缺省同归一（fit 显式 cover 归一先例），再序列化整键不落
        $graph = ImageLayer::make(10, 10)->setImage('x')->graph();
        $graph['spec']['clip'] = ImageLayer::CLIP_RECT;

        $rebuilt = ImageLayer::fromGraph($graph);

        $this->assertNull($rebuilt->getClip());
        $this->assertArrayNotHasKey('clip', $rebuilt->graph()['spec']);
        $this->assertNull(ImageLayer::make(10, 10)->setClip(ImageLayer::CLIP_RECT)->getClip());
    }

    public function testUnknownClipValuePassesThrough(): void
    {
        // 未知值契约面原样透传（往返恒等）；渲染面按矩形降级属渲染臂职责。
        // 'star' 是从未入册的任意未知串
        $graph = ImageLayer::make(10, 10)->setImage('x')->graph();
        $graph['spec']['clip'] = 'star';

        $rebuilt = ImageLayer::fromGraph($graph);

        $this->assertSame('star', $rebuilt->getClip());
        $this->assertSame('star', $rebuilt->graph()['spec']['clip']);
    }

    public function testClipRoundtripIdentity(): void
    {
        $layer = ImageLayer::make(30, 20)->setImage('https://example.com/a.png')->setClip(ImageLayer::CLIP_CIRCLE);

        $rebuilt = ImageLayer::fromGraph($layer->graph());

        $this->assertSame('circle', $rebuilt->getClip());
        $this->assertSame($layer->graph(), $rebuilt->graph());
    }

    public function testNonStringClipValueSilentlyDefaults(): void
    {
        // 非字符串静默归缺省（fit 同门读入门风）：isset + is_string 双门，不告警
        $graph = ImageLayer::make(10, 10)->setImage('x')->graph();
        $graph['spec']['clip'] = 42;

        $rebuilt = ImageLayer::fromGraph($graph);

        $this->assertNull($rebuilt->getClip());
        $this->assertArrayNotHasKey('clip', $rebuilt->graph()['spec']);
    }

    public function testClipSurvivesSrcRewriteAndExpressionMarking(): void
    {
        // clip 与 src/fit 正交：改图、打标均不扰动裁剪声明
        $layer = ImageLayer::make(10, 10)
            ->setClip(ImageLayer::CLIP_CIRCLE)
            ->setImage('https://example.com/a.png')
            ->setExpression('{{row.photo}}');

        $this->assertSame('circle', $layer->getClip());
        $this->assertSame('circle', ImageLayer::fromGraph($layer->graph())->getClip());

        $layer->setImage('https://example.com/b.png');
        $this->assertSame('circle', $layer->getClip());
    }

    // ------------------------------------------------------------------
    // 内切圆纯函数矩阵（ADR 0026）：直径 = min(内容区宽高)、盒内对齐定位
    // （alignedX/alignedY 同门公式）、非方盒恒正圆留透明边不变形为椭圆
    // ------------------------------------------------------------------

    public function testInscribedCircleRectMatrix(): void
    {
        // 方盒：内切圆 = 整个内容盒
        $this->assertSame([0, 0, 100, 100], ImageLayer::make(100, 100)->getInscribedCircleRect());

        // 非方盒（横盒 2:1）：直径取短边 50、垂直居中，左右留透明边
        $this->assertSame([25, 0, 50, 50], ImageLayer::make(100, 50)->getInscribedCircleRect());

        // 对齐分量：left/top 贴内容盒原点侧，right/bottom 贴对侧（恒正圆 side=50）
        $this->assertSame([0, 0, 50, 50], ImageLayer::make(100, 50)->setHorizontalAlign('left')->setVerticalAlign('top')->getInscribedCircleRect());
        $this->assertSame([50, 0, 50, 50], ImageLayer::make(100, 50)->setHorizontalAlign('right')->setVerticalAlign('bottom')->getInscribedCircleRect());

        // padding 收缩内容盒：直径 = min(内容宽高)（内边距即框外留白）
        $this->assertSame([10, 10, 80, 80], ImageLayer::make(100, 100)->setPadding(10)->getInscribedCircleRect());

        // 非对称 padding：center 按整盒中点、left/top 取各自 padding 分量（contain 矩阵同款期望）
        $asym = ImageLayer::make(100, 50)->setPadding(20, 10, 5, 0)->getInscribedCircleRect(); // 内容盒 90×25 → 边长 25
        $this->assertSame([37, 12, 25, 25], $asym);
        $this->assertSame([0, 12, 25, 25], ImageLayer::make(100, 50)->setPadding(20, 10, 5, 0)->setHorizontalAlign('left')->getInscribedCircleRect());
        $this->assertSame([37, 20, 25, 25], ImageLayer::make(100, 50)->setPadding(20, 10, 5, 0)->setVerticalAlign('top')->getInscribedCircleRect());
    }

    // ------------------------------------------------------------------
    // 圆周描边参数解析（ADR 0026 / CONTEXT.md 词条）：宽取四边已声明宽的
    // 最小值、颜色按 top→right→bottom→left 取首个非空边色、四边全未声明不描边
    // ------------------------------------------------------------------

    public function testCircleStrokeParsingMatrix(): void
    {
        // 四边全未声明：不描边
        $this->assertNull(ImageLayer::make(100, 100)->getCircleStroke());

        // 单边声明：宽与色都取该边
        $this->assertSame(['width' => 4, 'color' => '#f00'], ImageLayer::make(100, 100)->setBorderTop(4, '#f00')->getCircleStroke());
        $this->assertSame(['width' => 3, 'color' => '#0f0'], ImageLayer::make(100, 100)->setBorderRight(3, '#0f0')->getCircleStroke());

        // 多边声明：宽取最小值（top 2 < bottom 8），色按 top→right→bottom→left 取首个非空（top）
        $layer = ImageLayer::make(100, 100)->setBorderTop(2, '#f00')->setBorderBottom(8, '#00f');
        $this->assertSame(['width' => 2, 'color' => '#f00'], $layer->getCircleStroke());

        // 首个非空按序回退：top 未声明、right 5 蓝色、left 2 黄色 → 宽 2、色取 right 蓝
        $layer = ImageLayer::make(100, 100)->setBorderRight(5, '#00f')->setBorderLeft(2, '#ff0');
        $this->assertSame(['width' => 2, 'color' => '#00f'], $layer->getCircleStroke());

        // setBorder 全边同值：宽色一致
        $this->assertSame(['width' => 4, 'color' => '#abc'], ImageLayer::make(100, 100)->setBorder(4, '#abc')->getCircleStroke());
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

    /**
     * cover 等比居中裁窗矩阵（ADR 0024）：源图坐标系里与内容盒同宽高比的窗口、
     * 恒居中裁（对齐不进源窗），padding 经内容盒收缩间接收窄窗口；
     * 与 getContainRect 同门（box/scale 公式互为 max/min 镜像）
     */
    public function testCoverRectMatrix(): void
    {
        // 竖图（1:4）进横盒（2:1）：源窗贴满宽、垂直居中裁
        $this->assertSame([0, 175, 100, 50], ImageLayer::make(100, 50)->getCoverRect(100, 400));
        // 方图进横盒：源窗全宽、中部横带（sy = (40-20)/2 = 10）
        $this->assertSame([0, 10, 40, 20], ImageLayer::make(100, 50)->getCoverRect(40, 40));
        // 横图（4:1）进竖盒：源窗贴满高、水平居中裁（sx = int(87.5)）
        $this->assertSame([87, 0, 25, 50], ImageLayer::make(50, 100)->getCoverRect(200, 50));
        // 等比：源窗 = 整图，零裁切
        $this->assertSame([0, 0, 200, 100], ImageLayer::make(100, 50)->getCoverRect(200, 100));

        // padding 收缩内容盒 → 窗口随之收窄（80×30 内容盒 → 40×15 窗）
        $this->assertSame([0, 12, 40, 15], ImageLayer::make(100, 50)->setPadding(10)->getCoverRect(40, 40));
        // 对齐不影响源窗（cover 恒居中裁）
        $this->assertSame(
            ImageLayer::make(100, 50)->getCoverRect(40, 40),
            ImageLayer::make(100, 50)->setHorizontalAlign('left')->setVerticalAlign('top')->getCoverRect(40, 40)
        );
    }

    /**
     * tile 可见块矩形组矩阵（ADR 0024）：单元 = 内在尺寸 1:1，首块按对齐 + padding 锚定
     * （与 getImageOrigin 同一 aligned 公式），网格向右下无限延伸、绘制面止于网格 ∩ 内容盒，
     * 每块 [src, dst] 一一对应（src 为源图坐标可见子矩形、dst 为图层盒坐标）
     */
    public function testTileRectsMatrix(): void
    {
        // 小图 center：网格整体居中，右/下溢出半块截断（行主序）
        $this->assertSame([
            ['src' => [0, 0, 40, 40], 'dst' => [30, 5, 40, 40]],
            ['src' => [0, 0, 30, 40], 'dst' => [70, 5, 30, 40]],
            ['src' => [0, 0, 40, 5], 'dst' => [30, 45, 40, 5]],
            ['src' => [0, 0, 30, 5], 'dst' => [70, 45, 30, 5]],
        ], ImageLayer::make(100, 50)->getTileRects(40, 40));

        // 大图 center：锚点为负露出图片中部（可见块只有网格首块的中部子矩形）
        $this->assertSame(
            [['src' => [10, 15, 100, 50], 'dst' => [0, 0, 100, 50]]],
            ImageLayer::make(100, 50)->getTileRects(120, 80)
        );

        // align=right：首块贴右、锚点前空隙透明露下层，下方照常补行
        $this->assertSame([
            ['src' => [0, 0, 40, 40], 'dst' => [60, 0, 40, 40]],
            ['src' => [0, 0, 40, 10], 'dst' => [60, 40, 40, 10]],
        ], ImageLayer::make(100, 50)->setHorizontalAlign('right')->setVerticalAlign('top')->getTileRects(40, 40));

        // 非均匀 padding：内容盒 (0,20)-(90,45)，网格止于交集（末列 10px 窄条）
        $this->assertSame([
            ['src' => [0, 0, 40, 25], 'dst' => [0, 20, 40, 25]],
            ['src' => [0, 0, 40, 25], 'dst' => [40, 20, 40, 25]],
            ['src' => [0, 0, 10, 25], 'dst' => [80, 20, 10, 25]],
        ], ImageLayer::make(100, 50)->setPadding(20, 10, 5, 0)->setHorizontalAlign('left')->setVerticalAlign('top')->getTileRects(40, 40));

        // 非对称 padding + center：锚点按整盒中点（与 contain 同门公式，可落在内容盒起点之前），
        // 网格 ∩ 内容盒的可见块以 src 偏移修正（大图露中部：srcY = 45）
        $this->assertSame(
            [['src' => [0, 45, 90, 25], 'dst' => [0, 20, 90, 25]]],
            ImageLayer::make(100, 50)->setPadding(20, 10, 5, 0)->getTileRects(100, 100)
        );

        // 网格线恰好压内容盒底边：零高交集不出块
        $this->assertSame([
            ['src' => [0, 0, 40, 40], 'dst' => [0, 0, 40, 40]],
            ['src' => [0, 0, 40, 40], 'dst' => [40, 0, 40, 40]],
        ], ImageLayer::make(80, 40)->setHorizontalAlign('left')->setVerticalAlign('top')->getTileRects(40, 40));

        // 内容区被 padding 吃满：交集为空，零块
        $this->assertSame([], ImageLayer::make(40, 40)->setPadding(20)->getTileRects(40, 40));

        // 非法内在尺寸防线：≤0 步长会使循环不终止，返回空集（分派层已门控，
        // 此处兜公开 API 第三方直调——Go TileRects 同款防御）
        $this->assertSame([], ImageLayer::make(100, 50)->getTileRects(0, 40));
        $this->assertSame([], ImageLayer::make(100, 50)->getTileRects(40, 0));
        $this->assertSame([], ImageLayer::make(100, 50)->getTileRects(-40, 40));
    }
}
