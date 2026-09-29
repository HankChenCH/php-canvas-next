<?php

namespace HankChen\CanvasNext\Tests\Support;

use HankChen\CanvasNext\Contracts\DownloaderInterface;
use HankChen\CanvasNext\Runtime\Cancellation;

/**
 * DownloaderInterface 手工桩：记录调用并返回预设内容，传 false 模拟下载失败
 */
class FakeDownloader implements DownloaderInterface
{
    /** @var string[] 已请求的 URL 列表，供断言调用次数 */
    public $calls = [];

    /** @var Cancellation[] 已收到的取消信号实例，供断言 ctx 穿线 */
    public $ctxs = [];

    private $content;

    public function __construct($content)
    {
        $this->content = $content;
    }

    public function download(Cancellation $ctx, string $url): string|false
    {
        $this->ctxs[] = $ctx;
        $this->calls[] = $url;

        return $this->content;
    }
}
