<?php

namespace HankChen\CanvasNext\Runtime;

/**
 * 渲染期取消信号（只读端）：随渲染调用以显式首参 ctx 向下传递的控制面
 * （render-cancellation 工票 02，ADR 0008）
 *
 * 契约是 advisory only（意译自 amp "Cancellations are advisory only"）：
 * 信号只请求停止，不保证即时生效——检查点之间的阻塞段（如下载中的 socket 等待）
 * 不被取消打断，只受自身超时约束；唯一真实咬合点是下载侧按 deadline 余量换算
 * socket 超时（DefaultDownloader）。持有信号者无取消权，取消权在 CancellationSource
 *
 * deadline() 返回绝对 Unix 时间戳（秒，float），null = 无截止时间；
 * 检查点调 throwIfRequested() 抛 MaterializeException：
 * code render_cancelled（人为取消）/ render_deadline_exceeded（截止时间已到）
 *
 * @api
 */
interface Cancellation
{
    /** 取消是否已被请求 */
    public function isRequested(): bool;

    /** 检查点抛点：已请求即抛 MaterializeException，未请求静默返回 */
    public function throwIfRequested(): void;

    /** 绝对 Unix 时间戳形式的截止时间，null = 无 */
    public function deadline(): ?float;
}
