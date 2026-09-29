<?php

namespace HankChen\CanvasNext\Compiler\Flow;

/**
 * 流消费策略（spec §10.3）：声明面封闭（链节点是可序列化数据）、策略多态藏实现内部
 * （FlowConsumerFactory 按 mode 分发，LayerFactory 同款），ADR 0009。
 *
 * 分配权威是共享 remaining 序列：本接口只声明「吃多少」，不改动序列——
 * 切份额由调用方统一 array_splice 执行（逐节点 take 后切份额）
 */
interface FlowConsumer
{
    /**
     * 本节点自 remaining 头部吃掉的行数（0 .. count(remaining)）
     *
     * @param FrameCapacity $capacity 目标帧可用区
     * @param list<array<string, mixed>> $remaining 共享权威行 graph 序列（行高已定稿）
     */
    public function take(FrameCapacity $capacity, array $remaining): int;
}
