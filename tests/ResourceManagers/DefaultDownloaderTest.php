<?php

namespace HankChen\CanvasNext\Tests\ResourceManagers;

use HankChen\CanvasNext\Exception\MaterializeException;
use HankChen\CanvasNext\Layer\ImageLayer;
use HankChen\CanvasNext\ResourceManagers\DefaultDownloader;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;
use HankChen\CanvasNext\Runtime\NullCancellation;
use HankChen\CanvasNext\Runtime\TimeoutCancellation;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * DefaultDownloader 流超时（render-cancellation 工票 01）
 *
 * 端点全部用本地回环构造，不依赖外网、不受 CI 网络抖动影响：
 * - 慢端点 = 只 listen 不应答的本地 socket：TCP 握手由内核 backlog 完成，
 *   请求发出后等不到响应，挂起直至超时（等价不可路由地址的 connect 挂起形态）；
 * - 正常端点 = PHP 内建 server 回固定字节
 */
class DefaultDownloaderTest extends CanvasTestCase
{
    /**
     * 用只 listen 不应答的本地端点驱动 $fn，并把 default_socket_timeout ini
     * 显式压低为 5s（原值通常 60s）：显式超时若生效，失败应远早于 ini 兜底
     */
    private function withStalledEndpoint(callable $fn): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("无法创建本地监听 socket: {$errstr}");
        }

        $url = 'http://' . stream_socket_get_name($server, false) . '/fixture.png';

        $previousIni = ini_set('default_socket_timeout', '5');
        try {
            $fn($url);
        } finally {
            if ($previousIni !== false) {
                ini_set('default_socket_timeout', $previousIni);
            }
            fclose($server);
        }
    }

    /**
     * 用回固定字节的 PHP 内建 server 驱动 $fn（正常端点）
     *
     * 端口：先绑 0 端口向内核要一个空闲端口再交给内建 server——server 启动行
     * 打到管道下是块缓冲，解析 stdout 不可靠；改轮询建连就绪（5s 截止）
     */
    private function withRespondingEndpoint(string $body, callable $fn): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open 不可用，跳过真实 HTTP 下载用例');
        }

        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            $this->markTestSkipped("无法探测空闲端口: {$errstr}");
        }
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $routerPath = sys_get_temp_dir() . '/canvas-next-downloader-router-' . uniqid('', true) . '.php';
        file_put_contents($routerPath, '<?php header("Content-Type: application/octet-stream"); echo ' . var_export($body, true) . ';');

        $process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", $routerPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            unlink($routerPath);
            $this->markTestSkipped('无法启动 PHP 内建 server');
        }

        $url = "http://127.0.0.1:{$port}/fixture.png";
        $ready = false;
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $conn = @stream_socket_client("tcp://127.0.0.1:{$port}", $connErrno, $connErrstr, 0.2);
            if ($conn !== false) {
                fclose($conn);
                $ready = true;
                break;
            }
            usleep(50_000);
        }

        try {
            if (!$ready) {
                $this->fail("内建 server 5s 内未就绪: {$url}");
            }
            $fn($url);
        } finally {
            proc_terminate($process);
            proc_close($process);
            @unlink($routerPath);
        }
    }

    public function testDefaultTimeoutIs30Seconds(): void
    {
        $param = (new \ReflectionClass(DefaultDownloader::class))->getConstructor()->getParameters()[0];

        $this->assertSame(30.0, $param->getDefaultValue());
    }

    public function testNonPositiveTimeoutRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DefaultDownloader(0.0);
    }

    public function testNormalDownloadSucceedsOverHttp(): void
    {
        $body = 'fake-png-bytes';
        $this->withRespondingEndpoint($body, function (string $url) use ($body): void {
            // 显式超时不影响正常下载（读超时只兜底，不会提前掐断）
            $this->assertSame($body, (new DefaultDownloader(5.0))->download(new NullCancellation(), $url));
        });
    }

    public function testResolverTimeoutSurfacesAsResourceDownloadFailed(): void
    {
        $this->withStalledEndpoint(function (string $url): void {
            $resolver = new ResourceResolver(new DefaultDownloader(1.0));

            try {
                $resolver->resolveLayer(new NullCancellation(), ImageLayer::make(10, 10)->setImage($url));
                $this->fail('下载超时必须抛错');
            } catch (MaterializeException $e) {
                // 全链归既有 code（Resolver 抛出路径不变），消息带超时上下文
                $this->assertSame('resource_download_failed', $e->getErrorCode());
                $this->assertStringContainsString('timed out after 1.0s', $e->getMessage());
            }
        });
    }

    public function testStalledEndpointFailsAtExplicitTimeout(): void
    {
        $this->withStalledEndpoint(function (string $url): void {
            $downloader = new DefaultDownloader(1.0);

            $start = microtime(true);
            try {
                $downloader->download(new NullCancellation(), $url);
                $this->fail('超时必须抛错');
            } catch (MaterializeException $e) {
                $elapsed = microtime(true) - $start;

                // 归既有 code，不新增错误 code
                $this->assertSame('resource_download_failed', $e->getErrorCode());
                // 消息带超时上下文：显式超时值 + 目标 URL
                $this->assertStringContainsString('timed out after 1.0s', $e->getMessage());
                $this->assertStringContainsString($url, $e->getMessage());
                // 下界 = 确实等到了显式超时而非瞬时失败；上界 = 远小于压低后的 ini 兜底（5s）
                $this->assertGreaterThanOrEqual(0.8, $elapsed, "应在显式超时（约 1s）处失败，实际 {$elapsed}s");
                $this->assertLessThan(4.0, $elapsed, "应在显式超时处失败而非等 ini 兜底，实际 {$elapsed}s");
            }
        });
    }

    /**
     * 下载发起前检查点（render-cancellation 工票 02）：deadline 已过时
     * 先于任何网络 I/O 抛 render_deadline_exceeded——等不到流超时
     */
    public function testExceededDeadlineThrowsBeforeNetworkIO(): void
    {
        $this->withStalledEndpoint(function (string $url): void {
            $downloader = new DefaultDownloader(30.0);

            $start = microtime(true);
            try {
                $downloader->download(new TimeoutCancellation(0.0), $url);
                $this->fail('deadline 已过必须抛错');
            } catch (MaterializeException $e) {
                $elapsed = microtime(true) - $start;

                $this->assertSame('render_deadline_exceeded', $e->getErrorCode());
                $this->assertLessThan(1.0, $elapsed, "应在下载发起前抛出，实际等了 {$elapsed}s");
            }
        });
    }

    /**
     * deadline 余量换算 stream context 超时（复用工票 01 通道）：
     * 剩余（约 1s）< 本地默认（30s）时，流超时按余量收紧——
     * 若未收紧，context 超时为 30s，将越过压低后的 ini 兜底（5s）一路挂起
     */
    public function testDeadlineMarginTightensSocketTimeout(): void
    {
        $this->withStalledEndpoint(function (string $url): void {
            $downloader = new DefaultDownloader(30.0);

            $start = microtime(true);
            try {
                $downloader->download(new TimeoutCancellation(1.0), $url);
                $this->fail('余量耗尽必须抛错');
            } catch (MaterializeException $e) {
                $elapsed = microtime(true) - $start;

                // 流超时（约 1s）归既有 code，不另立 code
                $this->assertSame('resource_download_failed', $e->getErrorCode());
                $this->assertStringContainsString('timed out after', $e->getMessage());
                // 实际等待 ≈ 余量而非本地默认 30s / ini 兜底 5s
                $this->assertGreaterThanOrEqual(0.5, $elapsed, "应等到余量（约 1s）而非瞬时失败，实际 {$elapsed}s");
                $this->assertLessThan(4.0, $elapsed, "流超时应按余量收紧，实际 {$elapsed}s");
            }
        });
    }
}
