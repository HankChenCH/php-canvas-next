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

    public function testExifRotatedImageDispatchesWindowsInObservedDimensions(): void
    {
        // EXIF orientation 6（顺时针 90°）：8x16 存储尺寸观察为 16x8——分派层内在
        // 尺寸返回观察方向尺寸（07 票裁决），cover 窗与位图后端解码归正位图同系
        // （intervention v4 autoOrientation 双驱动解码即归正）：scale = 1 整图 blit。
        // 修前按原始存储尺寸算窗 (0,6,8,4)，在后端归正坐标系越界错窗黑补，
        // 且 contain 宽高比随之倒置（v1 潜伏缺陷同根）
        $src = $this->twoBandExifJpeg(6);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(16, 8)->setImage($src);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([[$src, 0, 0, 16, 8, 0, 0, 16, 8]], $renderer->images);
    }

    /**
     * 8x16 上红下蓝、携带指定 EXIF orientation 的 JPEG 落盘（GD 编码后在 SOI 后
     * 注入最小 APP1 Exif 段：II 字节序 TIFF，IFD0 仅 Orientation 一项 SHORT；
     * 与 go-canvas image-renderer / php-canvas-image-renderer 同名助手同构）
     */
    private function twoBandExifJpeg(int $orientation): string
    {
        $image = imagecreatetruecolor(8, 16);
        $red = imagecolorallocate($image, 255, 0, 0);
        $blue = imagecolorallocate($image, 0, 0, 255);
        imagefilledrectangle($image, 0, 0, 7, 7, $red);
        imagefilledrectangle($image, 0, 8, 7, 15, $blue);
        $path = $this->tmpDir . '/exif-rotated.jpg';
        imagejpeg($image, $path, 100);
        imagedestroy($image);

        $ifd = pack('v', 1)
            . pack('v', 0x0112) . pack('v', 3) . pack('V', 1)
            . pack('v', $orientation) . pack('v', 0)
            . pack('V', 0);
        $payload = "Exif\x00\x00" . 'II' . pack('v', 42) . pack('V', 8) . $ifd;
        $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        $bytes = file_get_contents($path);
        file_put_contents($path, substr($bytes, 0, 2) . $app1 . substr($bytes, 2));

        return $path;
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
