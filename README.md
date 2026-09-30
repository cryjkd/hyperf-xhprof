# HyperfXhprof — xhprof 风格 Hyperf 性能分析工具

一个**纯 PHP**（无需 xhprof / tideways 等 C 扩展）、**协程安全**的性能分析器，同时兼容 **Hyperf 2.2** 和 **Hyperf 3.1**。

- 方法级火焰图式**调用树** + **扁平统计表**（类似 xhprof 的 Inclusive/Exclusive 指标）
- 可交互 **SVG 火焰图**（hover 提示、点击逐层缩放）
- **多报告勾选对比**（自选多个采样，一键并排对比耗时/CPU/内存）
- 每请求自动触发（Header / Query / 采样率）或手动 `Profiler::start()/stop()`
- 输出自包含 HTML 报告 + JSON 原始数据 + CLI 查看命令
- 基于 Hyperf AOP，状态存于协程上下文，可安全跑在 Swoole 常驻进程

## 工作原理

1. 通过 Hyperf 自带 AOP（`hyperf/di`）织入 `ProfilerAspect`，对标注了 `#[Profile]` 的类/方法做环绕采样。
2. 每次进入/退出方法记录 `hrtime()`（墙钟）、`getrusage()`（CPU）、`memory_get_usage()`（内存），用「子调用耗时冲减」精确算出 **Exclusive** 时间。
3. 采样数据按协程保存在 Coroutine Context，请求结束由中间件落盘为 `.json` + `.html`。

> 与 xhprof 的核心差异：xhprof 依赖 C 扩展做全量函数采样；本工具基于 AOP 做**方法级**采样，无需扩展、可精准圈定范围、开销可预期。

## 环境要求

| 项目 | 版本 |
| --- | --- |
| PHP | `>= 7.4` |
| Hyperf | `2.2`（PHP 7.4~8.0）或 `3.1`（PHP 8.1+） |

> 两个版本对 `Context`/`ApplicationContext` 的命名空间不同（`Hyperf\Utils\*` → `Hyperf\Context\*`），本包通过 `Cryjkd\HyperfXhprof\Context` 适配器自动选择，无需改动业务代码。
>
> 注解写法随 PHP 版本自动切换：PHP 8.0+ 用原生 Attribute（`#[Profile]` / `#[ProfileWs]`），PHP 7.4（Hyperf 2.2）用 docblock 注解（`@Profile` / `@ProfileWs`），类文件里两种标记同时声明，与 Hyperf 2.2 官方注解一致。

## 安装

```bash
composer require cryjkd/hyperf-xhprof
php bin/hyperf.php vendor:publish cryjkd/hyperf-xhprof
```

发布后得到 `config/autoload/profiler.php`，关键项：

```php
return [
    'enable' => false,          // 总开关，默认关闭（零开销）
    'profile_all' => false,     // true 则忽略触发条件、每个请求都采样
    'trigger' => [
        'header' => 'X-Profile',
        'query'  => '_profile',
        'sample_rate' => 0.0,   // 0~1，随机采样比例
    ],
    'collect' => [
        'cpu' => true,
        'memory' => true,
        'max_depth' => 0,       // 调用树深度上限（0=不限制）
    ],
    'exclude' => [],            // 永远跳过的类::方法 / 通配符
    'storage' => [
        'path' => BASE_PATH . '/runtime/profiler',
        'keep' => 100,
    ],
];
```

## 快速上手

给要分析的类或方法打上 `#[Profile]`（PHP 8.0+）：

```php
use Cryjkd\HyperfXhprof\Annotation\Profile;

#[Profile]              // 类级：分析该类全部 public 方法
class UserService
{
    #[Profile]          // 方法级：只分析这一个方法
    public function login(array $data): array
    {
        // ...
    }
}
```

PHP 7.4（Hyperf 2.2）用 docblock 注解代替：

```php
use Cryjkd\HyperfXhprof\Annotation\Profile;

/**
 * @Profile
 */
class UserService
{
    /**
     * @Profile
     */
    public function login(array $data): array
    {
        // ...
    }
}
```

重启服务后，带触发条件请求一次：

```bash
# Header 触发
curl -H 'X-Profile: 1' http://127.0.0.1:9501/user/login

# 或 Query 触发
curl 'http://127.0.0.1:9501/user/login?_profile=1'
```

响应头会带回报告 ID：

```
X-Profile-Id: 20250610_123456_a1b2c3d4
```

报告文件在 `runtime/profiler/20250610_123456_a1b2c3d4.html`（同名 `.json` 为原始数据）。

## 触发方式

| 方式 | 配置 | 说明 |
| --- | --- | --- |
| Header | `trigger.header` | `X-Profile: 1`（1/true/on/yes 均可） |
| Query | `trigger.query` | `?_profile=1` |
| 采样率 | `trigger.sample_rate` | 例如 `0.05` 随机采样 5% 请求 |
| 全量 | `profile_all=true` | 每个请求都采样（压测前请先评估磁盘） |

## WebSocket 请求（零代码）

WebSocket 是长连接、按「消息」分发，没有 HTTP 中间件那种每请求边界。为此提供 `#[ProfileWs]`：标在 `onMessage` 上，切面自动为每条消息开启/结束采样并落盘。

1. 配置开启（`enable` 是总开关，`ws.enable` 是 WS 专用开关）：

```php
// config/autoload/profiler.php
return [
    'enable' => true,
    'ws' => [
        'enable' => true,      // WS 采样开关
        'sample_rate' => 1.0,  // 每条消息都采；0.1 = 采样 10%
    ],
];
```

2. `onMessage` 打 `#[ProfileWs]`，内部业务方法打 `#[Profile]`：

```php
use Cryjkd\HyperfXhprof\Annotation\Profile;
use Cryjkd\HyperfXhprof\Annotation\ProfileWs;

class WebSocketController implements \Hyperf\Contract\OnMessageInterface
{
    #[ProfileWs]
    public function onMessage($server, $frame): void
    {
        // ... 原有解码、分发、handle 逻辑 ...
    }
}

class WebSocketService
{
    #[Profile]
    public function handle(array $params) { /* ... */ }
}
```

> PHP 7.4（Hyperf 2.2）把 `#[ProfileWs]` / `#[Profile]` 换成 docblock 里的 `@ProfileWs` / `@Profile` 即可（见上文「快速上手」）。

3. 报告落盘同 HTTP（`runtime/profiler/`），打开 `index.html` 可勾选对比。

注意：

- `#[ProfileWs]` 只标 `onMessage`；`#[Profile]` 只标内部业务方法，二者别混用（否则 `onMessage` 会被双重织入、时间翻倍）。
- WS 消息量大，建议用 `ws.sample_rate` 控制比例，别长期全量。
- trace 存在当前协程上下文；若 `onMessage` 里 `go()` 开新协程处理业务，需在新协程里再次 `Profiler::start()`。

## 手动埋点

不依赖 HTTP 中间件 / WS 切面的场景（CLI 命令、队列消费等）：

```php
use Cryjkd\HyperfXhprof\Profiler;

Profiler::start();

// ... 需要测量的代码（其中标注了 #[Profile] 的方法会被采样）

$trace = Profiler::stop();
if ($trace !== null) {
    (new \Cryjkd\HyperfXhprof\Report\ReportWriter())->save($trace);
}
```

## 按类通配符分析（不打注解）

不想逐个打注解时，写一个继承基类的切面，用 `classes` 通配符圈定范围：

```php
<?php

namespace App\Aspect;

use Cryjkd\HyperfXhprof\Aspect\AbstractProfilerAspect;

class ServiceProfilerAspect extends AbstractProfilerAspect
{
    public array $classes = [
        'App\Service\*',
        'App\Controller\*',
    ];

    public array $annotations = [];
}
```

然后在 `config/autoload/aspects.php` 注册：

```php
return [
    \App\Aspect\ServiceProfilerAspect::class,
];
```

## 查看报告

```bash
php bin/hyperf.php profiler                 # 列出已保存的报告
php bin/hyperf.php profiler show <id>       # 终端打印某次报告
php bin/hyperf.php profiler compare <id...> # 对比两个及以上报告（终端 + 生成对比页）
php bin/hyperf.php profiler clear           # 清空全部报告
php bin/hyperf.php profiler index           # 重建 index.html（对比页）
```

### 单次报告 HTML

每次采样的 `.html` 包含：

- **Flat profile**：每个函数的调用次数、Inclusive/Exclusive 墙钟与 CPU、内存增量、峰值内存（列可点击排序）
- **Call tree**：按调用嵌套展开的火焰图式调用树
- **Flame graph**：可交互 SVG 火焰图 —— hover 显示耗时/占比，点击某一帧可逐层放大，`Reset zoom` 还原
- **Summary**：本次请求的 URI、总耗时、CPU、峰值内存、函数数、最大深度

### 多报告对比

每次保存报告后会自动重建 `runtime/profiler/index.html`。用浏览器打开它：

1. 在列表里**勾选两个及以上**采样；
2. 点击 **Compare selected**；
3. 页面并排展示各次采样的总览（Wall / CPU / 峰值内存 / 函数数）与**函数级耗时对比**，最大者标红、最小者标绿，并给出 Δ（max−min）。

也可以在终端直接对比：

```bash
php bin/hyperf.php profiler compare 20250610_120001_abc 20250610_120002_def
```

会打印终端对比表，并生成一份 `compare-*.html` 供浏览器打开。

## 兼容性说明

- **AOP 注册**：切面通过 `ConfigProvider` 的 `aspects` 键注册，读取切面类的 `$annotations` 属性默认值，两个版本行为一致，不依赖版本相关的 `#[Aspect]` 注解写法。
- **`#[Profile]` / `#[ProfileWs]`**：与 Hyperf 2.2 官方注解一致，同一文件同时声明 docblock `@Annotation`/`@Target` 与原生 `#[Attribute]`。PHP 8.0+ 走原生 Attribute 路径（2.2 与 3.1 都能识别），PHP 7.4 时 `#[...]` 行被当作注释、改由 Doctrine 读取 docblock，因此一套代码兼容 7.4 / 8.0 / 8.1+。
- **上下文**：`Cryjkd\HyperfXhprof\Context` 自动在 `Hyperf\Context\Context` 与 `Hyperf\Utils\Context` 之间切换。

## 已知限制

- **CPU / 内存为进程级**：Swoole 下 `getrusage()` 与 `memory_get_usage()` 是进程（而非协程）维度的，多协程并发时 CPU 归属与内存增量是近似值；墙钟（`hrtime`）是精确的。
- **AOP 只作用于容器解析的类**：被织入的目标类需经 Hyperf DI 容器实例化（绝大多数 Service/Controller 均满足）；`new` 直接创建的类不会被采样。
- **织入范围在启动时确定**：`#[Profile]` 或 `classes` 通配符改动后需重启进程重新生成代理类。
- 采样本身会带来少量开销，仅建议在需要时开启触发条件，不要长期 `profile_all=true`。

## 目录结构

```
src/
├── ConfigProvider.php          # 注册切面 / 中间件 / 命令 / 发布配置
├── Context.php                 # 2.2 / 3.1 上下文适配
├── Profiler.php                # 门面：start/stop/begin/end/shouldStart/shouldStartWs
├── Trace.php                   # 协程内采样与聚合（扁平表 + 调用树）
├── Node.php / TreeNode.php     # 数据模型
├── Annotation/Profile.php      # #[Profile] 注解（HTTP / 内部方法）
├── Annotation/ProfileWs.php    # #[ProfileWs] 注解（WebSocket 消息窗口）
├── Aspect/                     # ProfilerAspect + WsProfilerAspect（含各自基类）
├── Middleware/ProfilerMiddleware.php
├── Report/ReportWriter.php     # JSON + HTML 落盘、清理、index 重建、对比页
├── Report/HtmlReport.php       # 自包含 HTML 报告（表格 + 调用树 + 火焰图）
├── Report/CompareReport.php    # 勾选多报告对比页（index.html / compare-*.html）
└── Command/ProfilerCommand.php # CLI
publish/profiler.php            # 默认配置
```
