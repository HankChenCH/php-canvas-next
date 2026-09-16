<?php

namespace HankChen\CanvasNext\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * 核心包（结构侧）测试基类：只测纯数据与布局，不做像素取样。
 * 像素辅助方法位于 php-canvas-image-renderer 包的同名基类
 */
abstract class CanvasTestCase extends TestCase
{
    /**
     * 核心包 ResourceResolver 的缓存目录（sys_get_temp_dir()/canvas-next/<sub>）
     */
    protected function cacheDir(string $sub): string
    {
        return sys_get_temp_dir()
            . DIRECTORY_SEPARATOR . 'canvas-next'
            . DIRECTORY_SEPARATOR . $sub;
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
}
