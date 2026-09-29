<?php

namespace HankChen\CanvasNext\Exception;

use RuntimeException;

/**
 * 绘制期错误（spec §5.1）：render 处下载/落盘/QR 生成失败，渲染面丢弃、无产物逃逸
 *
 * 承载 code：resource_download_failed / resource_save_failed / qr_generate_failed
 * 以及渲染期控制面两 code（ADR 0008）：render_deadline_exceeded / render_cancelled
 * （人为取消时源注入的原因经 $previous 透出）
 */
class MaterializeException extends RuntimeException implements CanvasNextExceptionInterface
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
