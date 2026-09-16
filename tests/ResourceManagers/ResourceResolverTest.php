<?php

namespace HankChen\CanvasNext\Tests\ResourceManagers;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TextLayer;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
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
        $resolver = $this->resolverWith($this->pngBytes(2, 2, '#f00'));
        $url = 'https://cdn.example.com/img/red.png';

        $canvas = Canvas::make(10, 10,
            ImageLayer::make(10, 10)->setImage($url),
            ImageLayer::make(10, 10)->setImage($url)
        );
        $resolver->resolve($canvas);

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
        $resolver->resolveLayer($layer);

        $this->assertCount(0, $this->downloader->calls);
        $this->assertSame('/tmp/local.png', $layer->getResolvedSrc());
    }

    public function testImageDownloadFailureThrows(): void
    {
        $resolver = $this->resolverWith(false);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('could not get remote file');

        $resolver->resolveLayer(ImageLayer::make(10, 10)->setImage('https://cdn.example.com/missing.png'));
    }

    public function testRemoteFontMaterialized(): void
    {
        $resolver = $this->resolverWith('ttf-bytes');
        $layer = TextLayer::make(100, 30)->setText('文本')
            ->setFont('https://cdn.example.com/fonts/msyh.ttf', 12, '#000');

        $resolver->resolveLayer($layer);

        $expectedPath = $this->cacheDir('text_layers') . '/msyh.ttf';
        $this->assertFileExists($expectedPath);
        $this->assertSame($expectedPath, $layer->getResolvedFont());
    }

    public function testQrCodeMaterializedToPngFile(): void
    {
        $resolver = $this->resolverWith('should-not-be-used');
        $layer = QrCodeLayer::make(60, 60)->setText('https://example.com/中文');

        $resolver->resolveLayer($layer);

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

        $resolver->resolveLayer($layer);
        $first = $layer->getResolvedSrc();
        $resolver->resolveLayer($layer);

        $this->assertSame($first, $layer->getResolvedSrc());
        $this->assertFileExists($first);
    }
}
