<?php

namespace HankChen\CanvasNext\Tests\ResourceManagers;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\Exception\MaterializeException;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Runtime\NullCancellation;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;
use HankChen\CanvasNext\Tests\Support\FakeDownloader;

class ResourceResolverTest extends CanvasTestCase
{
    private FakeDownloader $downloader;

    private ResourceResolver $resolver;

    protected function setUp(): void
    {
        $this->removeDir($this->cacheDir('img_layers'));
        $this->removeDir($this->cacheDir('text_layers'));
        $this->removeDir($this->cacheDir('qr_layers'));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->cacheDir('img_layers'));
        $this->removeDir($this->cacheDir('text_layers'));
        $this->removeDir($this->cacheDir('qr_layers'));
    }

    private function resolverWith(string|false $content): ResourceResolver
    {
        $this->downloader = new FakeDownloader($content);

        return new ResourceResolver($this->downloader);
    }

    public function testRemoteImageDownloadedOnceAndCachedAcrossLayers(): void
    {
        // Resolver 只负责把字节落盘，不校验图片内容，哑字节即可
        $resolver = $this->resolverWith('fake-image-bytes');
        $url = 'https://cdn.example.com/img/red.png';

        $canvas = Canvas::make(10, 10,
            ImageLayer::make(10, 10)->setImage($url),
            ImageLayer::make(10, 10)->setImage($url)
        );
        $resolver->resolve(new NullCancellation(), $canvas);

        $this->assertCount(1, $this->downloader->calls);

        $expectedPath = $this->cacheDir('img_layers') . '/red.png';
        $this->assertFileExists($expectedPath);
        $this->assertSame($expectedPath, $canvas->getLayers()[0]->getResolvedSrc());
        $this->assertSame($expectedPath, $canvas->getLayers()[1]->getResolvedSrc());
    }

    public function testLocalImageSkipsDownload(): void
    {
        $resolver = $this->resolverWith('should-not-be-used');

        $layer = ImageLayer::make(10, 10)->setImage('/tmp/local.png');
        $resolver->resolveLayer(new NullCancellation(), $layer);

        $this->assertCount(0, $this->downloader->calls);
        $this->assertSame('/tmp/local.png', $layer->getResolvedSrc());
    }

    public function testImageDownloadFailureThrows(): void
    {
        $resolver = $this->resolverWith(false);

        try {
            $resolver->resolveLayer(new NullCancellation(), ImageLayer::make(10, 10)->setImage('https://cdn.example.com/missing.png'));
            $this->fail('下载失败必须抛错');
        } catch (MaterializeException $e) {
            // 绘制期错误 code 化（spec §5.2）：code 稳定，消息保留原始上下文
            $this->assertSame('resource_download_failed', $e->getErrorCode());
            $this->assertStringContainsString('could not get remote file', $e->getMessage());
        }
    }

    public function testRemoteFontMaterialized(): void
    {
        $resolver = $this->resolverWith('ttf-bytes');
        $layer = TextLayer::make(100, 30)->setText('文本')
            ->setFont('https://cdn.example.com/fonts/msyh.ttf', 12, '#000');

        $resolver->resolveLayer(new NullCancellation(), $layer);

        $expectedPath = $this->cacheDir('text_layers') . '/msyh.ttf';
        $this->assertFileExists($expectedPath);
        $this->assertSame($expectedPath, $layer->getResolvedFont());
    }

    public function testQrCodeMaterializedToPngFile(): void
    {
        $resolver = $this->resolverWith('should-not-be-used');
        $layer = QrCodeLayer::make(60, 60)->setText('https://example.com/中文');

        $resolver->resolveLayer(new NullCancellation(), $layer);

        $src = $layer->getResolvedSrc();
        $this->assertNotNull($src);
        $this->assertFileExists($src);
        // PNG 魔数校验
        $this->assertSame("\x89PNG", substr(file_get_contents($src), 0, 4));
    }

    public function testQrCodeResolutionIsIdempotent(): void
    {
        $resolver = $this->resolverWith('should-not-be-used');
        $layer = QrCodeLayer::make(60, 60)->setText('https://example.com');

        $resolver->resolveLayer(new NullCancellation(), $layer);
        $first = $layer->getResolvedSrc();
        $resolver->resolveLayer(new NullCancellation(), $layer);

        $this->assertSame($first, $layer->getResolvedSrc());
        $this->assertFileExists($first);
    }
}
