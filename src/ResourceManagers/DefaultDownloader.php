<?php

namespace HankChen\CanvasNext\ResourceManagers;

use HankChen\CanvasNext\Contracts\DownloaderInterface;
use HankChen\CanvasNext\Exception\MaterializeException;
use InvalidArgumentException;

/**
 * 默认下载器：file_get_contents 实现（依赖 allow_url_fopen）
 *
 * 经 stream context 显式设置 socket 超时（构造参数可配，默认 30s），连接与传输同用该值，
 * 取代 default_socket_timeout ini（默认 60s）的隐式兜底——整包下载时长首次有上界。
 * 超时失败抛 code resource_download_failed 的 MaterializeException（消息带超时上下文），
 * 不新增错误 code；其余失败仍按契约返回 false，由 ResourceResolver 统一抛错
 */
class DefaultDownloader implements DownloaderInterface
{
    public function __construct(
        private readonly float $timeoutSeconds = 30.0
    ) {
        if ($timeoutSeconds <= 0) {
            throw new InvalidArgumentException("timeoutSeconds must be positive, got {$timeoutSeconds}");
        }
    }

    public function download($url)
    {
        $start = microtime(true);

        // http:// 与 https:// 共用 http wrapper 的 context 段（PHP 无独立 https 段）
        $context = stream_context_create([
            'http' => ['timeout' => $this->timeoutSeconds],
        ]);

        // 捕获并吞掉 file_get_contents 的 E_WARNING：失败统一走返回值/异常面。
        // 超时识别不能只靠 warning 文案——读超时（macOS 实测）只有 wrapper 层
        // "HTTP request failed!"，不含 "timed out"；故叠加耗时判定兜底
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        });

        try {
            $content = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }

        if ($content !== false) {
            return $content;
        }

        // 超时判定：耗时越线（留 5% 余量容纳前置开销）或 warning 文案直接点名。
        // 两处排除——DNS 解析不受 context 超时约束，getaddrinfo 失败哪怕慢到越过
        // 阈值也不是超时；拿到 HTTP 状态行说明响应已到达，同理不判超时
        $warningText = implode("\n", $warnings);
        $dnsOrHttpFailed = stripos($warningText, 'getaddrinfo') !== false
            || stripos($warningText, 'HTTP/1.') !== false;
        $timedOutByElapsed = !$dnsOrHttpFailed
            && (microtime(true) - $start) >= $this->timeoutSeconds * 0.95;
        $timedOutByWarning = stripos($warningText, 'timed out') !== false;

        if ($timedOutByElapsed || $timedOutByWarning) {
            $detail = $warnings === [] ? '' : ' (' . end($warnings) . ')';

            throw new MaterializeException(
                'resource_download_failed',
                "resource_download_failed: download timed out after {$this->timeoutSeconds}s: {$url}{$detail}"
            );
        }

        return false;
    }
}
