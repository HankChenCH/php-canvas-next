<?php

namespace HankChen\CanvasNext\Exception;

use InvalidArgumentException;

/**
 * 解码期错误（spec §5.1）：fromGraph 处 graph 结构非法，与数据集无关
 *
 * 承载 code：unknown_layer_type / template_rows_conflict / rows_path_missing
 */
class DecodeException extends InvalidArgumentException implements CanvasNextExceptionInterface
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
