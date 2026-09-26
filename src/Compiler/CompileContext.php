<?php

namespace HankChen\CanvasNext\Compiler;

use HankChen\CanvasNext\Canvas;

/**
 * 编译步骤间通信载体（spec §2）：字段封闭（canvas + dataset + options），
 * 字段演进走 spec 版本。任何跨步骤传递私有元数据的需求都是设计错误信号——
 * pass 边界状态必须 wire 可表达（graph()/fromGraph() 往返无语义损失），
 * 正解是去 wire 面立声明键
 */
final class CompileContext
{
    /**
     * @param Canvas $canvas 进入本步骤的画布（声明态入参或上游步骤产物）
     * @param mixed $dataset 绑定数据集；null = 未绑（hydrate 恒等直通）
     * @param array $options 编译选项（现含分页选项占位，spec §4.2 待钉稿）
     */
    public function __construct(
        public readonly Canvas $canvas,
        public readonly mixed $dataset = null,
        public readonly array $options = [],
    ) {
    }
}
