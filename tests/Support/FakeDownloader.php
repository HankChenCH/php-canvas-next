<?php

namespace HankChen\CanvasNext\Tests\Support;

use HankChen\CanvasNext\Contracts\DownloaderInterface;

/**
 * DownloaderInterface 手工桩：记录调用并返回预设内容，传 false 模拟下载失败
 */
class FakeDownloader implements DownloaderInterface
{
    /** @var string[] 已请求的 URL 列表，供断言调用次数 */
    public $calls = [];

    private $content;

    public function __construct($content)
    {
        $this->content = $content;
    }

    public function download($url)
    {
        $this->calls[] = $url;

        return $this->content;
    }
}
