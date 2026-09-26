<?php

namespace HankChen\CanvasNext\Expression;

use HankChen\CanvasNext\Contracts\ExpressionEvaluatorInterface;
use HankChen\CanvasNext\Exception\ExpandException;

/**
 * 默认求值器：mustache 风格外壳 + 受限表达式子集（零依赖纯 PHP，spec §3.6）
 *
 * v2 子集 = 变量点路径取值：{{row.x}} / {{row.user.city}} / {{根键}} /
 * {{$index}} / {{$root.根键}}；不含函数/条件/格式化（语义归求值器 spec effort，
 * 届时以共享 fixture 钉死）。{{ }} 划定表达式片段，其余字面直通；
 * 不含 {{ 的标记串 = 整串字面；未闭合的 {{ 按字面处理
 *
 * 缺字段/null 信号 = 空串（展开器按字段类施加兜底/报错）；
 * 类型不匹配抛 ExpandException(expression_type_mismatch)；
 * row. 前缀在非行上下文抛 ExpandException(expression_row_outside_loop)
 */
final class InterpolationEvaluator implements ExpressionEvaluatorInterface
{
    public function evaluate(string $template, array $context): string
    {
        $out = '';
        $pos = 0;
        $length = strlen($template);

        while (($open = strpos($template, '{{', $pos)) !== false) {
            $close = strpos($template, '}}', $open + 2);
            if ($close === false) {
                break; // 未闭合：剩余全字面
            }

            $out .= substr($template, $pos, $open - $pos);
            $out .= $this->resolveFragment(trim(substr($template, $open + 2, $close - $open - 2)), $context);
            $pos = $close + 2;
        }

        return $out . substr($template, $pos);
    }

    private function resolveFragment(string $expr, array $context): string
    {
        if ($expr === '') {
            throw new ExpandException('expression_syntax_error', 'expression_syntax_error: 空表达式片段');
        }

        $segments = explode('.', $expr);
        if (in_array('', $segments, true)) {
            throw new ExpandException('expression_syntax_error', "expression_syntax_error: 非法路径 {$expr}");
        }

        $head = array_shift($segments);

        return match ($head) {
            // row. 前缀 = 当前行命名空间；非行上下文（无 'row' 键）= 报错
            'row' => !array_key_exists('row', $context)
                ? throw new ExpandException('expression_row_outside_loop', 'expression_row_outside_loop: row. 前缀出现在非行上下文')
                : $this->navigateValue($context['row'], $segments, $expr),
            // $root 显式取根，与裸名等价
            '$root' => $this->navigateValue($context, $segments, $expr),
            // 裸名（含 $index 等 $ 保留名）= 上下文根键
            default => $this->navigateValue($context[$head] ?? null, $segments, $expr),
        };
    }

    /**
     * 点路径下钻：缺键/null 记缺字段（空串信号）；标量中途下钻或
     * 数组/对象落到标量位记类型不匹配（fail-fast）
     */
    private function navigateValue(mixed $value, array $segments, string $expr): string
    {
        foreach ($segments as $segment) {
            if ($value === null) {
                return ''; // 中途缺失与缺字段同信号
            }

            if (!is_array($value) || !array_key_exists($segment, $value)) {
                if (is_array($value)) {
                    return ''; // 缺字段信号
                }

                throw new ExpandException('expression_type_mismatch', "expression_type_mismatch: {$expr}");
            }

            $value = $value[$segment];
        }

        if (is_array($value)) {
            throw new ExpandException('expression_type_mismatch', "expression_type_mismatch: {$expr}");
        }

        return $this->stringify($value);
    }

    private function stringify(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
