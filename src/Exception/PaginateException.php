<?php

namespace HankChen\CanvasNext\Exception;

use RuntimeException;

/**
 * 分页期错误（编译步骤② paginate，spec §4.2.6/§5.2）：页几何与装箱错误，渲染从未开始
 *
 * 承载 code（钉稿即全量，追加走 spec 版本）：content_overflow / paginate_target_invalid /
 * page_geometry_invalid
 */
class PaginateException extends RuntimeException implements CanvasNextExceptionInterface
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
