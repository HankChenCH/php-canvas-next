<?php

namespace HankChen\CanvasNext\ResourceManagers;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use HankChen\CanvasNext\Exception\MaterializeException;
use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Contracts\DownloaderInterface;
use HankChen\CanvasNext\Layer\AbstractLayer;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\Layer\QrCodeLayer;
use HankChen\CanvasNext\Layer\TableLayer;
use HankChen\CanvasNext\Layer\TableCellLayer;
use HankChen\CanvasNext\Layer\TableRowLayer;
use HankChen\CanvasNext\Layer\TextLayer;

/**
 * 资源物化：绘制分派前按需物化（spec §4.3，渲染期角色重述）
 *
 * 把图层里的远程引用（图片 URL / 字体 URL）下载到本地缓存、把二维码内容
 * 生成为本地 PNG，并将物化结果回写到图层。渲染端只见本地路径。
 * 逻辑与渲染前预遍历时代零差异——变化只在调用时机：由渲染器绘制分支
 * 惰性调用（未引用资源零开销），物化失败即抛、渲染面丢弃、无产物逃逸
 *
 * 缓存目录 sys_get_temp_dir()/canvas-next/，与旧库 php-canvas 共存时互不污染；
 * 同一资源按 URL basename 缓存，命中即跳过下载（天然兼同 pass memo，无失效机制）
 */
class ResourceResolver
{
    private DownloaderInterface $downloader;

    public function __construct(?DownloaderInterface $downloader = null)
    {
        $this->downloader = $downloader ?? new DefaultDownloader();
    }

    public function resolveLayer(AbstractLayer $layer): void
    {
        match (true) {
            $layer instanceof TableLayer => $this->resolveTable($layer),
            $layer instanceof TableRowLayer => $this->resolveRow($layer),
            $layer instanceof TableCellLayer => $this->resolveCell($layer),
            $layer instanceof ImageLayer => $this->materializeImage($layer),
            $layer instanceof TextLayer => $this->materializeFont($layer),
            $layer instanceof QrCodeLayer => $this->materializeQrCode($layer),
            default => null,
        };
    }

    public function resolve(Canvas $canvas): void
    {
        foreach ($canvas->getLayers() as $layer) {
            $this->resolveLayer($layer);
        }
    }

    private function resolveTable(TableLayer $table): void
    {
        foreach ($table->getRows() as $row) {
            $this->resolveLayer($row);
        }
    }

    private function resolveRow(TableRowLayer $row): void
    {
        foreach ($row->getCells() as $cell) {
            $this->resolveLayer($cell);
        }
    }

    private function resolveCell(TableCellLayer $cell): void
    {
        if ($cell->getContentLayer() !== null) {
            $this->resolveLayer($cell->getContentLayer());
        }
    }

    private function materializeImage(ImageLayer $layer): void
    {
        $src = $layer->getImage();
        if ($src === null) {
            return;
        }

        if (filter_var($src, FILTER_VALIDATE_URL) === false) {
            return; // 本地路径直接使用
        }

        $path = $this->cachePath('img_layers', $src);
        if (!is_file($path)) {
            $content = $this->downloader->download($src);
            if (!$content) {
                throw new MaterializeException('resource_download_failed', "resource_download_failed: could not get remote file({$src})");
            }

            if (!file_put_contents($path, $content)) {
                throw new MaterializeException('resource_save_failed', "resource_save_failed: remote file({$src}) save to tmp path failed");
            }
        }

        $layer->setResolvedSrc($path);
    }

    private function materializeFont(TextLayer $layer): void
    {
        $font = $layer->getFont();
        // 空串/纯数字表示渲染端内置默认字体
        if ($font === '' || is_numeric($font) || filter_var($font, FILTER_VALIDATE_URL) === false) {
            return;
        }

        $path = $this->cachePath('text_layers', $font);
        if (!is_file($path)) {
            $content = $this->downloader->download($font);
            if (!$content) {
                throw new MaterializeException('resource_download_failed', "resource_download_failed: could not get remote file({$font})");
            }

            if (!file_put_contents($path, $content)) {
                throw new MaterializeException('resource_save_failed', "resource_save_failed: remote file({$font}) save to tmp path failed");
            }
        }

        $layer->setResolvedFont($path);
    }

    private function materializeQrCode(QrCodeLayer $layer): void
    {
        if ($layer->getText() === '' || $layer->getResolvedSrc() !== null) {
            return;
        }

        // endroid/qr-code v6 只读值对象，命名参数构造
        $qrCode = new QrCode(
            data: $layer->getText(),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: max($layer->getWidth(), 1),
            margin: 0,
            roundBlockSizeMode: RoundBlockSizeMode::None,
            foregroundColor: new Color(0, 0, 0),
            backgroundColor: new Color(255, 255, 255),
        );

        $png = (new PngWriter())->write($qrCode)->getString();

        $path = $this->cachePath(
            'qr_layers',
            'qr_' . md5($layer->getText() . '_' . $layer->getWidth()) . '.png'
        );
        if (!is_file($path) && !file_put_contents($path, $png)) {
            throw new MaterializeException('qr_generate_failed', "qr_generate_failed: qr code save to tmp path failed: {$path}");
        }

        $layer->setResolvedSrc($path);
    }

    /**
     * 缓存文件路径：以 URL path 的 basename 为键（不同 URL 同名会碰撞，与旧库一致）
     */
    private function cachePath(string $sub, string $url): string
    {
        $dir = $this->ensureCacheDir($sub);

        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['path'])) {
            throw new MaterializeException('resource_download_failed', "resource_download_failed: resource url parse failed:{$url}");
        }

        return $dir . DIRECTORY_SEPARATOR . pathinfo($parsed['path'], PATHINFO_BASENAME);
    }

    /**
     * 确保远程资源缓存子目录存在且可写（含历史 0644 目录自愈），返回目录路径
     */
    private function ensureCacheDir(string $sub): string
    {
        $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'canvas-next';
        $tmpPath = $basePath . DIRECTORY_SEPARATOR . $sub;

        if (is_dir($basePath) && (!is_writable($basePath) || !is_executable($basePath))) {
            @chmod($basePath, 0755);
        }

        if (!is_dir($tmpPath) && !@mkdir($tmpPath, 0755, true) && !is_dir($tmpPath)) {
            throw new MaterializeException('resource_save_failed', 'resource_save_failed: tmp path can not writable:' . $tmpPath);
        }

        if (!is_writable($tmpPath) || !is_executable($tmpPath)) {
            @chmod($tmpPath, 0755);
        }

        if (!is_writable($tmpPath) || !is_executable($tmpPath)) {
            throw new MaterializeException('resource_save_failed', 'resource_save_failed: tmp path can not writable:' . $tmpPath);
        }

        return $tmpPath;
    }
}
