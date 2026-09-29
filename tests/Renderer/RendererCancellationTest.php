<?php

namespace HankChen\CanvasNext\Tests\Renderer;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Exception\MaterializeException;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Runtime\CancellationSource;
use HankChen\CanvasNext\Runtime\CompositeCancellation;
use HankChen\CanvasNext\Runtime\NullCancellation;
use HankChen\CanvasNext\Runtime\TimeoutCancellation;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;
use HankChen\CanvasNext\Tests\Support\FakeCancellation;
use HankChen\CanvasNext\Tests\Support\FakeDownloader;
use HankChen\CanvasNext\Tests\Support\RecordingTestRenderer;
use RuntimeException;

/**
 * 渲染期控制面契约（render-cancellation 工票 02，ADR 0008）：
 * ctx 显式首参穿线 + 四处检查点（根层循环/表行循环/物化前/下载发起前）+ 错误面两 code
 *
 * 检查点位置用"绘制中途武装"驱动：FakeCancellation 的 requested 在第 N 个
 * drawRect 后翻开，其后第一个检查点必须掐断——既证明检查点存在，也证明其位置
 */
class RendererCancellationTest extends CanvasTestCase
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

    private function resolver(): ResourceResolver
    {
        $this->downloader = new FakeDownloader('fake-image-bytes');

        return new ResourceResolver($this->downloader);
    }

    public function testCancelledSourceAbortsRenderWithoutProduct(): void
    {
        $reason = new RuntimeException('客户端断连');
        $source = new CancellationSource();
        $source->cancel($reason);

        $renderer = new RecordingTestRenderer($this->resolver());

        try {
            $renderer->render($source->getCancellation(), Canvas::make(10, 10, ImageLayer::make(10, 10, '#f00')));
            $this->fail('取消后 render 必须抛错');
        } catch (MaterializeException $e) {
            $this->assertSame('render_cancelled', $e->getErrorCode());
            $this->assertSame($reason, $e->getPrevious());
        }

        // 产物整体丢弃：begin 已开的面随异常丢弃，end 永不触达，无半成品逃逸
        $this->assertEquals(['begin'], $renderer->records);
    }

    public function testZeroTimeoutAbortsRenderWithDeadlineCode(): void
    {
        $renderer = new RecordingTestRenderer($this->resolver());

        try {
            $renderer->render(new TimeoutCancellation(0.0), Canvas::make(10, 10, ImageLayer::make(10, 10, '#f00')));
            $this->fail('立即超时必须抛错');
        } catch (MaterializeException $e) {
            $this->assertSame('render_deadline_exceeded', $e->getErrorCode());
            // TimeoutCancellation 无取消权/无原因注入（无 message 参数），previous 恒空
            $this->assertNull($e->getPrevious());
        }

        $this->assertNotContains('end', $renderer->records);
    }

    public function testCompositeDerivationShapeAbortsRender(): void
    {
        // Go parent+local 派生形态：composite(父超时信号, 本地源信号)，父触发即掐断
        $localSource = new CancellationSource();
        $ctx = new CompositeCancellation(new TimeoutCancellation(0.0), $localSource->getCancellation());

        $renderer = new RecordingTestRenderer($this->resolver());

        try {
            $renderer->render($ctx, Canvas::make(10, 10, TableLayer::make(10, 10, '#f00')));
            $this->fail('组合信号父源超时必须抛错');
        } catch (MaterializeException $e) {
            $this->assertSame('render_deadline_exceeded', $e->getErrorCode());
        }
    }

    public function testRootLoopCheckpointBeforeEachLayer(): void
    {
        // 空行表只画壳不物化——隔离根层循环检查点（物化前检查点不干扰）
        $ctx = new FakeCancellation();
        $renderer = new RecordingTestRenderer($this->resolver());
        $rects = 0;
        $renderer->afterRect = function () use (&$rects, $ctx): void {
            if (++$rects === 1) {
                $ctx->requested = true; // 第 1 层画完后武装
            }
        };

        try {
            $renderer->render($ctx, Canvas::make(
                10,
                10,
                TableLayer::make(10, 10, '#f00'),
                TableLayer::make(10, 10, '#0f0'),
            ));
            $this->fail('第 2 层前的检查点必须掐断');
        } catch (MaterializeException $e) {
            $this->assertSame('render_cancelled', $e->getErrorCode());
        }

        // 第 2 层的壳矩形不得出现，end 永不触达
        $this->assertEquals(['begin', 'rect'], $renderer->records);
    }

    public function testTableRowLoopCheckpointBeforeEachRow(): void
    {
        // 行内容为空——隔离 paintTable 行循环检查点
        $ctx = new FakeCancellation();
        $table = TableLayer::make(10, 20, '#fff');
        $table->addRow(new TableRowLayer(10, 10));
        $table->addRow(new TableRowLayer(10, 10));

        $renderer = new RecordingTestRenderer($this->resolver());
        $rects = 0;
        $renderer->afterRect = function () use (&$rects, $ctx): void {
            if (++$rects === 2) {
                $ctx->requested = true; // 表壳 + 第 1 行画完后武装
            }
        };

        try {
            $renderer->renderLayer($ctx, $table);
            $this->fail('第 2 行前的检查点必须掐断');
        } catch (MaterializeException $e) {
            $this->assertSame('render_cancelled', $e->getErrorCode());
        }

        // 第 2 行的矩形不得出现，end 永不触达
        $this->assertEquals(['begin', 'rect', 'rect'], $renderer->records);
    }

    public function testCancelledSignalSkipsDownloadAtResolveLayerEntry(): void
    {
        $ctx = new FakeCancellation(requested: true);
        $renderer = new RecordingTestRenderer($this->resolver());

        try {
            $renderer->render($ctx, Canvas::make(
                10,
                10,
                ImageLayer::make(10, 10)->setImage('https://cdn.example.com/cancelled-' . uniqid() . '.png'),
            ));
            $this->fail('物化前检查点必须掐断');
        } catch (MaterializeException $e) {
            $this->assertSame('render_cancelled', $e->getErrorCode());
        }

        $this->assertSame([], $this->downloader->calls, '物化前已取消，不得发起下载');
    }

    public function testCancellationThreadsDownToDownloader(): void
    {
        $ctx = new FakeCancellation();
        $url = 'https://cdn.example.com/threaded-' . uniqid() . '.png';
        $renderer = new RecordingTestRenderer($this->resolver());

        $product = $renderer->render($ctx, Canvas::make(10, 10, ImageLayer::make(10, 10)->setImage($url)));

        $this->assertSame('fake-product', $product);
        // ctx 原样穿线到下载边界：render → paint → resolveLayer → download 同一实例
        $this->assertSame([$url], $this->downloader->calls);
        $this->assertSame([$ctx], $this->downloader->ctxs);
    }

    public function testRenderLayerTakesCancellationFirstParam(): void
    {
        $renderer = new RecordingTestRenderer($this->resolver());

        $product = $renderer->renderLayer(new NullCancellation(), TableLayer::make(10, 10, '#f00'));

        $this->assertSame('fake-product', $product);
        $this->assertEquals(['begin', 'rect', 'end'], $renderer->records);
    }
}
