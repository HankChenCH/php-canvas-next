<?php

namespace HankChen\CanvasNext\Exception;

use RuntimeException;

/**
 * 绘制期错误（spec §5.1）：render 处下载/落盘/QR 生成失败，渲染面丢弃、无产物逃逸
 *
 * 承载 code：resource_download_failed / resource_save_failed / qr_generate_failed
 */
class MaterializeException extends RuntimeException implements CanvasNextExceptionInterface
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
