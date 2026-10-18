<?php

namespace HankChen\CanvasNext;

/**
 * 画布背景（ADR 0022 / spec §3）：画布级声明式填充（背景色 + 背景图），纯值对象
 *
 * - 背景是渲染语义、不属图层树：无显隐、无优先级、不进命中面
 * - 静态：不进 hydrate 改写面与表达式白名单，hydrate/expand 仅透传
 * - wire 条件写键：两分量皆空 → graph() 返回 null（background 整键不落，name/visible 先例）；
 *   落键时仅非空分量落键，键序钉死 color, image
 * - image 为裸 string 资源引用（URL 或本地路径，与 ImageLayer src 同构）；适配固定 cover
 */
final class CanvasBackground
{
    private readonly ?string $color;

    private readonly ?string $image;

    public function __construct(?string $color = null, ?string $image = null)
    {
        // 空串与 null 同归缺省（与 ImageLayer::setImage 同精神）：条件写键的空分量
        $this->color = ($color === '' || $color === null) ? null : $color;
        $this->image = ($image === '' || $image === null) ? null : $image;
    }

    public static function make(?string $color = null, ?string $image = null): self
    {
        return new self($color, $image);
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function getImage(): ?string
    {
        return $this->image;
    }

    public function isEmpty(): bool
    {
        return $this->color === null && $this->image === null;
    }

    /**
     * wire 形态（条件写键）：两分量皆空返回 null（Canvas::graph 据此整键不落）；
     * 落键仅非空分量落键，键序 color, image
     */
    public function graph(): ?array
    {
        $background = [];
        if ($this->color !== null) {
            $background['color'] = $this->color;
        }
        if ($this->image !== null) {
            $background['image'] = $this->image;
        }

        return $background === [] ? null : $background;
    }

    /**
     * 宽容读（spec §3）：非数组/缺键回填空；两分量皆空归 null（与不落键态合流）
     */
    public static function fromGraph(mixed $graph): ?self
    {
        if (!is_array($graph)) {
            return null;
        }

        $background = new self(
            is_string($graph['color'] ?? null) ? $graph['color'] : null,
            is_string($graph['image'] ?? null) ? $graph['image'] : null,
        );

        return $background->isEmpty() ? null : $background;
    }
}
