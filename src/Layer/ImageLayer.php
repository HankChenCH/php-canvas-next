<?php

namespace HankChen\CanvasNext\Layer;

/**
 * 图片图层：只记录原始引用（URL 或本地路径）；
 * 远程资源的下载物化由 ResourceResolver 在渲染前完成
 */
class ImageLayer extends AbstractLayer
{
    public const TYPE = 'ImageLayer';

    /** 适配模式（ADR 0023）：铺满裁切（cover，缺省）＝等比缩放铺满内容盒、溢出居中裁切 */
    public const FIT_COVER = 'cover';

    /** 适配模式（ADR 0023）：完整显示（contain）＝等比缩放至恰好完整落入内容盒、按对齐放置，空隙透明 */
    public const FIT_CONTAIN = 'contain';

    protected string $name = self::TYPE;

    private ?string $rawImg = null;

    /**
     * 数据表达式标记（spec §3.1）：非 null = 已标记（valueType='ExpressionValue'），
     * src 由填充阶段按行求值；null = 字面直通
     */
    private ?string $expression = null;

    /** Resolver 物化后的本地路径，渲染端优先使用 */
    private ?string $resolvedSrc = null;

    /**
     * 适配模式声明（ADR 0023）：静态声明不参与数据表达式，只影响绘制、不影响盒子布局；
     * null = cover 缺省；v1 已知值仅 contain，未知值宽容保存（渲染面按 cover 降级）
     */
    private ?string $fit = null;

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
     * 声明适配模式（ADR 0023）：与 src 正交（改图/打标/解标均不扰动）；
     * cover 显式赋值归一为 null 缺省态，保条件写键字节面（name="" 先例）
     */
    public function setFit(?string $fit): static
    {
        $this->fit = $fit === self::FIT_COVER ? null : $fit;

        return $this;
    }

    /** 适配模式声明原文；null = cover 缺省（渲染面未知值按 cover 降级，白名单在渲染臂） */
    public function getFit(): ?string
    {
        return $this->fit;
    }

    /**
     * 内容区内图片的绘制起点（对齐 + padding），纯布局计算
     *
     * @return array{0: int, 1: int} [x, y]
     */
    public function getImageOrigin(): array
    {
        return [
            intval($this->alignedX($this->getContentWidth())),
            intval($this->alignedY($this->getContentHeight())),
        ];
    }

    /**
     * contain 收缩矩形（ADR 0023）：按源图内在比例把内容盒收缩成等比矩形——
     * 两维同一缩放系数取 min(内容宽比, 内容高比)，长边贴内容盒；
     * 放置按对齐 + padding 与 getImageOrigin 同门公式。纯布局计算；
     * 内在尺寸由调用方读取（读取失败按 cover 走），非法尺寸（≤0）不进本函数，
     * 收缩结果 ≤0 由 drawImage 后端防护只画盒
     *
     * @return array{0: int, 1: int, 2: int, 3: int} [x, y, width, height]（相对图层盒原点）
     */
    public function getContainRect(int $imgWidth, int $imgHeight): array
    {
        $scale = min($this->getContentWidth() / $imgWidth, $this->getContentHeight() / $imgHeight);
        $width = intval($imgWidth * $scale);
        $height = intval($imgHeight * $scale);

        return [
            intval($this->alignedX($width)),
            intval($this->alignedY($height)),
            $width,
            $height,
        ];
    }

    /** 对齐放置横向公式（align + padding 同门）：placedWidth 尺寸的内容在整盒内的起点 */
    private function alignedX(float $placedWidth): float
    {
        return match ($this->horizontalAlign) {
            'left' => $this->padding['left'],
            'center' => ($this->getWidth() - $placedWidth) / 2,
            'right' => $this->getWidth() - $placedWidth,
            default => 0,
        };
    }

    /** 对齐放置纵向公式：与 alignedX 同门镜像 */
    private function alignedY(float $placedHeight): float
    {
        return match ($this->verticalAlign) {
            'top' => $this->padding['top'],
            'center' => ($this->getHeight() - $placedHeight) / 2,
            'bottom' => $this->getHeight() - $placedHeight,
            default => 0,
        };
    }

    public function graph(): array
    {
        $graph = parent::graph();

        $graph['data'] = $this->expression !== null
            ? ['valueType' => 'ExpressionValue', 'expression' => $this->expression, 'value' => $this->rawImg]
            // 现状两键形态（条件写键）
            : ['valueType' => 'StaticValue', 'value' => $this->rawImg];

        // 适配模式条件写键（ADR 0023）：缺省(cover)整键不落、缺省态字节面与无字段版本一致，
        // 落键殿后 spec 末位（shape/align/position/fit）；未知值原样透传保往返恒等
        if ($this->fit !== null) {
            $graph['spec']['fit'] = $this->fit;
        }

        return $graph;
    }

    public static function fromGraph(array $graph): static
    {
        $layer = parent::fromGraph($graph);

        // 适配模式宽容读（ADR 0023）：无键回填缺省；isset 门与 PHP 语义一致（null 不算在场），
        // 非字符串值同样归缺省（宽容读不告警）
        if (isset($graph['spec']['fit']) && is_string($graph['spec']['fit'])) {
            $layer->setFit($graph['spec']['fit']);
        }

        $data = $graph['data'] ?? [];
        if (($data['valueType'] ?? null) === 'ExpressionValue' && isset($data['expression'])) {
            return $layer->setExpression((string) $data['expression']);
        }

        return $layer->setImage($data['value'] ?? null);
    }
}
