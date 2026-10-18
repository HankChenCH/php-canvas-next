<?php

namespace HankChen\CanvasNext\Tests\Corpus;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Compiler\CanvasPaginator;
use HankChen\CanvasNext\Exception\CanvasNextExceptionInterface;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 分页契约 runner（工票 06 消费翻转）：语义 = submodule 语料
 * corpus/paginate-semantics/v1.0/paginate.json，共 12 用例。
 *
 * 管线 = graph → Canvas::fromGraph → CanvasPaginator::fromOptions(options) → paginate()，
 * 输入为 hydrate 产物（无诊断发射面，expect 不收 warnings），
 * expect = {pages: [页 graph…]} | {errorCode}。手写语义用例已归拢进语料；
 * PHP-only 关怀的清点见 tests/Corpus/README.md。
 */
class PaginateCorpusTest extends CanvasTestCase
{
    private const CONTRACT = 'paginate-semantics v1.0';
    private const MODULE_FILE = 'paginate-semantics/v1.0/paginate.json';

    public static function paginateCasesProvider(): iterable
    {
        $document = CorpusDocument::load(self::MODULE_FILE, self::CONTRACT);
        foreach ($document['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /**
     * @dataProvider paginateCasesProvider
     *
     * @param array{name: string, graph: array, options: mixed, expect: array} $case
     */
    public function testCorpusCase(array $case): void
    {
        // options = []（PHP 空关联数组 wire 形态 = 无选项）| {pageHeight, truncate?}；
        // page_geometry_invalid 在 fromOptions 构造期抛，与分页期错误同一 errorCode 断言面
        try {
            $paginator = CanvasPaginator::fromOptions($case['options']);
            $pages = $paginator->paginate(Canvas::fromGraph($case['graph']));
        } catch (CanvasNextExceptionInterface $e) {
            $this->assertExpectedErrorCode($case, $e);

            return;
        }

        $this->assertArrayNotHasKey(
            'errorCode',
            $case['expect'],
            "用例 {$case['name']}：预期报错但分页成功"
        );
        $this->assertSame(
            CorpusDocument::wireBytes($case['expect']['pages']),
            CorpusDocument::wireBytes(array_map(static fn (Canvas $page) => $page->graph(), $pages)),
            "用例 {$case['name']}：产物页序列与语料期望不符"
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
