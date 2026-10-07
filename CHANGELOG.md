# Changelog

本项目遵循 [Semantic Versioning](https://semver.org/lang/zh-CN/)。

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
