<?php

namespace HankChen\CanvasNext\Exception;

use RuntimeException;

/**
 * 文档期错误（spec §10.4，阶段模型四段扩为五段：解码期 / 填充期 / 文档期 / 分页期 / 绘制期——
 * 段是语义域非时序切片：链校验先于填充、分配后于填充）
 *
 * 承载 code（钉稿即全量，追加走 spec 版本）：flow_chain_invalid / flow_rows_path_inconsistent。
 * 分配期超容不落本类——复用 paginate 段 content_overflow（同一装箱数学同一失败语义，
 * 由 DocumentCompiler 抛出，不换载体不换段）
 */
class FlowException extends RuntimeException implements CanvasNextExceptionInterface
{
    public function __construct(
        private readonly string $errorCode,
        string $message
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
