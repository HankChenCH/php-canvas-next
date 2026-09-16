# AGENTS.md

## Purpose

PHP library `hankchen/php-canvas-next`（`hankchen/php-canvas` 的下一代）：图层树（纯结构）与渲染器（可插拔后端）分离。**本包是核心包**：只含结构、渲染契约与资源物化，`require` 仅 `php + endroid/qr-code`，不依赖任何图像扩展/库。位图后端在伴随包 `hankchen/php-canvas-image-renderer`（本地同级目录，经 path repository 互相引用）。命名空间 `HankChen\CanvasNext\`（PSR-4 → `src/`），PHP `^8.3`。中文是代码注释的工作语言；commit 用 conventional-commit 前缀 + 中文 subject。

## Layout

- `src/Canvas.php` — 纯结构容器：尺寸 + 按 priority 降序的普通数组图层（等优先级保持插入序），可重复遍历；`graph()`/`fromGraph()` 无损序列化。
- `src/Layer/AbstractLayer.php` — 图层基类：纯数据（盒模型 width/height incl. `'auto'`、padding、border、对齐、position 锚点、priority）+ 纯布局函数 + `graph()`/`fromGraph()`。**没有 render，没有 setDownloader，不 import 任何图像库**。
- `src/Layer/*.php` — `ImageLayer`（只存引用，`setResolvedSrc` 供 Resolver 回写）、`TextLayer`（断行/度量可注入，`getLines()`/`getTextOrigin()`/`lineHeightPx()` 是布局纯函数）、`QrCodeLayer`（只存 text）、`TableLayer → TableRowLayer → TableCellLayer`（add* 时同步父子尺寸，容器暴露 `getRows/getCells/getContentLayer`）。
- `src/Layer/LayerFactory.php` — 按 graph 的 `type` 标识重建图层。
- `src/Contracts/` — `RendererInterface`（`render(Canvas): mixed`，唯一公开渲染入口，`@api`）、`LineBreakerInterface`、`TextMeasurerInterface`（已配置的宽度度量器）与 `TextMeasurerFactoryInterface`（策略注入点）、`DownloaderInterface`。
- `src/Renderer/AbstractRenderer.php` — 渲染模板：resolve → begin → 遍历图层 instanceof 分派 → 容器递归（坐标偏移栈，全部直画同一渲染面）→ end；后端只实现 `begin/end/drawRect/drawImage/drawText` 五个原语。**本包不含任何具体后端**。
- `src/Renderer/PositionResolver.php` — 锚点串 → 父盒内偏移（纯函数，不做钳位，负值即溢出）。
- `src/ResourceManagers/ResourceResolver.php` — 渲染前统一物化：远程图片/字体下载（失败抛 `could not get remote file`）、QR 经 endroid 生成 PNG，回写图层 `setResolvedSrc/setResolvedFont`。是核心包的一部分（任何后端都需要它）。
- `src/Text/` — `Uax14LineBreaker`（图素簇/词边界/禁则，默认断行器）、`HeuristicMeasurer`（半角 0.55 字宽启发式，默认度量器）、`Graphemes`（图素切分助手）。
- 伴随包 `../php-canvas-image-renderer`：`Renderer\Image\ImageRenderer` + `Renderer\Image\ImageManagerFactory` + 像素测试与目验脚本；命名空间根 `HankChen\CanvasNext\Renderer\Image\`。

## Commands

```sh
export PATH="$(brew --prefix)/opt/php@8.3/bin:$PATH"   # Homebrew PHP 是 keg-only
composer install
composer test          # vendor/bin/phpunit
php -l src/...         # 改动文件语法检查
```

本机 composer 全局走阿里云镜像且元数据滞后（不认识 intervention/image 4.x 与 endroid/qr-code 6.x 稳定版）：安装依赖用仓库内 `composer.lock` 直接 `composer install`；必须重新解析时临时 `composer config repo.packagist.org composer https://repo.packagist.org` 再 update，随后 unset 并用脚本重算 lock 的 content-hash。CI 从 packagist 解析，无此问题。image-renderer 包的改动在 `../php-canvas-image-renderer` 仓库做，其测试含像素取样。

## Rules and gotchas

- **依赖方向单向**：Renderer → Canvas → Layer；结构侧（Canvas/Layer）不得 import 渲染侧与图像库，**核心包 composer require 保持 `php + endroid/qr-code`**——把 intervention 等渲染依赖引回核心是回归。图层 setter 禁止 I/O，物化只发生在 `ResourceResolver`。
- **priority 语义**：越大越先渲染（越垫底），与旧库一致。
- **`AbstractRenderer` 属于核心**：遍历/分派/定位只依赖结构类型；具体后端（Image/Pdf）永远在独立包，只实现绘制原语。
- **PCRE `\X` 不可用于切分**：本机 PCRE 10.45 + jit 下 `preg_split('/\X/u')` 返回空串数组（bug），图素切分统一走 `Text\Graphemes`（ext-intl `grapheme_extract`，缺失时降级码点切分）。
- **endroid/qr-code v6**：只读值对象，命名参数构造；输出用 `(new PngWriter())->write($qr)->getString()` 原始字节落盘。
- **缓存目录** `sys_get_temp_dir()/canvas-next/{img_layers,text_layers,qr_layers}`：两包测试都依赖该路径，勿改；按 URL basename 缓存、命中跳过、无失效机制。
- **graph() 是无损公共序列化面**：新增图层字段必须同时补 `graph()` 与 `fromGraph()`，并保持所有图层往返恒等（测试有 graph→fromGraph→graph 恒等用例）；type 标识 = 类常量 `TYPE`，同时登记进 `LayerFactory::TYPE_TO_CLASS`。
- **断行行为变更属行为面**：改 `Uax14LineBreaker`/`HeuristicMeasurer` 需同步更新 `TextLayerTest` 断言。
- **TDD**：行为变化先写失败测试（红）再实现（绿）；核心包测试只测纯数据/布局/序列化，像素测试在 image-renderer 包。
- 旧库 `../php-canvas` 已冻结：不要把新库改动同步回旧库，也不要让新库依赖旧库。
- intervention/image v4 API、Imagick 无内置字体、PCRE 之外的渲染侧注意项见 image-renderer 包的 AGENTS.md。
