<?php

namespace HankChen\CanvasNext\Tests\Support;

use HankChen\CanvasNext\Layer\AbstractLayer;
use HankChen\CanvasNext\Renderer\AbstractRenderer;
use HankChen\CanvasNext\ResourceManagers\ResourceResolver;

/**
 * 绘制记录型测试后端：只实现五个原语，记录调用序并返回哑产物。
 * 供核心包检查点/穿线契约测试使用（无像素断言，非像素测试）
 */
class RecordingTestRenderer extends AbstractRenderer
{
    /** @var string[] 调用序记录：begin/rect/image/text/end */
    public array $records = [];

    /** 每次 drawRect 后回调（供测试在绘制中途武装取消信号） */
    public ?\Closure $afterRect = null;

    public function __construct(?ResourceResolver $resolver = null)
    {
        parent::__construct($resolver ?? new ResourceResolver());
    }

    protected function begin(int $width, int $height): void
    {
        $this->records[] = 'begin';
    }

    protected function end(): string
    {
        $this->records[] = 'end';

        return 'fake-product';
    }

    protected function drawRect(int $x, int $y, int $width, int $height, ?string $bgColor, array $border): void
    {
        $this->records[] = 'rect';

        if ($this->afterRect !== null) {
            ($this->afterRect)();
        }
    }

    protected function drawImage(string $src, int $x, int $y, int $width, int $height): void
    {
        $this->records[] = 'image';
    }

    protected function drawText(
        string $line,
        int $x,
        int $y,
        string $fontFile,
        int $fontSize,
        string $fontColor,
        string $horizontalAlign,
        string $verticalAlign,
        int $angle
    ): void {
        $this->records[] = 'text';
    }
}
