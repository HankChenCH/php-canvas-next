<?php

namespace HankChen\CanvasNext\Tests\Renderer;

use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Runtime\NullCancellation;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;
use HankChen\CanvasNext\Tests\Support\CoordinateRecordingRenderer;
use HankChen\CanvasNext\Tests\Support\RecordingTestRenderer;

/**
 * paintImage 圆形档分派契约（ADR 0026）：clip=circle 走三步——椭圆背景填充、
 * 内容按 fit 绘出后遮罩 blit（掩码矩形 = 内切圆矩形，fit 几何照旧）、
 * 椭圆周描边；矩形盒 drawRect/drawImage 路径不参与（整层渲染面裁进圆）。
 * 未知 clip 值与后端缺形状能力口降级矩形（fit 未知值纪律同门）；
 * 内在尺寸不可用退化 fill 形态再裁圆（ADR 0024 纪律）。
 * 核心包只断言分派几何，像素点位在 php-canvas-image-renderer 包
 */
class ImageClipPaintingTest extends CanvasTestCase
{
    protected function setUp(): void
    {
        $this->initTmpDir('canvas-next-clip-test');
    }

    protected function tearDown(): void
    {
        $this->cleanupTmpDir();
    }

    private function renderer(): CoordinateRecordingRenderer
    {
        return new CoordinateRecordingRenderer(new ResourceResolver());
    }

    /** 已知内在尺寸的合法 PNG 落盘（基类助手），返回路径 */
    private function png(string $name, int $width, int $height): string
    {
        return $this->writePng($this->tmpDir, $name, $width, $height);
    }

    public function testCircleDispatchesThreeStepsWithoutRect(): void
    {
        // 圆形档三步：椭圆背景填充 → 遮罩 blit → 椭圆周描边；矩形盒 rect 原语不调用
        // （背景进圆、边框成环）。方盒 100×100：内切圆矩形 = 整盒
        $src = $this->png('square.png', 40, 40);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 100, '#ffffff')
            ->setImage($src)
            ->setBorder(6, '#f00')
            ->setClip(ImageLayer::CLIP_CIRCLE);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame(['begin', 'ellipse', 'masked', 'ellipse', 'end'], $renderer->records);
        $this->assertSame([
            [0, 0, 100, 100, '#ffffff', null],
            [0, 0, 100, 100, null, ['width' => 6, 'color' => '#f00']],
        ], $renderer->ellipses);
        // fit 几何照旧（cover：40×40 方图进方盒零裁切），掩码矩形 = 内切圆矩形
        $this->assertSame([[$src, 0, 0, 40, 40, 0, 0, 100, 100, 0, 0, 100, 100]], $renderer->maskedImages);
        $this->assertSame([], $renderer->rects);
        $this->assertSame([], $renderer->images);
    }

    public function testNonSquareBoxKeepsPerfectCircleGeometry(): void
    {
        // 非方盒 100×50：内切圆矩形 [25,0,50,50]（恒正圆），fit 的 dstRect 仍是内容盒
        $src = $this->png('square.png', 40, 40);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 50, '#0f0')->setImage($src)->setClip(ImageLayer::CLIP_CIRCLE);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([[25, 0, 50, 50, '#0f0', null]], $renderer->ellipses);
        // cover：40×40 方图进 100×50 内容盒 → 源窗中部横带 (0,10,40,20)、dst 全内容盒
        $this->assertSame([[$src, 0, 10, 40, 20, 0, 0, 100, 50, 25, 0, 50, 50]], $renderer->maskedImages);
    }

    public function testCircleWithoutImageStillFillsAndStrokes(): void
    {
        // 无图：背景圆 + 描边环两步，零 blit
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 100, '#fff')
            ->setBorder(2, '#00f')
            ->setClip(ImageLayer::CLIP_CIRCLE);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame(['begin', 'ellipse', 'ellipse', 'end'], $renderer->records);
        $this->assertSame([0, 0, 100, 100, '#fff', null], $renderer->ellipses[0]);
        $this->assertSame([0, 0, 100, 100, null, ['width' => 2, 'color' => '#00f']], $renderer->ellipses[1]);
        $this->assertSame([], $renderer->maskedImages);
    }

    public function testCircleWithoutDeclaredBorderSkipsStrokeEllipse(): void
    {
        // 四边全未声明不描边：只两步（背景圆 + 遮罩 blit）
        $src = $this->png('square.png', 40, 40);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 100, '#fff')->setImage($src)->setClip(ImageLayer::CLIP_CIRCLE);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame(['begin', 'ellipse', 'masked', 'end'], $renderer->records);
        $this->assertCount(1, $renderer->ellipses);
    }

    public function testCircleStrokeParamsFollowParsingRule(): void
    {
        // 描边参数解析规则进分派：宽取四边已声明最小值（2 < 8）、色按 top→right→bottom→left 首个非空（top）
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 100, '#fff')
            ->setBorderTop(2, '#f00')
            ->setBorderBottom(8, '#00f')
            ->setClip(ImageLayer::CLIP_CIRCLE);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([0, 0, 100, 100, null, ['width' => 2, 'color' => '#f00']], $renderer->ellipses[1]);
    }

    public function testCircleAllFitModesDispatchMaskedBlits(): void
    {
        // circle × fit 正交（先绘后裁）：contain/tile/fill 各档几何照旧，只换成遮罩 blit 形态
        $src = $this->png('square.png', 40, 40);

        // contain：40×20 宽图进 100×100 盒 → 等比放大 100×50 垂直居中（ADR 0023 几何不动）
        $wide = $this->png('wide.png', 40, 20);
        $contain = $this->renderer();
        $contain->renderLayer(new NullCancellation(), ImageLayer::make(100, 100)->setImage($wide)->setFit(ImageLayer::FIT_CONTAIN)->setClip(ImageLayer::CLIP_CIRCLE));
        $this->assertSame([[$wide, 0, 0, 40, 20, 0, 25, 100, 50, 0, 0, 100, 100]], $contain->maskedImages);

        $fill = $this->renderer();
        $fill->renderLayer(new NullCancellation(), ImageLayer::make(100, 100)->setImage($src)->setFit(ImageLayer::FIT_FILL)->setClip(ImageLayer::CLIP_CIRCLE));
        $this->assertSame([[$src, 0, 0, 40, 40, 0, 0, 100, 100, 0, 0, 100, 100]], $fill->maskedImages);

        // tile：可见块逐块遮罩 blit（掩码矩形同圆；40×40 单元 center 锚定 (30,30)，右/下溢出截断）
        $tile = $this->renderer();
        $tile->renderLayer(new NullCancellation(), ImageLayer::make(100, 100)->setImage($src)->setFit(ImageLayer::FIT_TILE)->setClip(ImageLayer::CLIP_CIRCLE));
        $this->assertSame([
            [$src, 0, 0, 40, 40, 30, 30, 40, 40, 0, 0, 100, 100],
            [$src, 0, 0, 30, 40, 70, 30, 30, 40, 0, 0, 100, 100],
            [$src, 0, 0, 40, 30, 30, 70, 40, 30, 0, 0, 100, 100],
            [$src, 0, 0, 30, 30, 70, 70, 30, 30, 0, 0, 100, 100],
        ], $tile->maskedImages);
    }

    public function testCircleIntrinsicSizeFailureDegradesToFillFormMasked(): void
    {
        // 内在尺寸不可用（ADR 0024 纪律）：退化整图拉伸（fill 形态、srcRect 零哨兵）
        // 再裁圆——掩码 blit 原语形态，遮罩几何不变
        $src = $this->tmpDir . '/broken.png';
        file_put_contents($src, 'definitely-not-an-image');

        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 50)->setImage($src)->setClip(ImageLayer::CLIP_CIRCLE);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([[$src, 0, 0, 0, 0, 0, 0, 100, 50, 25, 0, 50, 50]], $renderer->maskedImages);
    }

    public function testCircleWithDegenerateContentAreaDrawsNothing(): void
    {
        // 内容区被 padding 吃满：内切圆边长 ≤0，零几何零绘制
        $src = $this->png('square.png', 40, 40);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(40, 40, '#fff')
            ->setImage($src)
            ->setPadding(20)
            ->setBorder(2, '#f00')
            ->setClip(ImageLayer::CLIP_CIRCLE);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame(['begin', 'end'], $renderer->records);
        $this->assertSame([], $renderer->ellipses);
    }

    public function testUnknownClipValueDegradesToRectangle(): void
    {
        // 未知 clip 值渲染面按矩形降级（白名单在渲染臂，不在解码门）；wire 透传不受扰
        $src = $this->png('square.png', 40, 40);
        $graph = ImageLayer::make(100, 50, '#fff')->setImage($src)->graph();
        $graph['spec']['clip'] = 'star';
        $layer = ImageLayer::fromGraph($graph);

        $renderer = $this->renderer();
        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame('star', $layer->getClip());
        $this->assertSame(['begin', 'rect', 'image', 'end'], $renderer->records);
        $this->assertSame([], $renderer->ellipses);
        $this->assertSame([[$src, 0, 10, 40, 20, 0, 0, 100, 50]], $renderer->images);
    }

    public function testClipCircleDegradesToRectangleWhenBackendLacksShapeCapability(): void
    {
        // 后端缺形状能力口（未实现 ShapeCapableRenderer）降级矩形路径：可选能力口契约
        $src = $this->png('square.png', 40, 40);
        $renderer = new RecordingTestRenderer(new ResourceResolver());
        $layer = ImageLayer::make(100, 50, '#fff')->setImage($src)->setClip(ImageLayer::CLIP_CIRCLE);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame(['begin', 'rect', 'image', 'end'], $renderer->records);
    }
}
