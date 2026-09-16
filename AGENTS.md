# AGENTS.md

## Purpose

PHP library `hankchen/php-canvas-next`（`hankchen/php-canvas` 的下一代）：图层树（纯结构）与渲染器（可插拔后端）分离。命名空间 `HankChen\CanvasNext\`（PSR-4 → `src/`），PHP `^8.3`，依赖 `intervention/image` v4 与 `endroid/qr-code` v6。中文是代码注释的工作语言；commit 用 conventional-commit 前缀 + 中文 subject。

## Layout

- `src/Canvas.php` — 纯结构容器：尺寸 + 按 priority 降序的普通数组图层（等优先级保持插入序），可重复遍历；`graph()`/`fromGraph()` 无损序列化。
- `src/Layer/AbstractLayer.php` — 图层基类：纯数据（盒模型 width/height incl. `'auto'`、padding、border、对齐、position 锚点、priority）+ 纯布局函数 + `graph()`/`fromGraph()`。**没有 render，没有 setDownloader**。
- `src/Layer/*.php` — `ImageLayer`（只存引用，`setResolvedSrc` 供 Resolver 回写）、`TextLayer`（断行/度量可注入，`getLines()`/`getTextOrigin()`/`lineHeightPx()` 是布局纯函数）、`QrCodeLayer`（只存 text）、`TableLayer → TableRowLayer → TableCellLayer`（add* 时同步父子尺寸，容器暴露 `getRows/getCells/getContentLayer`）。
- `src/Layer/LayerFactory.php` — 按 graph 的 `type` 标识重建图层。
- `src/Contracts/` — `RendererInterface`（`render(Canvas): mixed`，唯一公开渲染入口，`@api`）、`LineBreakerInterface`、`TextMeasurerInterface`（已配置的宽度度量器）与 `TextMeasurerFactoryInterface`（策略注入点）、`DownloaderInterface`。
- `src/Renderer/AbstractRenderer.php` — 渲染模板：resolve → begin → 遍历图层 instanceof 分派 → 容器递归（坐标偏移栈，全部直画同一渲染面）→ end；后端只实现 `begin/end/drawRect/drawImage/drawText` 五个原语。
- `src/Renderer/PositionResolver.php` — 锚点串 → 父盒内偏移（纯函数，不做钳位，负值即溢出）。
- `src/Renderer/Image/ImageRenderer.php` — intervention v4 后端，返回 `ImageInterface`。
- `src/ResourceManagers/ResourceResolver.php` — 渲染前统一物化：远程图片/字体下载（失败抛 `could not get remote file`）、QR 经 endroid 生成 PNG，回写图层 `setResolvedSrc/setResolvedFont`。
- `src/Text/` — `Uax14LineBreaker`（图素簇/词边界/禁则，默认断行器）、`HeuristicMeasurer`（半角 0.55 字宽启发式，默认度量器）、`Graphemes`（图素切分助手）。

## Commands

```sh
export PATH="$(brew --prefix)/opt/php@8.3/bin:$PATH"   # Homebrew PHP 是 keg-only
composer install
composer test          # vendor/bin/phpunit
php -l src/...         # 改动文件语法检查
php scripts/visual-check.php out.png   # 渲染目验样图（中文禁则/表格/二维码/优先级）
```

本机 composer 全局走阿里云镜像且元数据滞后（不认识 intervention/image 4.x 稳定版），安装依赖必须复用仓库内 `composer.lock` 做 `composer install`，不要跑 `composer update`。CI（GitHub Actions）从 packagist 解析，无此问题。

## Rules and gotchas

- **依赖方向单向**：Renderer → Canvas → Layer；结构侧（Canvas/Layer）不得 import 渲染侧与图像库。图层 setter 禁止 I/O（下载/文件），物化只发生在 `ResourceResolver`。
- **priority 语义**：越大越先渲染（越垫底），与旧库一致。
- **intervention/image v4 API**：`createImage()/decode()/fill()/drawRectangle(closure RectangleFactory: at/size/background)/drawLine(closure LineFactory: from/to/width/color)/text(closure FontFactory: file/size/color/align/angle)/cover()/orient()/colorAt()`；无 `ImageManagerStatic`，一切经 `ImageManagerFactory::make()`。注意 v4.3 的 `drawRectangle` 只收 closure|Rectangle，定位用 `$rect->at(x,y)`，背景色方法是 `background()` 不是 `backgroundColor()`。
- **Imagick 驱动无内置字体**：无字体文件渲染文字抛 StateException；文本测试用 `CanvasTestCase::systemTtf()`（找不到且 Imagick 时 skip），纯数字字体编号（旧库 GD 内置 id）按"无字体"处理走 v4 默认字体（仅 GD 可跑，Imagick 下 skip）。
- **PCRE `\X` 不可用于切分**：本机 PCRE 10.45 + jit 下 `preg_split('/\X/u')` 返回空串数组（bug），图素切分统一走 `Text\Graphemes`（ext-intl `grapheme_extract`，缺失时降级码点切分）。
- **endroid/qr-code v6**：只读值对象，命名参数构造；输出用 `(new PngWriter())->write($qr)->getString()` 原始字节落盘。
- **缓存目录** `sys_get_temp_dir()/canvas-next/{img_layers,text_layers,qr_layers}`：测试断言依赖该路径，勿改；按 URL basename 缓存、命中跳过、无失效机制。
- **graph() 是无损公共序列化面**：新增图层字段必须同时补 `graph()` 与 `fromGraph()`，并保持所有图层往返恒等（测试有 graph→fromGraph→graph 恒等用例）；type 标识 = 类常量 `TYPE`，同时登记进 `LayerFactory::TYPE_TO_CLASS`。
- **断行行为变更属行为面**：改 `Uax14LineBreaker`/`HeuristicMeasurer` 需同步更新 `TextLayerTest` 断言，且渲染像素可能变化。
- **TDD**：行为变化先写失败测试（红）再实现（绿）；像素测试用 `CanvasTestCase::pixel()/assertPixelSame()`，无黄金文件比对。
- 旧库 `../php-canvas` 已冻结：不要把新库改动同步回旧库，也不要让新库依赖旧库。
