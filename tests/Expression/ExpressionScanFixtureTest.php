<?php

namespace HankChen\CanvasNext\Tests\Expression;

use HankChen\CanvasNext\Tests\Support\CanvasTestCase;
use RuntimeException;

require_once __DIR__ . '/../../scripts/scan-fixtures.php';

/**
 * 扫描 fixture 同跑（content-completion spec §4 / 工单 06；用例集定稿于工单 01）。
 *
 * 共享用例集（contract: expression-scan v1）单点 authored 在 scripts/scan-fixtures.php，
 * 本测试是 PHP 侧 CI 永久 runner：逐用例钉死 InterpolationEvaluator 的扫描面
 * （未闭合 {{ 剩余全字面、片段 trim、{{}} 不嵌套、片段外字面直通、空片段/路径空段
 * = expression_syntax_error），并互证「切分拼装逐段求值 ≡ 整串求值」——扫描器与
 * 权威求值器漂移即红。求值器本身只读对齐、行为零改动；TS 侧 runner（canvas-web）
 * 消费同源导出的 JSON 载体，双端经同一用例集钉齐。
 */
class ExpressionScanFixtureTest extends CanvasTestCase
{
    public static function scanFixtureCasesProvider(): iterable
    {
        foreach (scanCases() as $case) {
            yield $case[0] => [$case];
        }
    }

    /**
     * @dataProvider scanFixtureCasesProvider
     */
    public function testScanFixtureCase(array $case): void
    {
        try {
            verifyScanCase($case);
            $this->addToAssertionCount(1);
        } catch (RuntimeException $e) {
            $this->fail($e->getMessage());
        }
    }

    public function testCaseNamesAreUnique(): void
    {
        $names = array_map(static fn (array $case): string => $case[0], scanCases());

        $this->assertNotEmpty($names, '共享用例集不可为空');
        $this->assertSame($names, array_values(array_unique($names)), '用例名必须唯一（双端按名对齐）');
    }

    public function testExportShapeUsesContractV1Keys(): void
    {
        // JSON 载体形态是 TS runner 的消费面：键集漂移 = TS 侧静默断读，逐用例钉死
        foreach (scanCases() as $case) {
            $shape = scanFixtureExportShape($case);
            $this->assertSame(
                ['name', 'description', 'template', 'expect'],
                array_keys($shape),
                "用例 {$case[0]}：载体顶层键集漂移"
            );
            $this->assertSame(['parts'], array_keys($shape['expect']), "用例 {$case[0]}：expect 键集漂移");

            foreach ($shape['expect']['parts'] as $part) {
                $expectedKeys = match ($part['kind']) {
                    'literal' => ['kind', 'text'],
                    'open' => ['kind', 'text', 'expr'],
                    'fragment' => ['kind', 'raw', 'expr', 'error'],
                    default => $this->fail("用例 {$case[0]}：未知 part kind {$part['kind']}"),
                };
                $this->assertSame($expectedKeys, array_keys($part), "用例 {$case[0]}：part 键集漂移");
            }
        }
    }
}
