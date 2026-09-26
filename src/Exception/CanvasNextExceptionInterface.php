<?php

namespace HankChen\CanvasNext\Exception;

/**
 * 三端错误分类面（spec §5.1）：稳定 code 的承载者
 *
 * 错误按阶段分三类，每类一个稳定标识符（code）——code 是三端一致性的抓手：
 * 消息可本地化，code 稳定；三端各自映射到本地异常类型
 * （PHP 异常类 / Go error / web Error 子类，UnknownLayerTypeError 先例）
 */
interface CanvasNextExceptionInterface
{
    /** 稳定错误标识符，清单见 spec §5.2 */
    public function getErrorCode(): string;
}
