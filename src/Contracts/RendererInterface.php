<?php

namespace HankChen\CanvasNext\Contracts;

use HankChen\CanvasNext\Canvas;
use HankChen\CanvasNext\Runtime\Cancellation;

/**
 * 渲染器契约：Renderer 是主动方，向 Canvas 拉取结构树完成渲染
 *
 * 公开接口仅此一个方法；通用遍历/分派/定位收在 AbstractRenderer 模板方法，
 * 具体后端只实现绘制原语。产物类型由后端决定。
 * 渲染期控制面（ADR 0008）：$ctx 以显式首参随渲染调用向下传递，严格非空——
 * 不受控调用方显式传零值 NullCancellation（对齐 Go Render(ctx, canvas) 惯例）
 *
 * @api
 */
interface RendererInterface
{
    /**
     * @param Cancellation $ctx 渲染期取消信号（advisory only，检查点轮询 + 抛错）
     * @return mixed 后端产物（ImageRenderer 返回 ImageInterface，PDF 后端返回字节流…）
     */
    public function render(Cancellation $ctx, Canvas $canvas): mixed;
}
