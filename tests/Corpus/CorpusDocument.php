<?php

namespace HankChen\CanvasNext\Tests\Corpus;

use HankChen\CanvasNext\Compiler\Diagnostic;
use HankChen\CanvasNext\Exception\DecodeException;
use HankChen\CanvasNext\Exception\FlowException;
use HankChen\CanvasNext\Exception\HydrateException;
use HankChen\CanvasNext\Exception\PaginateException;
use RuntimeException;

/**
 * 语料仓文档加载器（工票 06 消费翻转）：四契约 runner 的共享取数面。
 *
 * 语义权威 = submodule canvas-semantics/corpus/（本仓 pin 消费，语料先行）；
 * 加载即双写互检——meta.contract 与 runner 钉定的期望版本串精确相等，
 * 语料升版本（次版本纯增/主版本语义变更）未同步 runner 常量即红。
 */
final class CorpusDocument
{
    /** 语料仓 submodule 在本仓内的检出路径（包根相对） */
    public const SUBMODULE_DIR = 'canvas-semantics';

    /**
     * 错误 code → 五阶段异常载体（四契约语料可达面，与 spec/error-codes.json 对齐）。
     * code 是三端锚点；载体是本端 API 面。绘制期 5 code 与 frames_empty（入口守卫）
     * 语料不可达，不登记。
     */
    private const EXCEPTION_BY_CODE = [
        // 求值段（求值器内部错误，全部挂填充段载体）
        'expression_syntax_error' => HydrateException::class,
        'expression_type_mismatch' => HydrateException::class,
        'expression_row_outside_loop' => HydrateException::class,
        // 解码期
        'template_rows_conflict' => DecodeException::class,
        'rows_path_missing' => DecodeException::class,
        // 填充期
        'rows_path_invalid' => HydrateException::class,
        'reserved_root_key' => HydrateException::class,
        'expression_empty_resource' => HydrateException::class,
        'expression_resource_single_ref' => HydrateException::class,
        // 文档期
        'flow_chain_invalid' => FlowException::class,
        'flow_rows_path_inconsistent' => FlowException::class,
        // 分页期（分配期超容/多表复用本段，不换 code 不换段）
        'content_overflow' => PaginateException::class,
        'paginate_target_invalid' => PaginateException::class,
        'page_geometry_invalid' => PaginateException::class,
    ];

    public static function root(): string
    {
        $root = dirname(__DIR__, 2) . '/' . self::SUBMODULE_DIR;

        if (!is_dir($root . '/corpus')) {
            throw new RuntimeException(
                "语料仓 submodule 未检出：{$root}（先 git submodule update --init）"
            );
        }

        return $root;
    }

    /**
     * 加载语料模块文件并钉死契约版本：meta.contract ≠ 期望串即红（双写互检）。
     *
     * @return array{meta: array, cases: list<array<string, mixed>>}
     */
    public static function load(string $relativePath, string $expectedContract): array
    {
        $path = self::root() . '/corpus/' . $relativePath;

        if (!is_file($path)) {
            throw new RuntimeException("语料模块文件不存在: {$relativePath}（submodule pin 落后？）");
        }

        $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $contract = $document['meta']['contract'] ?? null;
        if ($contract !== $expectedContract) {
            throw new RuntimeException(
                "语料 {$relativePath} 契约版本「" . ($contract ?? '缺键') . "」与 runner 钉定「{$expectedContract}」不符："
                . '语料升版本须同步更新对应 runner 的版本常量（语义变更可审）'
            );
        }

        return $document;
    }

    /** 五阶段 code → 异常载体；新 code 未登记即抛（同步注册表与本表） */
    public static function exceptionClassFor(string $code): string
    {
        return self::EXCEPTION_BY_CODE[$code]
            ?? throw new RuntimeException("错误 code {$code} 未登记进 CorpusDocument::EXCEPTION_BY_CODE（新 code 须同步注册表）");
    }

    /**
     * 诊断序列 → 语料断言面（code + path，null path 键省略），与导出脚本同构。
     *
     * @param list<Diagnostic> $warnings
     * @return list<array{code: string, path?: string}>
     */
    public static function exportedWarnings(array $warnings): array
    {
        return array_map(
            static fn (Diagnostic $d) => array_filter(
                ['code' => $d->code, 'path' => $d->path],
                static fn ($v) => $v !== null
            ),
            $warnings
        );
    }

    /**
     * wire 字节面编码：产物/期望 graph 统一编码归一后比对（编码串相等 = wire 字节面相等）。
     *
     * json_decode 的数字类型（JSON 不分 int/float）不进断言面——PHP 侧 float(1)
     * 与语料解码侧 int(1) 语义同一，直接 assertSame 会把 wire 之下的 PHP 内在类型误判为漂移
     */
    public static function wireBytes(array $graph): string
    {
        return json_encode(
            $graph,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
