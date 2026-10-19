<?php

namespace HankChen\CanvasNext\Tests\Renderer;

use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Runtime\NullCancellation;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;
use HankChen\CanvasNext\Tests\Support\CoordinateRecordingRenderer;

/**
 * paintImage 全 fit 分派契约（ADR 0024 / spec §4）：绘制原语是双矩形 blit
 * （srcRect → dstRect 拉伸搬运），四档 fit 的几何全部在分派层算定——
 * cover（缺省/未知值降级）等比居中裁窗、fill 整图（与 cover 同门 dstRect，唯一差别在 srcRect）、
 * contain 收缩（ADR 0023 语义逐字节不动）、tile 可见块逐块 blit；
 * 内在尺寸读取为全 fit 前置（绘制分派点一次），读取失败统一退化 fill 形态
 * （srcRect 零哨兵 = 后端按解码实际尺寸取整图）。
 * 核心包只断言分派几何，像素点位在 php-canvas-image-renderer 包
 */
class ImageFitPaintingTest extends CanvasTestCase
{
    protected function setUp(): void
    {
        $this->initTmpDir('canvas-next-fit-test');
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

    public function testCoverDispatchesProportionalCenterCropWindow(): void
    {
        // 缺省 cover：分派点读内在尺寸算等比居中裁窗（40×40 方图进 100×50 横盒 → 中部横带），
        // dstRect = getImageOrigin + 内容宽高；图层盒背景 rect 照旧先画
        $src = $this->png('square.png', 40, 40);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 50)->setImage($src);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([[$src, 0, 10, 40, 20, 0, 0, 100, 50]], $renderer->images);
        $this->assertSame(['begin', 'rect', 'image', 'end'], $renderer->records);
    }

    public function testExplicitCoverWireAlsoCovers(): void
    {
        // wire 显式 cover（第三方手写 graph）：解码归一缺省，渲染走 cover 裁窗
        $src = $this->png('square.png', 40, 40);
        $graph = ImageLayer::make(100, 50)->setImage($src)->graph();
        $graph['spec']['fit'] = ImageLayer::FIT_COVER;

        $renderer = $this->renderer();
        $renderer->renderLayer(new NullCancellation(), ImageLayer::fromGraph($graph));

        $this->assertSame([[$src, 0, 10, 40, 20, 0, 0, 100, 50]], $renderer->images);
    }

    public function testUnknownFitValueDegradesToCover(): void
    {
        // 未知 fit 值渲染面按 cover 降级（白名单在渲染臂，不在解码门）；
        // fill/tile 已转正（ADR 0024），白名单外示例改用从未入册的值
        $src = $this->png('square.png', 40, 40);
        $graph = ImageLayer::make(100, 50)->setImage($src)->graph();
        $graph['spec']['fit'] = 'flip';
        $layer = ImageLayer::fromGraph($graph);

        $renderer = $this->renderer();
        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame('flip', $layer->getFit());
        $this->assertSame([[$src, 0, 10, 40, 20, 0, 0, 100, 50]], $renderer->images);
    }

    public function testContainBlitsWholeImageIntoShrunkRect(): void
    {
        // contain（ADR 0023 语义不动）：srcRect = 整图，dstRect = 收缩矩形（盒内居中）
        $src = $this->png('square.png', 40, 40);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 50)->setImage($src)->setFit(ImageLayer::FIT_CONTAIN);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([[$src, 0, 0, 40, 40, 25, 0, 50, 50]], $renderer->images);
    }

    public function testContainPlacesByAlignAndPadding(): void
    {
        // 左上对齐 + padding：收缩矩形贴内容盒原点（同门 align + padding 语义）
        $src = $this->png('wide.png', 200, 50);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 50)
            ->setImage($src)
            ->setFit(ImageLayer::FIT_CONTAIN)
            ->setHorizontalAlign('left')
            ->setVerticalAlign('top')
            ->setPadding(6, 8);

        $renderer->renderLayer(new NullCancellation(), $layer);

        // 内容盒 84×38 → 等比收缩 84×21（scale = min(84/200, 38/50) = 0.42）
        $this->assertSame([[$src, 0, 0, 200, 50, 8, 6, 84, 21]], $renderer->images);
    }

    public function testFillBlitsWholeImageIntoSameDstAsCover(): void
    {
        // fill：整图 srcRect + 与 cover 同门 dstRect（唯一差别在源矩形，不裁切不保比）
        $src = $this->png('square.png', 40, 40);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 50)->setImage($src)->setFit(ImageLayer::FIT_FILL);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([[$src, 0, 0, 40, 40, 0, 0, 100, 50]], $renderer->images);
    }

    public function testFillDstFollowsAlignAndPadding(): void
    {
        // fill 的 dst 与 cover 同门：getImageOrigin + 内容宽高（padding 内缩、对齐定origin）
        $src = $this->png('square.png', 40, 40);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 50)
            ->setImage($src)
            ->setFit(ImageLayer::FIT_FILL)
            ->setPadding(10);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([[$src, 0, 0, 40, 40, 10, 10, 80, 30]], $renderer->images);
    }

    public function testTileDispatchesVisibleBlocksInRowMajorOrder(): void
    {
        // tile：40×40 单元进 100×50 盒（center）→ 网格整体居中、右/下溢出半块截断，
        // 逐块以可见子矩形 blit（src/dst 同尺寸 1:1），裁切纯几何完成、原语零裁剪参数
        $src = $this->png('square.png', 40, 40);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 50)->setImage($src)->setFit(ImageLayer::FIT_TILE);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([
            [$src, 0, 0, 40, 40, 30, 5, 40, 40],
            [$src, 0, 0, 30, 40, 70, 5, 30, 40],
            [$src, 0, 0, 40, 5, 30, 45, 40, 5],
            [$src, 0, 0, 30, 5, 70, 45, 30, 5],
        ], $renderer->images);
    }

    public function testTileDstFollowsLayerPosition(): void
    {
        // dst 是绝对坐标（图层锚点偏移入账），src 不随位移；40×40 图铺满 40×40 盒恰一块
        $src = $this->png('square.png', 40, 40);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(40, 40)
            ->setImage($src)
            ->setFit(ImageLayer::FIT_TILE)
            ->setPosition(5, 7);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([[$src, 0, 0, 40, 40, 5, 7, 40, 40]], $renderer->images);
    }

    public function testIntrinsicSizeFailureDegradesAllFitsToFillForm(): void
    {
        // getimagesize 读取失败（损坏/非图文件）：白名单四档统一退化整图拉伸（fill 形态），
        // srcRect 走零哨兵（后端按解码实际尺寸取整图，真损坏图在后端解码抛、结局 parity）；
        // tile 也退化为单次 blit，不产生 N 块循环
        $src = $this->tmpDir . '/broken.png';
        file_put_contents($src, 'definitely-not-an-image');

        foreach ([null, ImageLayer::FIT_COVER, ImageLayer::FIT_CONTAIN, ImageLayer::FIT_FILL, ImageLayer::FIT_TILE, 'flip'] as $fit) {
            $renderer = $this->renderer();
            $layer = ImageLayer::make(100, 50)->setImage($src);
            if ($fit !== null) {
                $layer->setFit($fit);
            }

            $renderer->renderLayer(new NullCancellation(), $layer);

            $this->assertSame([[$src, 0, 0, 0, 0, 0, 0, 100, 50]], $renderer->images, "fit={$fit} 退化形态");
        }
    }

    public function testQrCodeBlitsWholeImageIntoInscribedSquare(): void
    {
        // QR 臂迁移（ADR 0015 内切正方形 + ADR 0024 blit）：产物恒正方形、目标恒正方形，
        // cover 窗 = 整图——srcRect 零哨兵整图 blit、零内在尺寸读取，quiet zone 语义不变
        $renderer = $this->renderer();
        $layer = QrCodeLayer::make(100, 50)->setPadding(5)->setText('https://example.com/qr-blit');

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertCount(1, $renderer->images);
        [$src, $sx, $sy, $sw, $sh, $dx, $dy, $dw, $dh] = $renderer->images[0];
        $this->assertNotSame('', $src);
        $this->assertSame([0, 0, 0, 0], [$sx, $sy, $sw, $sh], 'QR 整图 blit：srcRect 零哨兵');
        // 内容盒 90×40 → 内切边长 40，left/top 缺省锚定 padding 原点
        $this->assertSame([5, 5, 40, 40], [$dx, $dy, $dw, $dh]);
    }
}
