<?php

namespace HankChen\CanvasNext\Layer;

/**
 * 图片图层：只记录原始引用（URL 或本地路径）；
 * 远程资源的下载物化由 ResourceResolver 在渲染前完成
 */
class ImageLayer extends AbstractLayer
{
    public const TYPE = 'ImageLayer';

    protected string $name = self::TYPE;

    private ?string $rawImg = null;

    /**
     * 数据表达式标记（spec §3.1）：非 null = 已标记（valueType='ExpressionValue'），
     * src 由展开阶段按行求值；null = 字面直通
     */
    private ?string $expression = null;

    /** Resolver 物化后的本地路径，渲染端优先使用 */
    private ?string $resolvedSrc = null;

    // 取值：left,center,right
    protected string $horizontalAlign = 'center';
    // 取值：top,center,bottom
    protected string $verticalAlign = 'center';

    public function setImage(?string $img): static
    {
        $this->rawImg = ($img === '' || $img === null) ? null : $img;
        $this->resolvedSrc = null;
        $this->expression = null; // 字面赋值解除标记

        return $this;
    }

    /**
     * 标记数据表达式（spec §3.1）：value 恒镜像表达式原文；
     * expression 键条件写键——未标记保持现状两键，保 Go↔PHP 字节 parity
     */
    public function setExpression(string $expression): static
    {
        $this->expression = $expression;
        $this->rawImg = $expression;
        $this->resolvedSrc = null;

        return $this;
    }

    /** 已标记的数据表达式；未标记返回 null */
    public function getExpression(): ?string
    {
        return $this->expression;
    }

    public function getImage(): ?string
    {
        return $this->rawImg;
    }

    /**
     * @internal 仅供 ResourceResolver 回写物化结果
     */
    public function setResolvedSrc(string $src): static
    {
        $this->resolvedSrc = $src;

        return $this;
    }

    /** 渲染实际可用的图片来源：优先物化结果 */
    public function getResolvedSrc(): ?string
    {
        return $this->resolvedSrc ?? $this->rawImg;
    }

    /**
     * 内容区内图片的绘制起点（对齐 + padding），纯布局计算
     *
     * @return array{0: int, 1: int} [x, y]
     */
    public function getImageOrigin(): array
    {
        $posx = match ($this->horizontalAlign) {
            'left' => $this->padding['left'],
            'center' => ($this->getWidth() - $this->getContentWidth()) / 2,
            'right' => $this->getWidth() - $this->getContentWidth(),
            default => 0,
        };

        $posy = match ($this->verticalAlign) {
            'top' => $this->padding['top'],
            'center' => ($this->getHeight() - $this->getContentHeight()) / 2,
            'bottom' => $this->getHeight() - $this->getContentHeight(),
            default => 0,
        };

        return [intval($posx), intval($posy)];
    }

    public function graph(): array
    {
        $graph = parent::graph();

        $graph['data'] = $this->expression !== null
            ? ['valueType' => 'ExpressionValue', 'expression' => $this->expression, 'value' => $this->rawImg]
            // 现状两键形态（条件写键）
            : ['valueType' => 'StaticValue', 'value' => $this->rawImg];

        return $graph;
    }

    public static function fromGraph(array $graph): static
    {
        $layer = parent::fromGraph($graph);

        $data = $graph['data'] ?? [];
        if (($data['valueType'] ?? null) === 'ExpressionValue' && isset($data['expression'])) {
            return $layer->setExpression((string) $data['expression']);
        }

        return $layer->setImage($data['value'] ?? null);
    }
}
