<?php

namespace HankChen\CanvasNext\Tests\Renderer;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\CanvasBackground;
use HankChen\CanvasNext\Contracts\DownloaderInterface;
use HankChen\CanvasNext\Exception\MaterializeException;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Runtime\Cancellation;
use HankChen\CanvasNext\Runtime\NullCancellation;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;
use HankChen\CanvasNext\Tests\Support\FakeCancellation;
use HankChen\CanvasNext\Tests\Support\FakeDownloader;
use HankChen\CanvasNext\Tests\Support\RecordingTestRenderer;

/**
 * 画布背景铺装契约（ADR 0022 / spec §4）：
 * 插入点 = 根层循环之前（先色后图）；背景图画布级惰性物化（绘制分派前、失败即抛、
 * 产物整体丢弃）；渲染器禁 resolve() 预遍历；renderLayer 单图层入口不画背景
 */
class CanvasBackgroundPaintingTest extends CanvasTestCase
{
    private FakeDownloader $downloader;

    protected function setUp(): void
    {
        $this->removeDir($this->cacheDir('img_layers'));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cacheDir('img_layers'));
    }

    private function resolverWith(string|false $content): ResourceResolver
    {
        $this->downloader = new FakeDownloader($content);

        return new ResourceResolver($this->downloader);
    }

    /**
     * 坐标记录型后端：在 RecordingTestRenderer 之上追加绘制参数留痕
     */
    private function coordinateRenderer(ResourceResolver $resolver): RecordingTestRenderer
    {
        return new class($resolver) extends RecordingTestRenderer {
            /** @var list<array{0: int, 1: int, 2: int, 3: int, 4: ?string}> */
            public array $rects = [];

            /** @var list<array{0: string, 1: int, 2: int, 3: int, 4: int}> */
            public array $images = [];

            protected function drawRect(int $x, int $y, int $width, int $height, ?string $bgColor, array $border): void
            {
                $this->rects[] = [$x, $y, $width, $height, $bgColor];
                parent::drawRect($x, $y, $width, $height, $bgColor, $border);
            }

            protected function drawImage(string $src, int $x, int $y, int $width, int $height): void
            {
                $this->images[] = [$src, $x, $y, $width, $height];
                parent::drawImage($src, $x, $y, $width, $height);
            }
        };
    }

    public function testBackgroundColorPaintedBeforeRootLayerLoop(): void
    {
        // 先铺色再进根层循环：背景 rect 打头，图层盒其后；背景无边框（空 border）
        $renderer = $this->coordinateRenderer($this->resolverWith('should-not-be-used'));
        $canvas = Canvas::make(100, 80, ImageLayer::make(10, 10, '#f00'))
            ->setBackground(new CanvasBackground('#ffffff'));

        $renderer->render(new NullCancellation(), $canvas);

        $this->assertSame([[0, 0, 100, 80, '#ffffff']], array_slice($renderer->rects, 0, 1));
        $this->assertSame(['begin', 'rect', 'rect', 'end'], $renderer->records);
    }

    public function testBackgroundPaintedEvenWithoutLayers(): void
    {
        $renderer = $this->coordinateRenderer($this->resolverWith('should-not-be-used'));
        $canvas = Canvas::make(100, 80)->setBackground(new CanvasBackground('#ffffff'));

        $renderer->render(new NullCancellation(), $canvas);

        $this->assertSame([[0, 0, 100, 80, '#ffffff']], $renderer->rects);
        $this->assertEquals(['begin', 'rect', 'end'], $renderer->records);
    }

    public function testBackgroundImagePaintedAfterColorAtCanvasRect(): void
    {
        // 先色后图：背景图 cover 铺满画布矩形（drawImage 原语自带 cover 语义），物化结果路径下放
        $renderer = $this->coordinateRenderer($this->resolverWith('should-not-be-used'));
        $canvas = Canvas::make(100, 80)->setBackground(new CanvasBackground('#ffffff', '/tmp/local-bg.png'));

        $renderer->render(new NullCancellation(), $canvas);

        $this->assertSame([[0, 0, 100, 80, '#ffffff']], $renderer->rects);
        $this->assertSame([['/tmp/local-bg.png', 0, 0, 100, 80]], $renderer->images);
    }

    public function testNoBackgroundPaintsNothing(): void
    {
        // 无背景零开销：不铺色、不物化（存量渲染行为零变化）
        $renderer = $this->coordinateRenderer($this->resolverWith('should-not-be-used'));
        $canvas = Canvas::make(100, 80, ImageLayer::make(10, 10, '#f00'));

        $renderer->render(new NullCancellation(), $canvas);

        $this->assertSame([], $renderer->images);
        $this->assertCount(1, $renderer->rects); // 只有图层盒，无画布级背景矩形
        $this->assertSame([0, 0, 10, 10], array_slice($renderer->rects[0], 0, 4));
    }

    public function testBackgroundImageMaterializedAtRenderTimeWithoutPreTraversal(): void
    {
        // 画布级惰性物化（绘制分派前）：ctx 穿线到下载边界；resolve() 预遍历不发生
        $url = 'https://cdn.example.com/bg-' . uniqid() . '.png';
        $downloader = new FakeDownloader('fake-image-bytes');
        $spy = new NoPreTraversalResolverSpy($downloader);
        $renderer = $this->coordinateRenderer($spy);
        $ctx = new NullCancellation();
        $canvas = Canvas::make(100, 80)->setBackground(new CanvasBackground(null, $url));

        $renderer->render($ctx, $canvas);

        $this->assertSame([$url], $downloader->calls);
        $this->assertSame([$ctx], $downloader->ctxs);
        $this->assertSame(1, $spy->backgroundCalls);
        $this->assertSame([[$this->cacheDir('img_layers') . '/' . basename($url), 0, 0, 100, 80]], $renderer->images);
    }

    public function testBackgroundMaterializeFailureThrowsAndDropsProduct(): void
    {
        // 失败语义：物化失败即抛，渲染面随异常丢弃（end 永不触达）
        $renderer = $this->coordinateRenderer($this->resolverWith(false));
        $canvas = Canvas::make(100, 80)->setBackground(new CanvasBackground('#ffffff', 'https://cdn.example.com/missing-' . uniqid() . '.png'));

        try {
            $renderer->render(new NullCancellation(), $canvas);
            $this->fail('背景物化失败必须在绘制期抛出');
        } catch (MaterializeException $e) {
            $this->assertSame('resource_download_failed', $e->getErrorCode());
        }

        $this->assertNotContains('end', $renderer->records);
    }

    public function testBackgroundCheckpointHonoursCancellationBeforeDownload(): void
    {
        // 检查点沿用既有位置：物化前（下载发起前）轮询取消信号
        $ctx = new FakeCancellation(requested: true);
        $renderer = $this->coordinateRenderer($this->resolverWith('should-not-be-downloaded'));
        $canvas = Canvas::make(100, 80)->setBackground(new CanvasBackground(null, 'https://cdn.example.com/cancelled-' . uniqid() . '.png'));

        try {
            $renderer->render($ctx, $canvas);
            $this->fail('背景物化前检查点必须掐断');
        } catch (MaterializeException $e) {
            $this->assertSame('render_cancelled', $e->getErrorCode());
        }

        $this->assertSame([], $this->downloader->calls, '物化前已取消，不得发起下载');
    }

    public function testRenderLayerDoesNotPaintCanvasBackground(): void
    {
        // 单图层入口不画背景（无画布矩形语义）：同一画布的背景不进 renderLayer 产物
        $renderer = $this->coordinateRenderer($this->resolverWith('should-not-be-used'));
        $layer = ImageLayer::make(10, 10, '#f00');
        Canvas::make(100, 80, $layer)->setBackground(new CanvasBackground('#ffffff'));

        $renderer->renderLayer(new NullCancellation(), $layer);

        $this->assertSame([[0, 0, 10, 10, '#f00']], $renderer->rects);
    }
}

/**
 * 禁预遍历 spy（与 image-renderer 包像素测试同构）：resolve() 一旦被触达即失败
 */
final class NoPreTraversalResolverSpy extends ResourceResolver
{
    public int $backgroundCalls = 0;

    public function __construct(?DownloaderInterface $downloader = null)
    {
        parent::__construct($downloader);
    }

    public function resolve(Cancellation $ctx, Canvas $canvas): void
    {
        throw new \RuntimeException('render() 不应触发预遍历物化');
    }

    public function resolveBackground(Cancellation $ctx, string $image): string
    {
        $this->backgroundCalls++;

        return parent::resolveBackground($ctx, $image);
    }
}
