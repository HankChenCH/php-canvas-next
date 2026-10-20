# Changelog

本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

## 2.1.0 - 2026-10-20

- 图片层裁剪形状圆形档（ADR 0026，spec 见 `.scratch/image-clip-shape/spec.md`）：`ImageLayer` 新增静态裁剪声明 `clip`（`CLIP_RECT`/`CLIP_CIRCLE` 常量，`setClip()`/`getClip()`，与 src/fit 正交），v1 两档——矩形（缺省不裁）与圆形（完整圆形框：背景色填进内切圆、内容按 fit 绘出后遮罩进圆、边框沿圆周描成环）
- wire 条件写键：`spec.clip` 值=rect（缺省）整键不落、落键殿后 spec 末位 fit 之后（键序钉死 shape/align/position/fit/clip）；解码宽容读——无键回填 rect、显式 rect 归一缺省态（fit 显式 cover 归一先例）、非字符串静默归缺省、未知值原样透传保往返恒等；无 clip 存量 graph 字节面零变化
- 纯布局函数：`getInscribedCircleRect()` 内切圆矩形——边长 = min(内容区宽高)、alignedX/alignedY 同门盒内对齐定位、padding 即框外留白、非方盒恒正方几何（渲染面正圆）不变形为椭圆；`getCircleStroke()` 圆周描边参数解析——宽取四边已声明宽的最小值、色按 top→right→bottom→left 取首个非空边色、四边全未声明不描边
- 分派层圆形档：`AbstractRenderer` 以 instanceof 探测新增形状能力口 `Contracts\ShapeCapableRenderer`（后端可选实现——`drawEllipse(rect, fill, stroke)` 椭圆绘制与 `drawImageMasked(..., maskRect)` 掩码 blit 双原语，掩码 = 内切于给定矩形的椭圆、分派层传正方形即圆，原语零形状语义零 fit 语义，ADR 0024 方向延续）；clip=circle 且后端实现能力口走三步（背景填进内切圆 → fit 先绘后遮罩 blit → 圆周描边环），fit 分派几何收敛 `paintFitBlits` 单处与矩形路径共用防数学漂移；未知 clip 值与后端缺能力口降级矩形；内在尺寸不可用退化 fill 形态再裁圆（ADR 0024 纪律）
- 测试：clip wire 四腿、内切圆/描边解析矩阵（纯几何单测）、圆形档分派几何断言（CoordinateRecordingRenderer 扩展椭圆/掩码 blit 留痕）、缺能力口与未知值降级、内在尺寸退化；存量序列化/分派断言按设计保绿

## 2.0.2 - 2026-10-19

- `ImageLayer::getTileRects` 非法内在尺寸（≤0）防线返回空集：≤0 步长使平铺循环不终止，公开 API 对第三方直调自防御（Go TileRects 同款防御；分派层 intrinsicSize 已门控，渲染行为面零变化）+ 防线用例（retro 08 票）
- 测试基建：CanvasTestCase `cleanupTmpDir` 收敛复用 `removeDir` 递归删除（评审 Standards 项去重）

## 2.0.1 - 2026-10-19

- 图片分派内在尺寸 EXIF 感知（07 票，ADR 0024 追记）：orientation 5..8 的 JPEG 分派层返回**观察方向尺寸**（宽高互换）——blit 窗口几何与位图后端解码归正位图（intervention v4 `autoOrientation` 双驱动默认）同坐标系，修复旋转 JPEG 在 cover/tile 档的错窗越界黑补与 contain 档的宽高比倒置（v1 潜伏缺陷同根）；exif 扩展缺席宽容退回原始尺寸（与损坏图同一退化臂）。Go 端为原始尺寸系自洽（裁窗先于归正）、JS 端 naturalWidth 即观察方向——三端渲染输出 parity 不变，坐标系约定落 `drawImage` 契约 docblock
- paintImage 退化臂注释收窄：「渲染结局与迁移前 parity」限定为后端解码同样失败的真损坏图；「解码可过而尺寸探测不识」的窄缝源（如部分 SVG）静默呈现 fill 形态，明示为 ADR 0024 接受面
- 历史勘误：2.0.0 条目「扁平十参」实为九参（$src + 8 个 int）

## 2.0.0 - 2026-10-19

**Breaking（ADR 0024）**：`AbstractRenderer::drawImage` 绘制原语签名变更为双矩形 blit——`drawImage(string $src, int $sx, int $sy, int $sw, int $sh, int $dx, int $dy, int $dw, int $dh)`（扁平九参，drawText 同款风格），把源图 (sx, sy) 起 sw×sh 矩形拉伸搬运至目标 (dx, dy) 处 dw×dh；原语零 fit 语义、零内在尺寸读取，sw/sh ≤ 0 为整图哨兵（按解码后实际尺寸取整图）。**自定义渲染后端必须同步迁移**（官方伴随包 php-canvas-image-renderer 以 v2.0.0 跟进）。

- 图片绘制原语通用化 + 全 fit 分派上移（ADR 0024，spec 见 `.scratch/image-fit-fill-tile/spec.md`）：四档 fit 的几何全部收进绘制分派层——cover（缺省/未知值降级）等比居中裁窗、fill 整图（与 cover 同门 dstRect，唯一差别在 srcRect）、contain 收缩（ADR 0023 语义逐字节不动）、tile 可见块逐块 blit；背景臂（恒 cover，ADR 0022）迁移为分派点算 cover 窗后 blit，QR 臂（恒内切正方形，ADR 0015）srcRect 零哨兵整图 blit、零尺寸读取——语义零变化
- 图片层适配模式扩四档：`ImageLayer` 新增 `FIT_FILL`（拉伸铺满：非等比缩放至恰好铺满内容盒，不裁切不留空隙）与 `FIT_TILE`（平铺：按内在尺寸 1:1 原尺寸重复铺放，首块按对齐 + padding 锚定、向右下无限重复、绘制面止于网格 ∩ 内容盒，末行末列半块截断、锚点前空隙透明，零参数）；tile 矩形组纯布局函数 `getTileRects(imgW, imgH)`（行主序 [src, dst] 组，可见块数不设上限）
- cover 裁窗公式收敛单处：`ImageLayer::getCoverRect(imgW, imgH)`（源图坐标窗口）与 `getContainRect` 同门，公式唯一实例 = 静态 `coverSourceWindow(boxW, boxH, imgW, imgH)`（图片臂与画布背景臂共用，防数学漂移）；坐标取整统一 PHP `intval`（朝零截断）——Go/JS 移植须复制朝零截断语义保三端像素 parity
- 内在尺寸读取扩为全 fit 前置：`paintImage` 绘制分派点一次读取（`getimagesize`，与 resolve 同批 I/O），读取失败统一退化**整图拉伸进目标矩形（fill 形态）**，srcRect 零哨兵——真损坏图在后端解码处照旧抛，渲染结局与迁移前逐位对齐
- wire 零变化（spec §3/§4）：`spec.fit` 形态/键序/条件写键一字不动，已知值域 {cover, contain} 扩为 {cover, contain, fill, tile}（值是透传字符串）；无键回填 cover、显式 cover 归一缺省、未知值原样透传保往返恒等、渲染面白名单外降级 cover，全部不变；布局快照字节面零 diff（导出脚本仅同步生成器注释与 RecordingRenderer 签名）
- 测试：cover 裁窗矩阵 + tile 矩形组矩阵 + fill/cover 同门 dst 断言（纯几何单测）；四档 fit 分派坐标断言（CoordinateRecordingRenderer，blit 调用序列 srcRect/dstRect/次数）；内在尺寸失败退化矩阵；背景/QR 臂迁移既有断言零 diff

## 1.4.0 - 2026-10-18

- 图片层适配模式 fit（ADR 0023，spec 见 `.scratch/image-layer-fit/spec.md`）：`ImageLayer` 新增静态适配声明 `fit`（`FIT_COVER`/`FIT_CONTAIN` 常量，`setFit()`/`getFit()`），v1 仅 contain 一档（完整显示：等比缩放至恰好完整落入内容盒、按对齐放置、空隙透明露下层）；cover 缺省不动
- wire 条件写键：`spec.fit` 值=cover（缺省）整键不落、落键仅 fit≠cover 时发生且殿后 spec 末位（键序钉死 shape/align/position/fit）；解码宽容读——无键回填 cover、显式 cover 归一缺省态（name="" 先例）、未知值原样透传保往返恒等；无 fit 存量 graph 字节面零变化
- contain 渲染语义：`AbstractRenderer::paintImage` 绘制分派点按物化后源图内在比例（`getimagesize`，绘制分派期 I/O 与 resolve 同批）把目标矩形收缩成等比矩形再调 `drawImage`——原语签名与 cover 语义一字不动（GD/Imagick 后端零改动白得）；收缩矩形纯计算在 `ImageLayer::getContainRect()`（等比收缩 + align/padding 镜像 `getImageOrigin` 同门放置），内在尺寸读取失败/未知 fit 值按 cover 降级，cover 路径逐字节零回归
- fit 为静态声明：不进 hydrate 改写面与表达式白名单（TextLayer.text / ImageLayer.src / QrCodeLayer.value 白名单不变），hydrate 对 fit 仅透传

## 1.3.0 - 2026-10-18

- 画布背景声明化（ADR 0022，spec 见 `.scratch/canvas-background/spec.md`）：canvas 级可选 `background` 字段（`CanvasBackground` 值对象，color + image 两分量可空，`Canvas::setBackground()` 声明）。wire 条件写键——两分量皆空整键不落、落键仅非空分量落键、键序钉死 `canvas{width,height,background}` / `background{color,image}`；无背景存量 graph 字节面零变化，落键/不落键两态 decode→encode 往返恒等，解码宽容读缺键回填空
- 渲染铺装（spec §4）：`AbstractRenderer::render()` 根层循环之前先铺色再铺图（复用 drawRect/drawImage 绘制原语，后端约零改动），背景图 cover 铺满画布矩形、透明区露背景色，背景之下无物（画布初始透明保留）；`renderLayer()` 单图层便捷入口不画背景（无画布矩形语义）
- 背景图惰性物化：`ResourceResolver::resolveBackground()` 画布级入口，与 `resolveLayer` 同语义——物化前检查点、远程下载缓存（与图层共用 `img_layers` 缓存）、本地路径直通、失败即抛 `MaterializeException`、渲染面整体丢弃；不触发渲染器预遍历（`resolve()` 禁预遍历断言保持）
- 分页与填充透传：`CanvasPaginator::split()` 页 canvas 键显式携带 background（spec §5 易漏点，每页均带）；hydrate/expand 对背景仅透传——不进 hydrate 改写面与表达式白名单（静态，ADR 0022 Q3），数据驱动底图继续走图层级，两形态并存合法

## 1.2.0 - 2026-10-15

- 资源表达式单值裸路径门（ADR 0020）：已标记 Image/Qr 的 expression 求值前校验须恰为一个 `{{...}}` 闭合片段 + 裸点路径（`$index`/`$root.` 前缀合法），禁多片段拼接、片段外字面、内建函数与空片段；违例抛 `HydrateException` 新错误码 `expression_resource_single_ref`，先于求值与 `expression_empty_resource` 空串检查（测试钉序）。文本层插值语义与未标记字面直通零变化
- 共享 fixture `expand-semantics` 增资源门用例 8 条（合法 3 + 违例 4 + 顺序钉定 1），`independent-layers-root-context` 改合法形态

## 1.1.0 - 2026-10-11

- 表达式内建函数求值器（v1 方法面，ADR 0018）：mustache 插值内函数调用形态 `{{date(row.d, 'YYYY年MM月DD日')}}`，签名 `date(path[, format])` / `number(path[, n])` / `fixed(path[, n])` / `percent(path[, n])`
- `DateFormat` 日期 token 语言：moment 风格 14 token（含周几中文输出），方括号转义，严格 ISO 解析（3 种无时区形态、wall clock 语义、零时区依赖），token 表为三端契约权威源
- `NumberFormat` 数字族：十进制字符串语义（最短往返归一、half-up 朝 +∞、2.675→2.68 不走二进制原生 round），千分位/定点补零/百分比乘 100
- 错误分轴：缺字段=空串、不可解析/数值=原文直通（值面宽松）；未知函数/参数个数/文法错 fail-fast 并入 `expression_syntax_error`，零新错误码
- 共享 fixture contract v2：104 用例（value/date/number 三组）+ dateTokens 14 行转译表，由 `scripts/export-hydrate-fixtures.php` 导出

## 1.0.0 - 2026-10-07

首个稳定版。

- 图层树 `Canvas` / `Layer`（纯结构，setter 无 I/O）与渲染契约（`AbstractRenderer` 模板方法：资源物化 → 建渲染面 → 遍历分派 → 容器下钻 → 收尾）分离
- `ResourceResolver` 渲染前统一物化远程图片/字体与二维码，渲染端只见本地路径
- `graph()` / `Canvas::fromGraph()` 无损结构树序列化与重建（含表格尺寸同步）
- 文本图层：UAX #14 简化断行（图素簇切分、CJK 字间可断、行首行末禁则），断行器/度量器可注入替换
- 表格图层（`TableLayer → TableRowLayer → TableCellLayer`）与可选行模板
- 内容流文档编译与分页
- QR 图层内切正方形渲染语义：padding 留白即静区、align 参与放置（ADR 0015）
- 渲染取消（`Runtime\Cancellation`）支持

位图输出请配合 `hankchen/php-canvas-image-renderer` 使用。
