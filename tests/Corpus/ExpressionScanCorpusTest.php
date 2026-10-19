<?php

namespace HankChen\CanvasNext\Tests\Corpus;

use HankChen\CanvasNext\Exception\CanvasNextExceptionInterface;
use HankChen\CanvasNext\Expression\InterpolationEvaluator;
use HankChen\CanvasNext\Tests\Support\CanvasTestCase;

/**
 * 扫描契约 runner（工票 08 消费翻转）：语义 = submodule 语料
 * corpus/expression-scan/v1.0/scan.json，共 27 用例。
 *
 * 承接 scripts/scan-fixtures.php（本票退役）的自检契约，以 runner 测试形态留在实现仓：
 * 断言面 = 语料 parts 逐字段比对 + 「切分拼装逐段求值 ≡ 整串求值」互证——扫描器
 * （脚本内同构切分，与 evaluate() 扫描循环同构）与权威求值器漂移即红。open 切分
 * （未闭合 {{ 触发态）是编辑器侧表示，求值期按字面拼回。
 */
class ExpressionScanCorpusTest extends CanvasTestCase
{
    public const CONTRACT = 'expression-scan v1.0';

    private InterpolationEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new InterpolationEvaluator();
    }

    public static function scanCasesProvider(): iterable
    {
        $document = CorpusDocument::load('expression-scan/v1.0/scan.json', self::CONTRACT);
        foreach ($document['cases'] as $case) {
            yield $case['name'] => [$case];
        }
    }

    /**
     * @dataProvider scanCasesProvider
     *
     * @param array{name: string, template: string, expect: array{parts: list<array<string, mixed>>}} $case
     */
    public function testCorpusCase(array $case): void
    {
        $parts = $this->scanParts($case['template']);

        // 语料断言面：切分逐字段一致（kind/text/raw/expr/error；位置字段不入语料，
        // PHP 字节索引与 JS 码元索引不同空间）
        $this->assertSame(
            $case['expect']['parts'],
            $parts,
            "用例 {$case['name']}：扫描切分与语料期望不符"
        );

        $this->assertComposedEvaluationMatchesWhole($case['name'], $case['template'], $parts);
    }

    /**
     * 自检契约（自 scan-fixtures.php verifyScanCase 承接）：切分拼装逐段求值的结果
     * 必须与整串求值一致（含未闭合尾按字面拼回）；带错误信号的用例整串求值必须抛
     * 同码错误。上下文键值选取让边界错误可显形——切分错位导致查到相邻键即偏离。
     *
     * @param list<array<string, mixed>> $parts
     */
    private function assertComposedEvaluationMatchesWhole(string $name, string $template, array $parts): void
    {
        $context = [
            'row' => ['name' => '张三', 'user' => ['city' => '杭州']],
            '$index' => 3,
            'orderNo' => 'A2026-001',
            'nested' => ['k' => 'v'],
            '订单号' => 'A2026-CN',
        ];

        $hasError = false;
        $composed = '';
        foreach ($parts as $part) {
            switch ($part['kind']) {
                case 'literal':
                case 'open':
                    // 未闭合尾求值期按字面拼回
                    $composed .= $part['text'];
                    break;
                default:
                    if ($part['error'] !== null) {
                        $hasError = true;
                        break;
                    }
                    $composed .= $this->evaluator->evaluate('{{' . $part['expr'] . '}}', $context);
            }
        }

        try {
            $evaluated = $this->evaluator->evaluate($template, $context);
            $this->assertFalse($hasError, "用例 {$name}：语料错误信号但整串求值成功（{$evaluated}）");
            $this->assertSame(
                $evaluated,
                $composed,
                "用例 {$name}：切分拼装求值与整串求值不符（扫描面与权威求值器漂移）"
            );
        } catch (CanvasNextExceptionInterface $e) {
            $this->assertTrue(
                $hasError && $e->getErrorCode() === 'expression_syntax_error',
                "用例 {$name}：错误码 {$e->getErrorCode()} 与语料错误信号不符"
            );
        }
    }

    /**
     * 脚本内扫描器（与求值器 evaluate() 扫描循环同构；编辑器 TS 侧同款）。
     * 未闭合 {{ 除按字面外另立 open 切分（编辑器补全触发态），拼回语义与字面等价；
     * 产出即语料 part 形态（键序与 scan.json 逐字段对应）。
     *
     * @return list<array<string, null|string>>
     */
    private function scanParts(string $template): array
    {
        $parts = [];
        $pos = 0;
        $length = strlen($template);

        while (($open = strpos($template, '{{', $pos)) !== false) {
            $close = strpos($template, '}}', $open + 2);
            if ($close === false) {
                if ($open > $pos) {
                    $parts[] = ['kind' => 'literal', 'text' => substr($template, $pos, $open - $pos)];
                }
                $text = substr($template, $open);
                $parts[] = ['kind' => 'open', 'text' => $text, 'expr' => trim(substr($text, 2))];
                return $parts;
            }

            if ($open > $pos) {
                $parts[] = ['kind' => 'literal', 'text' => substr($template, $pos, $open - $pos)];
            }
            $raw = substr($template, $open + 2, $close - $open - 2);
            $expr = trim($raw);
            $parts[] = [
                'kind' => 'fragment',
                'raw' => $raw,
                'expr' => $expr,
                'error' => ($expr === '' || in_array('', explode('.', $expr), true))
                    ? 'expression_syntax_error'
                    : null,
            ];
            $pos = $close + 2;
        }

        if ($pos < $length) {
            $parts[] = ['kind' => 'literal', 'text' => substr($template, $pos)];
        }

        return $parts;
    }

    public function testCasesAreWellFormed(): void
    {
        // 语料形状（键集/逐 kind part 键集/code 白名单）由 canvasctl validate 严格分派；
        // 本仓只钉用例集非空与用例名唯一（双端按名对齐）
        $document = CorpusDocument::load('expression-scan/v1.0/scan.json', self::CONTRACT);
        $names = array_column($document['cases'], 'name');

        $this->assertNotEmpty($names, '扫描用例集不可为空');
        $this->assertSame($names, array_values(array_unique($names)), '扫描用例名必须唯一');
    }
}
