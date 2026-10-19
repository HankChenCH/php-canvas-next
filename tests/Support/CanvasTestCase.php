<?php

namespace HankChen\CanvasNext\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * 核心包（结构侧）测试基类：只测纯数据与布局，不做像素取样。
 * 像素辅助方法位于 php-canvas-image-renderer 包的同名基类
 */
abstract class CanvasTestCase extends TestCase
{
    /** 绘制分派契约测试的临时目录（initTmpDir 创建、cleanupTmpDir 清理） */
    protected string $tmpDir = '';

    /**
     * 核心包 ResourceResolver 的缓存目录（sys_get_temp_dir()/canvas-next/<sub>）
     */
    protected function cacheDir(string $sub): string
    {
        return sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'canvas-next'
            . DIRECTORY_SEPARATOR . $sub;
    }

    /** 每用例唯一临时目录（writePng 等落盘助手的宿主） */
    protected function initTmpDir(string $prefix): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/' . $prefix . '-' . uniqid();
        mkdir($this->tmpDir);
    }

    /** 清理临时目录（幂等：未创建过直接跳过） */
    protected function cleanupTmpDir(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }

        foreach (scandir($this->tmpDir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($this->tmpDir . '/' . $entry);
            }
        }
        @rmdir($this->tmpDir);
    }

    /**
     * 删除目录及其内容，保证每条用例都真正走到 mkdir 分支
     */
    protected function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /**
     * 生成已知内在尺寸的合法 PNG（8-bit RGB）落盘，返回路径。
     * 供绘制分派契约测试喂 getimagesize（只认头块，但 IDAT 一并备成合法，文件真实可解码）
     */
    protected function writePng(string $dir, string $name, int $width, int $height): string
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . '/' . $name;
        $raw = str_repeat("\x00" . str_repeat("\x7f\x80\x81", $width), $height);
        file_put_contents($path, "\x89PNG\r\n\x1a\n"
            . $this->pngChunk('IHDR', pack('N2C5', $width, $height, 8, 2, 0, 0, 0))
            . $this->pngChunk('IDAT', gzcompress($raw))
            . $this->pngChunk('IEND', ''));

        return $path;
    }
}
