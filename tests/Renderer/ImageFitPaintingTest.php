<?php

namespace HankChen\CanvasNext\Tests\Renderer;

use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Runtime\NullCancellation;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;
use HankChen\CanvasNext\Tests\Support\CoordinateRecordingRenderer;

/**
 * paintImage contain 分派契约（ADR 0023 / spec §4）：绘制分派层按物化后源图内在比例
 * 把目标矩形收缩成等比矩形再调 drawImage——原语签名与 cover 语义一字不动；
 * cover 缺省（含未知值降级、显式 cover、内在尺寸读取失败）路径零回归。
 * 核心包只断言分派几何，像素点位在 php-canvas-image-renderer 包
 */
class ImageFitPaintingTest extends CanvasTestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/canvas-next-fit-test-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->tmpDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->tmpDir . '/' . $entry);
            }
        }
        @rmdir($this->tmpDir);
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /**
     * 生成已知内在尺寸的合法 PNG（8-bit RGB）落盘，返回路径
     * （getimagesize 只认头块，但 IDAT 一并备成合法，测试文件真实可解码）
     */
    private function writePng(string $name, int $width, int $height): string
    {
        $path = $this->tmpDir . '/' . $name;
        $raw = str_repeat("\x00" . str_repeat("\x7f\x80\x81", $width), $height);
        file_put_contents($path, "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', pack('N2C5', $width, $height, 8, 2, 0, 0, 0))
            . $this->pngChunk('IDAT', gzcompress($raw))
            . $this->pngChunk('IEND', ''));

        return $path;
    }

    private function renderer(): CoordinateRecordingRenderer
    {
        return new CoordinateRecordingRenderer(new ResourceResolver());
    }

    public function testContainShrinksRectToIntrinsicRatio(): void
    {
        // 方图（1:1）进 100×50 横盒：收缩为 50×50、盒内居中；图层盒背景 rect 照旧先画
        $src = $this->writePng('square.png', 40, 40);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 50)->setImage($src)->setFit(ImageLayer::FIT_CONTAIN);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([[$src, 25, 0, 50, 50]], $renderer->images);
        $this->assertSame(['begin', 'rect', 'image', 'end'], $renderer->records);
    }

    public function testContainPlacesByAlignAndPadding(): void
    {
        // 左上对齐 + padding：收缩矩形贴内容盒原点（同门 align + padding 语义）
        $src = $this->writePng('wide.png', 200, 50);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 50)
            ->setImage($src)
            ->setFit(ImageLayer::FIT_CONTAIN)
            ->setHorizontalAlign('left')
            ->setVerticalAlign('top')
            ->setPadding(6, 8);

        $renderer->renderLayer(new NullCancellation(), $layer);

        // 内容盒 84×38 → 等比收缩 84×21（scale = min(84/200, 38/50) = 0.42）
        $this->assertSame([[$src, 8, 6, 84, 21]], $renderer->images);
    }

    public function testCoverPathUnchangedByDefault(): void
    {
        // 缺省 cover：内容盒整铺，分派几何与无 fit 版本逐字节一致
        $src = $this->writePng('square.png', 40, 40);
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 50)->setImage($src);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([[$src, 0, 0, 100, 50]], $renderer->images);
    }

    public function testExplicitCoverWireAlsoCovers(): void
    {
        // wire 显式 cover（第三方手写 graph）：解码归一缺省，渲染走 cover
        $src = $this->writePng('square.png', 40, 40);
        $graph = ImageLayer::make(100, 50)->setImage($src)->graph();
        $graph['spec']['fit'] = ImageLayer::FIT_COVER;

        $renderer = $this->renderer();
        $renderer->renderLayer(new NullCancellation(), ImageLayer::fromGraph($graph));

        $this->assertSame([[$src, 0, 0, 100, 50]], $renderer->images);
    }

    public function testUnknownFitValueDegradesToCover(): void
    {
        // 未知 fit 值渲染面按 cover 降级（白名单在渲染臂，不在解码门）
        $src = $this->writePng('square.png', 40, 40);
        $graph = ImageLayer::make(100, 50)->setImage($src)->graph();
        $graph['spec']['fit'] = 'fill';
        $layer = ImageLayer::fromGraph($graph);

        $renderer = $this->renderer();
        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame('fill', $layer->getFit());
        $this->assertSame([[$src, 0, 0, 100, 50]], $renderer->images);
    }

    public function testIntrinsicSizeReadFailureFallsBackToCover(): void
    {
        // getimagesize 读取失败（损坏/非图文件）按 cover 走：损坏图不因 fit 崩渲染
        $src = $this->tmpDir . '/broken.png';
        file_put_contents($src, 'definitely-not-an-image');
        $renderer = $this->renderer();
        $layer = ImageLayer::make(100, 50)->setImage($src)->setFit(ImageLayer::FIT_CONTAIN);

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([[$src, 0, 0, 100, 50]], $renderer->images);
    }
}
