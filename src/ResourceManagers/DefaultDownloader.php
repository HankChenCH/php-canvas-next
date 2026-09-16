<?php

namespace HankChen\CanvasNext\ResourceManagers;

use HankChen\CanvasNext\Contracts\DownloaderInterface;

/**
 * 默认下载器：file_get_contents 实现（依赖 allow_url_fopen）
 */
class DefaultDownloader implements DownloaderInterface
{
    public function download($url)
    {
        return file_get_contents($url);
    }
}
