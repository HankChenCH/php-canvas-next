<?php

namespace HankChen\CanvasNext\Compiler\Flow;

/**
 * 流消费策略工厂（spec §10.3）：按 mode 封闭分发（LayerFactory 同款）——
 * fixed 无 quota = 容量型（CapacityTake）、fixed 带 quota = 声明配额（QuotaTake）、
 * paged = 链尾终止（PagedTake）。前置 FlowChainValidator 保证节点形状，非法 mode 到不了这里
 */
final class FlowConsumerFactory
{
    public static function fromNode(array $node): FlowConsumer
    {
        if (($node['mode'] ?? null) === 'paged') {
            return new PagedTake();
        }

        return array_key_exists('quota', $node) ? new QuotaTake((int) $node['quota']) : new CapacityTake();
    }
}
