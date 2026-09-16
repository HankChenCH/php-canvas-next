# php-canvas-next

`hankchen/php-canvas` 的下一代实现：**图层树（纯结构）与渲染器（可插拔后端）彻底分离**。

- `Canvas` / `Layer` 是纯数据容器，只承载设定与纯布局函数，不渲染任何像素；
- 渲染由 `Renderer` 驱动：`Renderer::render(Canvas)` 拉取结构树，按图层类型分派绘制；
- 内置 `ImageRenderer`（intervention/image v4），PDF 等其他后端按同一契约扩展。

设计文档与对象交互图见 [docs/design/layer-renderer-separation.md](docs/design/layer-renderer-separation.md)（完整用法与迁移指南随首个可用版本补充）。

## 开发

```sh
composer install
composer test
```
