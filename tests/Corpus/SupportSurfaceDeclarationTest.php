<?php

namespace HankChen\CanvasNext\Tests\Corpus;

use HankChen\CanvasNext\Tests\Support\CanvasTestCase;
use RuntimeException;

/**
 * 支持面声明对表（互检补边，GOVERNANCE §消费端 pin）：runner 版本常量 ↔ 包根
 * support-surface.json 钉住的版本。已有两半互检各有归属——runner 常量 ↔ 语料
 * meta.contract（CorpusDocument::load 内断言）、声明版本 ↔ 语料目录（canvasctl
 * --surface 三互检）——但都拦不住「pin/常量升级而声明漏重签」：本测试补第三条边，
 * 任一侧漂移即红。常量表引用各 runner 测试类的 public CONTRACT（引用而非抄写，
 * 不制造第二份漂移源）。
 */
final class SupportSurfaceDeclarationTest extends CanvasTestCase
{
    public function testDeclaredVersionsMatchRunnerContracts(): void
    {
        $declared = self::declaredVersions();
        $runners = self::runnerContracts();

        self::compare($declared, $runners);
        $declaredIds = array_keys($declared);
        $runnerIds = array_keys($runners);
        sort($declaredIds);
        sort($runnerIds);
        self::assertSame($runnerIds, $declaredIds); // 键面双向一致（版本串比对在 compare 内）
    }

    /**
     * @param array<string, string> $declared
     * @param array<string, string> $runners
     * @dataProvider mutatedDeclarationProvider
     */
    public function testMismatchIsDetected(array $declared, array $runners, string $fragment): void
    {
        try {
            self::compare($declared, $runners);
            self::fail('互检应当报错');
        } catch (RuntimeException $e) {
            self::assertStringContainsString($fragment, $e->getMessage());
        }
    }

    /** @return iterable<string, array{array<string, string>, array<string, string>, string}> */
    public static function mutatedDeclarationProvider(): iterable
    {
        $runners = [
            'expression-eval' => 'expression-eval v3.0',
            'expand-semantics' => 'expand-semantics v1.1',
        ];

        yield '声明版本漂移' => [
            ['expression-eval' => 'expression-eval v4.0', 'expand-semantics' => 'expand-semantics v1.1'],
            $runners,
            'expression-eval：声明 expression-eval v4.0 ≠ runner expression-eval v3.0',
        ];
        yield '声明漏签（runner 多出契约）' => [
            ['expand-semantics' => 'expand-semantics v1.1'],
            $runners,
            'expression-eval：声明缺席',
        ];
        yield '声明多签（runner 未翻转）' => [
            ['expression-eval' => 'expression-eval v3.0', 'expand-semantics' => 'expand-semantics v1.1', 'paginate-semantics' => 'paginate-semantics v1.0'],
            $runners,
            'paginate-semantics：声明 paginate-semantics v1.0 无 runner 消费',
        ];
    }

    /**
     * 双向完备比对：runner 侧逐契约要求声明在场且串精确相等，声明侧逐条要求有
     * runner 消费；差异聚合报出，一次看全。
     *
     * @param array<string, string> $declared 契约 id → 完整 contract 串
     * @param array<string, string> $runners  契约 id → runner 钉定的 contract 串
     */
    public static function compare(array $declared, array $runners): void
    {
        $drift = [];
        foreach ($runners as $id => $contract) {
            if (!isset($declared[$id])) {
                $drift[] = "{$id}：声明缺席（runner 钉 {$contract}，漏重签）";
            } elseif ($declared[$id] !== $contract) {
                $drift[] = "{$id}：声明 {$declared[$id]} ≠ runner {$contract}";
            }
        }
        foreach ($declared as $id => $contract) {
            if (!isset($runners[$id])) {
                $drift[] = "{$id}：声明 {$contract} 无 runner 消费（多签或 runner 未翻转）";
            }
        }
        if ($drift !== []) {
            throw new RuntimeException('支持面声明与 runner 常量漂移：' . implode('；', $drift));
        }
    }

    /** @return array<string, string> 契约 id → runner 钉定的完整 contract 串（引用各测试类常量） */
    private static function runnerContracts(): array
    {
        return [
            'expression-eval' => ExpressionEvalCorpusTest::CONTRACT,
            'expression-scan' => ExpressionScanCorpusTest::CONTRACT,
            'expand-semantics' => ExpandCorpusTest::CONTRACT,
            'paginate-semantics' => PaginateCorpusTest::CONTRACT,
            'flow-semantics' => FlowCorpusTest::CONTRACT,
        ];
    }

    /** @return array<string, string> 契约 id → 完整 contract 串（support-surface.json） */
    private static function declaredVersions(): array
    {
        $path = dirname(__DIR__, 2) . '/support-surface.json';
        $raw = json_decode((string) file_get_contents($path), true);
        if (!is_array($raw) || !isset($raw['contracts']) || !is_array($raw['contracts'])) {
            throw new RuntimeException("支持面声明不可读：{$path}");
        }

        $declared = [];
        foreach ($raw['contracts'] as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || !is_string($entry['version'] ?? null)) {
                throw new RuntimeException("支持面声明条目形状非法：{$path}");
            }
            $declared[$entry['id']] = $entry['id'] . ' ' . $entry['version'];
        }

        return $declared;
    }
}
