<?php

namespace HankChen\CanvasNext\Tests\Corpus;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\DiagnosticCollector;
use HankChen\CanvasNext\Exception\CanvasNextExceptionInterface;
use HankChen\CanvasNext\Hydrate\CanvasHydrator;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 填充契约 runner（工票 06 消费翻转）：语义 = submodule 语料
 * corpus/expand-semantics/v1.1/expand.json，共 29 用例（含软诊断 v1.1 三例）。
 *
 * 管线 = graph → Canvas::fromGraph → CanvasHydrator::hydrate(dataset, 收集器) → graph，
 * expect = {graph, warnings?} | {errorCode}；warnings 断言面 = code + path + 序列序
 * （消息人读不入断言面，spec/pipeline.md §4/§5）。手写语义用例已归拢进语料；
 * PHP-only 关怀的清点见 tests/Corpus/README.md。
 */
class ExpandCorpusTest extends CanvasTestCase
{
    public const CONTRACT = 'expand-semantics v1.1';
    private const MODULE_FILE = 'expand-semantics/v1.1/expand.json';

    public static function expandCasesProvider(): iterable
    {
        $document = CorpusDocument::load(self::MODULE_FILE, self::CONTRACT);
        foreach ($document['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /**
     * @dataProvider expandCasesProvider
     *
     * @param array{name: string, graph: array, dataset: mixed, expect: array} $case
     */
    public function testCorpusCase(array $case): void
    {
        if (isset($case['expect']['errorCode'])) {
            $this->assertExpectedErrorCode($case);

            return;
        }

        $decoded = Canvas::fromGraph($case['graph']);
        $warnings = new DiagnosticCollector();
        $hydrated = (new CanvasHydrator())->hydrate($decoded, $case['dataset'], $warnings);

        $this->assertSame(
            CorpusDocument::wireBytes($case['expect']['graph']),
            CorpusDocument::wireBytes($hydrated->graph()),
            "用例 {$case['name']}：填充产物 graph 与语料期望不符"
        );
        $this->assertSame(
            $case['expect']['warnings'] ?? [],
            CorpusDocument::exportedWarnings($warnings->all()),
            "用例 {$case['name']}：软诊断序列与语料期望不符"
        );
    }

    private function assertExpectedErrorCode(array $case): void
    {
        $code = $case['expect']['errorCode'];
        $expectedClass = CorpusDocument::exceptionClassFor($code);

        try {
            $decoded = Canvas::fromGraph($case['graph']);
            (new CanvasHydrator())->hydrate($decoded, $case['dataset'], new DiagnosticCollector());
            $this->fail("用例 {$case['name']}：预期报 {$code} 但填充成功");
        } catch (CanvasNextExceptionInterface $e) {
            $this->assertSame($expectedClass, get_class($e), "用例 {$case['name']}：五阶段异常载体漂移");
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    public function testContractVersionIsPinnedAndCaseNamesUnique(): void
    {
        // 加载即版本双写互检（CorpusDocument::load 内断言 meta.contract 精确相等）
        $document = CorpusDocument::load(self::MODULE_FILE, self::CONTRACT);
        $names = array_column($document['cases'], 'name');

        $this->assertNotEmpty($names);
        $this->assertSame($names, array_values(array_unique($names)), '语料用例名重复');
    }
}
