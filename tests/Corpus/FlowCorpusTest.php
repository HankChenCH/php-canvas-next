<?php

namespace HankChen\CanvasNext\Tests\Corpus;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\DocumentCompiler;
use HankChen\CanvasNext\Exception\CanvasNextExceptionInterface;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 流装箱契约 runner（工票 06 消费翻转）：语义 = submodule 语料
 * corpus/flow-semantics/v1.1/flow.json，共 23 用例（20 规定用例集 + fit 保真
 * + mode 枚举 + 软诊断 v1.1）。
 *
 * 管线 = 模板态 frames graph → Canvas::fromGraph 重建 →
 * DocumentCompiler::compile(frames, dataset, flowChain) → 帧序×页序 canvas graph 列表
 * （文档管线自声明态起跑，hydrate 在管线内），expect = {canvases, warnings?} | {errorCode}。
 * 手写语义用例已归拢进语料；PHP-only 关怀的清点见 tests/Corpus/README.md。
 */
class FlowCorpusTest extends CanvasTestCase
{
    public const CONTRACT = 'flow-semantics v1.1';
    private const MODULE_FILE = 'flow-semantics/v1.1/flow.json';

    public static function flowCasesProvider(): iterable
    {
        $document = CorpusDocument::load(self::MODULE_FILE, self::CONTRACT);
        foreach ($document['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /**
     * @dataProvider flowCasesProvider
     *
     * @param array{name: string, frames: array, dataset: mixed, flowChain: mixed, expect: array} $case
     */
    public function testCorpusCase(array $case): void
    {
        try {
            $decoded = array_map(static fn (array $graph) => Canvas::fromGraph($graph), $case['frames']);
            $result = (new DocumentCompiler())->compile($decoded, $case['dataset'], $case['flowChain']);
        } catch (CanvasNextExceptionInterface $e) {
            $this->assertExpectedErrorCode($case, $e);

            return;
        }

        $this->assertArrayNotHasKey(
            'errorCode',
            $case['expect'],
            "用例 {$case['name']}：预期报错但编译成功"
        );
        $this->assertSame(
            CorpusDocument::wireBytes($case['expect']['canvases']),
            CorpusDocument::wireBytes(array_map(static fn (Canvas $canvas) => $canvas->graph(), $result->canvases)),
            "用例 {$case['name']}：产物画布序列与语料期望不符"
        );
        $this->assertSame(
            $case['expect']['warnings'] ?? [],
            CorpusDocument::exportedWarnings($result->warnings),
            "用例 {$case['name']}：软诊断序列与语料期望不符"
        );
    }

    private function assertExpectedErrorCode(array $case, CanvasNextExceptionInterface $e): void
    {
        $code = $case['expect']['errorCode'] ?? $this->fail("用例 {$case['name']}：意外报错 {$e->getErrorCode()}");
        $expectedClass = CorpusDocument::exceptionClassFor($code);

        $this->assertSame($expectedClass, get_class($e), "用例 {$case['name']}：五阶段异常载体漂移");
        $this->assertSame($code, $e->getErrorCode());
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
