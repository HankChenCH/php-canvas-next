<?php

namespace HankChen\CanvasNext\Expression;

use HankChen\CanvasNext\Contracts\ExpressionEvaluatorInterface;
use HankChen\CanvasNext\Exception\HydrateException;

/**
 * 默认求值器：mustache 风格外壳 + 受限表达式子集（零依赖纯 PHP）
 *
 * v2 子集 = 变量点路径取值 + 内建函数调用（v1 白名单 date/number/fixed/percent）：
 * {{row.x}} / {{row.user.city}} / {{根键}} / {{$index}} / {{$root.根键}} /
 * {{date(row.d, 'YYYY年MM月DD日')}} / {{number(row.v, 2)}} / {{fixed(row.f)}} / {{percent(row.p)}}。
 * 语法与语义钉稿 = .scratch/expression-type-methods/spec.md，三端共享 fixture 锁默认实现
 *
 * 分轴哲学（spec §0）：值面宽松——缺字段/null = 空串信号、不可解析 = 原文直通
 * （打印物场景数据不丢）；文法/签名面 fail-fast——未知函数/参数个数/结构错是
 * 数据无关的确定性错误，报错优于整批静默输出错误内容（expression_syntax_error，零新 code）。
 * {{ }} 划定表达式片段，其余字面直通；不含 {{ 的标记串 = 整串字面；
 * 未闭合的 {{ 按字面处理；首个 }} 收口（引号不感知，片段内禁 }} 字面）
 */
final class InterpolationEvaluator implements ExpressionEvaluatorInterface
{
    /** v1 内建函数白名单：名 => [最小参, 最大参]（spec §2；大小写敏感、不保留字化） */
    private const FUNCTIONS = [
        'date' => [1, 2],
        'number' => [1, 2],
        'fixed' => [1, 2],
        'percent' => [1, 2],
    ];

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

    /**
     * 片段分类（spec §1.2 ⑦）：trim 后首个 token（ASCII 特界符/空白前）其后紧跟
     * 可选空白与 ( 即调用，否则路径——`foo(bar)` / `日期(bar)` / `foo (bar)` 均按
     * 调用分类（白名单校验后置），{{date}} 无 ( 恒为路径，数据集根键叫 date 合法
     */
    private function resolveFragment(string $expr, array $context): string
    {
        if ($expr === '') {
            throw new HydrateException('expression_syntax_error', 'expression_syntax_error: 空表达式片段');
        }

        preg_match("/^[^()',.\s]+/", $expr, $matches);
        $head = $matches[0] ?? '';
        if (preg_match('/^\s*\(/', substr($expr, strlen($head)))) {
            return $this->evaluateCall($expr, strlen($head), $context);
        }

        return $this->resolvePathFragment($expr, $context);
    }

    /** 路径片段：既有 v2 点路径取值，行为逐字节不变 */
    private function resolvePathFragment(string $expr, array $context): string
    {
        $segments = explode('.', $expr);
        if (in_array('', $segments, true)) {
            throw new HydrateException('expression_syntax_error', "expression_syntax_error: 非法路径 {$expr}");
        }

        [$base, $rest] = $this->pathBase($expr, $segments, $context);

        return $this->stringify($this->drill($base, $rest, $expr));
    }

    /**
     * head 三分类（既有钉稿）：row. = 当前行命名空间（非行上下文报错）、
     * $root = 显式取根、裸名（含 $index 等 $ 保留名）= 上下文根键。
     * 片段路径与调用首参共用；返回 [基底值, 余下段]
     *
     * @return array{mixed, list<string>}
     */
    private function pathBase(string $expr, array $segments, array $context): array
    {
        $head = array_shift($segments);

        return match ($head) {
            'row' => !array_key_exists('row', $context)
                ? throw new HydrateException('expression_row_outside_loop', 'expression_row_outside_loop: row. 前缀出现在非行上下文')
                : [$context['row'], $segments],
            '$root' => [$context, $segments],
            default => [$context[$head] ?? null, $segments],
        };
    }

    /**
     * 点路径下钻：缺键/null 记缺字段（null 信号）；标量中途下钻或
     * 数组/对象落到标量位记类型不匹配（fail-fast）
     */
    private function drill(mixed $value, array $segments, string $expr): mixed
    {
        foreach ($segments as $segment) {
            if ($value === null) {
                return null; // 中途缺失与缺字段同信号
            }

            if (!is_array($value) || !array_key_exists($segment, $value)) {
                if (is_array($value)) {
                    return null; // 缺字段信号
                }

                throw new HydrateException('expression_type_mismatch', "expression_type_mismatch: {$expr}");
            }

            $value = $value[$segment];
        }

        if (is_array($value)) {
            throw new HydrateException('expression_type_mismatch', "expression_type_mismatch: {$expr}");
        }

        return $value;
    }

    /** 调用片段：白名单校验（未知函数 fail-fast）→ 实参表解析 → 签名校验 → 求值 */
    private function evaluateCall(string $expr, int $nameLen, array $context): string
    {
        $name = substr($expr, 0, $nameLen);
        if (!isset(self::FUNCTIONS[$name])) {
            throw new HydrateException('expression_syntax_error', "expression_syntax_error: 未知函数 {$name}");
        }

        $args = $this->parseCallArgs($expr, $nameLen);
        [$minArity, $maxArity] = self::FUNCTIONS[$name];
        $count = count($args);
        if ($count < $minArity || $count > $maxArity) {
            throw new HydrateException('expression_syntax_error', "expression_syntax_error: 参数个数不符 {$name}(...)");
        }

        // 首参文法层钉死为路径：'格式化常量' 是伪场景，误用更早一层被抓住（spec §1.2 ①）
        if ($args[0]['type'] !== 'path') {
            throw new HydrateException('expression_syntax_error', 'expression_syntax_error: 首参必须为路径');
        }

        return $name === 'date'
            ? $this->callDate($name, $args, $context)
            : $this->callNumber($name, $args, $context);
    }

    /**
     * 实参表解析（spec §1.1）：实参 := 路径 | 字符串 | 数字，实参间 WS* ',' WS*；
     * 尾逗号与空参数位 = 语法错；字符串仅单引号、'' 翻倍转义；数字无符号无指数；
     * 零参调用文法合法（为 now() 留门，个数归签名层拒）
     *
     * @return list<array{type: 'path'|'string'|'number', value: string}>
     */
    private function parseCallArgs(string $expr, int $nameLen): array
    {
        $len = strlen($expr);
        $pos = $this->skipWs($expr, $nameLen);
        if ($pos >= $len || $expr[$pos] !== '(') {
            throw new HydrateException('expression_syntax_error', 'expression_syntax_error: 括号不平衡');
        }
        $pos = $this->skipWs($expr, $pos + 1);

        $args = [];
        if ($pos < $len && $expr[$pos] === ')') {
            $pos++;
        } else {
            while (true) {
                if ($pos >= $len) {
                    throw new HydrateException('expression_syntax_error', 'expression_syntax_error: 括号不平衡');
                }
                $args[] = $this->parseCallArg($expr, $pos, $pos);
                $pos = $this->skipWs($expr, $pos);
                if ($pos >= $len) {
                    throw new HydrateException('expression_syntax_error', 'expression_syntax_error: 括号不平衡');
                }
                if ($expr[$pos] === ',') {
                    $pos = $this->skipWs($expr, $pos + 1);
                    if ($pos < $len && $expr[$pos] === ')') {
                        throw new HydrateException('expression_syntax_error', 'expression_syntax_error: 空参数位');
                    }
                    continue;
                }
                if ($expr[$pos] === ')') {
                    $pos++;
                    break;
                }
                throw new HydrateException('expression_syntax_error', 'expression_syntax_error: 调用实参语法错');
            }
        }

        if ($this->skipWs($expr, $pos) !== $len) {
            throw new HydrateException('expression_syntax_error', 'expression_syntax_error: 调用后有多余内容');
        }

        return $args;
    }

    /** 单个实参：按首字符三分派（' 串 / 数字 / 路径），$end 回写消费终点 */
    private function parseCallArg(string $expr, int $pos, int &$end): array
    {
        $len = strlen($expr);
        $ch = $expr[$pos];

        // 字符串字面量：仅单引号，'' 翻倍转义（ICU/SQL 风格），其余任意字符合法
        if ($ch === "'") {
            $pos++;
            $buf = '';
            while (true) {
                if ($pos >= $len) {
                    throw new HydrateException('expression_syntax_error', 'expression_syntax_error: 字符串字面量未闭合');
                }
                if ($expr[$pos] === "'") {
                    if ($pos + 1 < $len && $expr[$pos + 1] === "'") {
                        $buf .= "'";
                        $pos += 2;
                        continue;
                    }
                    $pos++;
                    break;
                }
                $buf .= $expr[$pos];
                $pos++;
            }
            $end = $pos;

            return ['type' => 'string', 'value' => $buf];
        }

        // 数字字面量：无符号十进制 数字+ ['.' 数字+]，无指数（负数/布尔字面量写了即语法错）
        if ($ch >= '0' && $ch <= '9') {
            $start = $pos;
            while ($pos < $len && $expr[$pos] >= '0' && $expr[$pos] <= '9') {
                $pos++;
            }
            if ($pos < $len && $expr[$pos] === '.' && $pos + 1 < $len && $expr[$pos + 1] >= '0' && $expr[$pos + 1] <= '9') {
                $pos++;
                while ($pos < $len && $expr[$pos] >= '0' && $expr[$pos] <= '9') {
                    $pos++;
                }
            }
            $end = $pos;

            return ['type' => 'number', 'value' => substr($expr, $start, $pos - $start)];
        }

        // 路径：至顶层 ',' 或 ')' 止后 trim（段内空白属于键名，存量画布字节兼容）
        $start = $pos;
        while ($pos < $len && $expr[$pos] !== ',' && $expr[$pos] !== ')') {
            $pos++;
        }
        $raw = trim(substr($expr, $start, $pos - $start));
        if ($raw === '') {
            throw new HydrateException('expression_syntax_error', 'expression_syntax_error: 空参数位');
        }
        $end = $pos;

        return ['type' => 'path', 'value' => $raw];
    }

    /**
     * date 求值（spec §3）：可解析输入按格式渲染；缺失/null/空串 = 空串；
     * 不可解析/数值 = 原文直通；bool/容器落参数位 = type_mismatch
     */
    private function callDate(string $name, array $args, array $context): string
    {
        if (isset($args[1]) && $args[1]['type'] !== 'string') {
            throw new HydrateException('expression_syntax_error', "expression_syntax_error: {$name} 第二参须为字符串字面量");
        }
        $format = DateFormat::expandPreset($args[1]['value'] ?? DateFormat::DEFAULT_FORMAT);

        $value = $this->resolveCallArgValue($args[0], $context, $name);
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            if ($value === '') {
                return '';
            }
            $dt = DateFormat::parseIso($value);

            return $dt === null ? $value : DateFormat::format($format, $dt);
        }

        // 数值：时间戳不收，按字符串化后原文直通（常规区间字符串化双端一致）
        return NumberFormat::fromNumber($value);
    }

    /**
     * number/fixed/percent 求值（spec §4）：严格十进制串与常规区间数值进管线；
     * 缺失/null/空串 = 空串；非空不可解析 = 原文直通；bool/容器 = type_mismatch
     */
    private function callNumber(string $name, array $args, array $context): string
    {
        $n = null;
        if (isset($args[1])) {
            if ($args[1]['type'] !== 'number') {
                throw new HydrateException('expression_syntax_error', "expression_syntax_error: {$name} 第二参须为数字字面量");
            }
            $raw = $args[1]['value'];
            if (!ctype_digit($raw)) {
                throw new HydrateException('expression_syntax_error', "expression_syntax_error: 数字参数须为整数 n={$raw}");
            }
            $n = (int) $raw;
            if ($n > NumberFormat::N_MAX) {
                throw new HydrateException('expression_syntax_error', "expression_syntax_error: 数字参数超界 n={$n}");
            }
        }

        $value = $this->resolveCallArgValue($args[0], $context, $name);
        if ($value === null || $value === '') {
            return '';
        }

        $dec = is_string($value)
            ? NumberFormat::fromStrictString($value)
            : NumberFormat::fromNumber($value);
        if ($dec === null) {
            return $value; // 不可解析 → 原文直通
        }

        return match ($name) {
            'number' => NumberFormat::group($dec, $n),
            'fixed' => NumberFormat::fixedPoint($dec, $n ?? 2),
            'percent' => NumberFormat::percent($dec, $n ?? 0),
        };
    }

    /**
     * 调用首参取原始值：复用路径遍历（缺键/null → null 信号、结构错同码），
     * bool/数组/对象落函数参数位 = type_mismatch（bool 字符串化双端不一致，直通无法对齐）
     */
    private function resolveCallArgValue(array $arg, array $context, string $name): mixed
    {
        $path = $arg['value'];
        $segments = explode('.', $path);
        if (in_array('', $segments, true)) {
            throw new HydrateException('expression_syntax_error', "expression_syntax_error: 非法路径 {$path}");
        }

        [$base, $rest] = $this->pathBase($path, $segments, $context);
        $value = $this->drill($base, $rest, $path);
        if ($value === null) {
            return null;
        }
        if (is_bool($value) || is_array($value) || is_object($value)) {
            throw new HydrateException('expression_type_mismatch', "expression_type_mismatch: {$name}({$path})");
        }

        return $value;
    }

    private function skipWs(string $expr, int $pos): int
    {
        $len = strlen($expr);
        while ($pos < $len && ($expr[$pos] === ' ' || $expr[$pos] === "\t" || $expr[$pos] === "\n" || $expr[$pos] === "\r")) {
            $pos++;
        }

        return $pos;
    }

    private function stringify(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
