<?php

namespace HankChen\CanvasNext\Layer;

/**
 * 二维码图层：只存二维码内容，图像由 ResourceResolver 在渲染前
 * 通过 endroid/qr-code 物化为本地 PNG
 */
class QrCodeLayer extends AbstractLayer
{
    public const TYPE = 'QrCodeLayer';

    protected string $name = self::TYPE;

    private string $qrText = '';

    /**
     * 数据表达式标记（spec §3.1）：非 null = 已标记（valueType='ExpressionValue'），
     * 码内容由填充阶段按行求值；null = 字面直通
     */
    private ?string $expression = null;

    private ?string $resolvedSrc = null;

    public function setText(string $content): static
    {
        $this->qrText = $content;
        $this->resolvedSrc = null;
        $this->expression = null; // 字面赋值解除标记

        return $this;
    }

    public function getText(): string
    {
        return $this->qrText;
    }

    /**
     * 标记数据表达式（spec §3.1）：value 恒镜像表达式原文；
     * expression 键条件写键——未标记保持现状两键，保 Go↔PHP 字节 parity
     */
    public function setExpression(string $expression): static
    {
        $this->expression = $expression;
        $this->qrText = $expression;
        $this->resolvedSrc = null;

        return $this;
    }

    /** 已标记的数据表达式；未标记返回 null */
    public function getExpression(): ?string
    {
        return $this->expression;
    }

    /**
     * @internal 仅供 ResourceResolver 回写物化结果
     */
    public function setResolvedSrc(string $src): static
    {
        $this->resolvedSrc = $src;

        return $this;
    }

    public function getResolvedSrc(): ?string
    {
        return $this->resolvedSrc;
    }

    public function getHeight(): int
    {
        // 未物化且未声明高度时按宽度兜底，避免 0 高度盒子导致渲染报错
        if (!$this->autoHeight && $this->height > 0) {
            return $this->height;
        }

        return $this->getWidth();
    }

    /**
     * 二维码内容边长 = min(内容区宽, 内容区高)：内切于内容盒的正方形
     * （quiet zone 语义——padding 留白即码外静区），≤0 时绘制端只画盒
     */
    public function getContentSide(): int
    {
        return min($this->getContentWidth(), $this->getContentHeight());
    }

    /**
     * 内容盒内二维码的绘制起点（对齐 + padding，镜像 ImageLayer::getImageOrigin：
     * 内容尺寸换成内切边长），纯布局计算
     *
     * @return array{0: int, 1: int} [x, y]
     */
    public function getQrOrigin(): array
    {
        $side = $this->getContentSide();

        $posx = match ($this->horizontalAlign) {
            'left' => $this->padding['left'],
            'center' => ($this->getWidth() - $side) / 2,
            'right' => $this->getWidth() - $side,
            default => 0,
        };

        $posy = match ($this->verticalAlign) {
            'top' => $this->padding['top'],
            'center' => ($this->getHeight() - $side) / 2,
            'bottom' => $this->getHeight() - $side,
            default => 0,
        };

        return [intval($posx), intval($posy)];
    }

    public function graph(): array
    {
        $graph = parent::graph();

        // 无损：value 始终携带内容，与是否已生成图像无关
        $graph['data'] = $this->expression !== null
            ? ['valueType' => 'ExpressionValue', 'expression' => $this->expression, 'value' => $this->qrText]
            // 现状两键形态（条件写键）
            : ['valueType' => 'StaticValue', 'value' => $this->qrText];

        return $graph;
    }

    public static function fromGraph(array $graph): static
    {
        $layer = parent::fromGraph($graph);

        $data = $graph['data'] ?? [];
        if (($data['valueType'] ?? null) === 'ExpressionValue' && isset($data['expression'])) {
            return $layer->setExpression((string) $data['expression']);
        }

        return $layer->setText($data['value'] ?? '');
    }
}
