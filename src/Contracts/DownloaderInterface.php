<?php

namespace HankChen\CanvasNext\Contracts;

/**
 * 远程资源下载器契约：注入自定义实现（代理、鉴权、限流等）
 */
interface DownloaderInterface
{
    /**
     * @param string $url 远程资源地址
     * @return string|false 资源内容，失败返回 false
     */
    public function download($url);
}
