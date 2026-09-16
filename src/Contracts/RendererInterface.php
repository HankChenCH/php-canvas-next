<?php

namespace HankChen\CanvasNext\Contracts;

use HankChen\CanvasNext\Canvas;

/**
 * 渲染器契约：Renderer 是主动方，向 Canvas 拉取结构树完成渲染
 *
 * 公开接口仅此一个方法；通用遍历/分派/定位收在 AbstractRenderer 模板方法，
 * 具体后端只实现绘制原语。产物类型由后端决定
 *
 * @api
 */
interface RendererInterface
{
    /**
     * @return mixed 后端产物（ImageRenderer 返回 ImageInterface，PDF 后端返回字节流…）
     */
    public function render(Canvas $canvas): mixed;
}
