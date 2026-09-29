<?php

namespace HankChen\CanvasNext\Contracts;

use HankChen\CanvasNext\Runtime\Cancellation;

/**
 * 远程资源下载器契约：注入自定义实现（代理、鉴权、限流等）
 */
interface DownloaderInterface
{
    /**
     * @param Cancellation $ctx 渲染期取消信号（advisory only）：实现方应在发起
     *                          阻塞等待前轮询 $ctx，并按 deadline() 余量自我约束
     *                          阻塞时长（参照 DefaultDownloader 的 stream context 换算）
     * @param string $url 远程资源地址
     * @return string|false 资源内容，失败返回 false
     */
    public function download(Cancellation $ctx, string $url): string|false;
}
