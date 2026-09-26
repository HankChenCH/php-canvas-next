<?php

namespace HankChen\CanvasNext\Exception;

use RuntimeException;

/**
 * 填充期错误（数据填充 hydrate，spec §5.2）：hydrate 处数据集与标记字段的形状错误，渲染从未开始
 *
 * 承载 code：rows_path_invalid / expression_row_outside_loop / reserved_root_key /
 * expression_empty_resource / expression_type_mismatch（求值器内部错误 code 归求值器 spec）
 */
class HydrateException extends RuntimeException implements CanvasNextExceptionInterface
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
