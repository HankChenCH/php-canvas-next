<?php

namespace HankChen\CanvasNext\Tests\Corpus;

use HankChen\CanvasNext\Exception\CanvasNextExceptionInterface;
use HankChen\CanvasNext\Expression\DateFormat;
use HankChen\CanvasNext\Expression\InterpolationEvaluator;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 求值契约 runner（工票 06 消费翻转）：语义 = submodule 语料
 * corpus/expression-eval/v3.0/{shell,path,fn-date,fn-number}.json，共 107 用例。
 *
 * 管线 = InterpolationEvaluator::evaluate(template, context)，expect = 输出串 | {errorCode}
 * （code 是三端锚点，锁 code 字符串与五阶段异常载体；消息人读不入断言面）。
 * 手写语义用例已归拢进语料；PHP-only 关怀的清点见 tests/Corpus/README.md。
 */
class ExpressionEvalCorpusTest extends CanvasTestCase
{
    public const CONTRACT = 'expression-eval v3.0';

    /** 模块文件 = 求值契约语义域拆分（corpus/README.md）：外壳/路径/日期/数字函数族 */
    private const MODULES = ['shell', 'path', 'fn-date', 'fn-number'];

    private InterpolationEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new InterpolationEvaluator();
    }

    public static function evalCasesProvider(): iterable
    {
        foreach (self::MODULES as $module) {
            $document = CorpusDocument::load("expression-eval/v3.0/{$module}.json", self::CONTRACT);
            foreach ($document['cases'] as $case) {
                yield "{$module}:{$case['name']}" => [$case];
            }
        }
    }

    /**
     * @dataProvider evalCasesProvider
     *
     * @param array{name: string, template: string, context: array, expect: mixed} $case
     */
    public function testCorpusCase(array $case): void
    {
        // 语料形状：expect = 输出字符串（空串合法）| {errorCode}——is_array 是契约判别式
        if (is_array($case['expect'])) {
            $this->assertExpectedErrorCode($case);
        } else {
            $this->assertSame(
                $case['expect'],
                $this->evaluator->evaluate($case['template'], $case['context']),
                "用例 {$case['name']}：求值输出与语料期望不符"
            );
        }
    }

    /** 错误断言只落 code + 五阶段异常载体（code 是三端锚点，消息人读不入断言面） */
    private function assertExpectedErrorCode(array $case): void
    {
        $code = $case['expect']['errorCode'];
        $expectedClass = CorpusDocument::exceptionClassFor($code);

        try {
            $this->evaluator->evaluate($case['template'], $case['context']);
            $this->fail("用例 {$case['name']}：预期报 {$code} 但求值成功");
        } catch (CanvasNextExceptionInterface $e) {
            $this->assertSame($expectedClass, get_class($e), "用例 {$case['name']}：五阶段异常载体漂移");
            $this->assertSame($code, $e->getErrorCode());
        }
    }

    public function testEveryModuleFileIsPresentAndPinned(): void
    {
        // 逐模块加载即版本双写互检（CorpusDocument::load 内断言 meta.contract 精确相等）
        foreach (self::MODULES as $module) {
            $document = CorpusDocument::load("expression-eval/v3.0/{$module}.json", self::CONTRACT);
            $this->assertNotEmpty($document['cases'], "模块 {$module} 用例集不可为空");
        }
    }

    public function testCaseNamesAreUniqueAcrossModules(): void
    {
        // 用例名唯一的作用域 = 契约版本、跨模块文件（corpus/README.md）
        $names = [];
        foreach (self::MODULES as $module) {
            $document = CorpusDocument::load("expression-eval/v3.0/{$module}.json", self::CONTRACT);
            array_push($names, ...array_column($document['cases'], 'name'));
        }

        $this->assertNotEmpty($names);
        $this->assertSame($names, array_values(array_unique($names)), '语料用例名跨模块重复');
    }

    public function testDateTokensTableMatchesCorpusAuthority(): void
    {
        // dateTokens 转译表：语料为权威，PHP DateFormat 内置表向语料对齐
        // （token/语义注记逐行一致 + 样例时刻逐 token 输出一致）
        $document = CorpusDocument::load('expression-eval/v3.0/fn-date.json', self::CONTRACT);
        $dateTokens = $document['dateTokens'] ?? $this->fail('fn-date.json 缺 dateTokens 转译表');

        $table = DateFormat::tokenTable();
        $this->assertCount(count($dateTokens['rows']), $table, 'token 表行数与语料不符');

        $dt = DateFormat::parseIso($dateTokens['sample']);
        $this->assertNotNull($dt, "语料样例时刻 {$dateTokens['sample']} 解析失败");

        foreach ($dateTokens['rows'] as $i => $row) {
            $this->assertSame($row['token'], $table[$i]['token'], "token[{$i}] 与语料行序不符");
            $this->assertSame($row['semantics'], $table[$i]['semantics'], "token {$row['token']} 语义注记漂移");
            $this->assertSame($row['expect'], DateFormat::format($row['token'], $dt), "token {$row['token']} 样例输出漂移");
        }
    }
}
