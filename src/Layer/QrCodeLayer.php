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

    private ?string $resolvedSrc = null;

    public function setText(string $content): static
    {
        $this->qrText = $content;
        $this->resolvedSrc = null;

        return $this;
    }

    public function getText(): string
    {
        return $this->qrText;
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

    public function graph(): array
    {
        $graph = parent::graph();

        // 无损：value 始终携带内容，与是否已生成图像无关
        $graph['data'] = [
            'valueType' => 'StaticValue',
            'value' => $this->qrText,
        ];

        return $graph;
    }

    public static function fromGraph(array $graph): static
    {
        return parent::fromGraph($graph)->setText($graph['data']['value'] ?? '');
    }
}
