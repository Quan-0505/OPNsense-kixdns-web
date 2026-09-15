<div align="center">
  <h1>os-kixdns-community</h1>
  <p><strong>KixDNS</strong> —— 用 Rust 编写的高性能异步 DNS 服务器，集成到 OPNsense，支持热重载的防污染管道。</p>

  <p>
    <a href="https://github.com/Quan-0505/OPNsense-kixdns-web/releases"><img src="https://img.shields.io/github/v/release/Quan-0505/OPNsense-kixdns-web?style=flat-square&color=0b5" alt="Release" /></a>
    <a href="https://github.com/Quan-0505/OPNsense-kixdns-web/blob/main/LICENSE"><img src="https://img.shields.io/github/license/Quan-0505/OPNsense-kixdns-web?style=flat-square" alt="License" /></a>
    <a href="https://github.com/olicesx/kixdns"><img src="https://img.shields.io/badge/engine-KixDNS%20(Rust)-e05d44?style=flat-square" alt="Engine" /></a>
    <a href="https://opnsense.org/"><img src="https://img.shields.io/badge/OPNsense-25.7%20%C2%B7%2026.1%20%C2%B7%2026.7-0095D5?style=flat-square" alt="OPNsense" /></a>
    <a href="https://github.com/Quan-0505/OPNsense-kixdns-web/commits/main"><img src="https://img.shields.io/github/last-commit/Quan-0505/OPNsense-kixdns-web?style=flat-square" alt="Last commit" /></a>
  </p>

  <p>
    <a href="./README.md">English</a> &nbsp;|&nbsp;
    <strong>简体中文</strong>
  </p>

  <p>
    <a href="#-功能特性">功能特性</a> &nbsp;&middot;&nbsp;
    <a href="#-web-控制台">Web 控制台</a> &nbsp;&middot;&nbsp;
    <a href="#-原生可观测性">原生可观测性</a> &nbsp;&middot;&nbsp;
    <a href="#-工作原理">工作原理</a> &nbsp;&middot;&nbsp;
    <a href="#-安装">安装</a> &nbsp;&middot;&nbsp;
    <a href="#-验证">验证</a> &nbsp;&middot;&nbsp;
    <a href="#-开发">开发</a> &nbsp;&middot;&nbsp;
    <a href="#-许可证">许可证</a>
  </p>
</div>

---

## ✨ 功能特性

- 🦀 **Rust 内核** —— 基于 [KixDNS](https://github.com/olicesx/kixdns)：Tokio 驱动的零拷贝 UDP、异步 I/O 与管道规则引擎，适合高 QPS 转发。
- 🧩 **管道编辑器** —— 图形化编辑 `settings`、`pipeline_select` 与 `pipelines`，带实时 JSON 预览、导入/导出和逐规则的条件/动作构建器。
- 🛡️ **DNS 防污染** —— 多级规则：先查运营商解析器，检测投毒（保留地址/伪造应答），命中毒化结果后改用 **DoH**（阿里/腾讯）重新解析 —— HTTPS 加密，不受 UDP 注入影响。
- 🔄 **热重载** —— 监听配置文件变化，编辑后原子生效，无需重启服务、不丢端口。
- 📊 **AdGuardHome 风格控制台** —— 仪表盘（查询统计、趋势图、Top-N 榜单）+ 可过滤的实时查询日志（见 [Web 控制台](#-web-控制台)）。
- 🎛️ **服务控制** —— 启动/停止/重启，带实时状态指示灯，旁边显示运行时的接管方式与版本。
- 🔬 **诊断信息** —— 设置页实时上报 `kixdns --version`，在动管道之前就能发现二进制缺失 / ABI 不匹配。
- 📜 **日志轮转** —— 内置 newsyslog 配置，`/var/log/kixdns/*.log` 每日轮转（gzip 保留），日志不会无限增长。
- 📄 **ACL + 日志查看** —— 附带 ACL 以便为普通用户授权，并在菜单提供 **Log File** 入口，把 kixdns 日志直接呈现在 GUI 中。

## 📊 Web 控制台

**Services → KixDNS** 打开 AdGuardHome 风格的控制台，含四个标签页：

| 标签页 | 内容 |
| --- | --- |
| **仪表盘 Dashboard** | 转发查询总数、唯一域名、平均延迟、慢查询数；按小时的查询趋势图（图表用 OPNsense 自带的 Chart.js）；Top 域名 / Top 客户端 / 上游分布；响应码分布。 |
| **查询日志 Query Log** | 实时查询表格（时间 · 客户端 · 域名 · 类型 · 响应码 · 上游 · 延迟 · 缓存），支持域名/客户端过滤、条数选择与 3s/5s/15s 自动刷新。 |
| **设置 Settings** | 启用/停用、监听标签、日志级别、调试开关、UDP worker 数 —— 与之前一致。 |
| **管道编辑器 Pipeline Editor** | 图形化编辑 `settings`、`pipeline_select` 与 `pipelines`，带实时 JSON 预览、导入/导出和逐规则构建器 —— 与之前一致。 |

各面板由 `api/kixdns/stats/*` 支撑，该接口**增量解析** kixdns 日志（字节偏移游标 + 短期缓存文件），因此每天产生 10 万行以上日志的解析器做汇总依然很轻量。

> **两种数据口径。** 开启 **Debug**（需要 kixdns ≥ 0.2.0 的原生观测能力，见下节）时，控制台报告真实的客户端查询量、
> 真实缓存命中率与端到端延迟；未开启时日志中只有*转发*（回源）响应记录 —— 缓存命中不可见，此时控制台显示这一较窄口径
> 并在状态条中标注。所有占比列始终以当前口径的总量为分母。

## 🔭 原生可观测性

kixdns ≥ 0.2.0 提供 **Observer API**（`EngineObserver`），当以 `--debug` 启动时会安装其参考实现。本插件在
**Settings → Debug** 开启时传递 `--debug`，于是引擎会把结构化生命周期事件写入日志：

```
event="request_started"  request_id=41 listener="default" client=192.168.5.106:53121 qname="www.baidu.com" qtype=A
event="cache_miss"       request_id=41
event="upstream_result"  request_id=41 upstream="202.96.128.86:53" outcome=Success latency_us=13004 rcode=No Error
event="request_finished" request_id=41 status=Completed latency_us=13220
event="cache_hit"        request_id=42 kind=Fresh remaining_ttl_s=10
```

仪表盘消费这些事件并切换到 **observer 口径**：

| 指标 | observer 口径（Debug 开） | log 口径（Debug 关） |
| --- | --- | --- |
| 查询量 | 全部客户端请求 | 仅转发（回源）响应 |
| 缓存命中率 | 真实 `命中 / (命中 + 未命中)` | 不可用 |
| 延迟 | 端到端，含命中缓存的应答 | 仅上游往返耗时 |
| 上游健康 | 每次尝试的结果与耗时 | 仅响应记录 |

**代价与控制。** 事件流较为冗长 —— 繁忙网关每天可写入 100–250 MB 日志。下面两点把它压在可控范围内：

* 内置的 newsyslog 规则**每日轮转**，并且单文件达到 **200 MB** 时也轮转，保留 3 份压缩副本（上限约 600 MB）；
* `/etc/rc.conf.d/kixdns` 中的 `kixdns_debug_log_level` 决定 Debug 开启时使用的 `RUST_LOG` 过滤器，默认
  `info,kixdns::observe=debug`：保留观测事件、丢弃其余调试流。只有在内核本身排障时才设为 `debug`。

回退只需一个开关：关闭 **Debug**，控制台即回到 log 口径，日志量恢复为常规 `info` 水平。

## 🏗️ 工作原理

查询进入管道后由 `pipeline_select` 路由；每条规则可以转发到上游、检查响应，然后放行或重新解析：

```text
 客户端 ──► KixDNS (UDP/TCP :53) ──► pipeline_select ──► rule ──► 运营商解析器 (UDP)
    │                                                     │
    │                                                     └──► 检查响应
    │                                                           ├─ 干净   ──► 放行
    │                                                           └─ 被污染 ──► continue
    │                                                                          │
    │                                                                          └──► rule_1 ──► DoH (阿里/腾讯)
    └──────────────────────────────────── 应答 ◄─────────────────────────────────┘
```

- **运营商优先** —— 常规路径留在运营商的高速解析器上（支持按 ISP 分别配置上游）。
- **污染回退** —— 只有检测到被污染的响应（保留地址/伪造记录）时才跳到 DoH，因此回退开销只在需要时付出。

## 📦 安装

### 1. 选择正确的包（ABI 必须匹配）

| OPNsense | pkg ABI | 包名 |
| --- | --- | --- |
| 25.7 / 26.1 | `FreeBSD:14:amd64` | `os-kixdns-community-0.5.1-FreeBSD_14_amd64.pkg` |
| 26.7 及更新（amd64） | `FreeBSD:15:amd64` | `os-kixdns-community-0.5.1-FreeBSD_15_amd64.pkg` |
| 26.7 及更新（arm64） | `FreeBSD:15:aarch64` | `os-kixdns-community-0.5.1-FreeBSD_15_aarch64.pkg` |

不确定时用 `pkg config abi` 查看。

> **发布策略：** 本仓库**只保留最新版本的 Release**，被取代的安装包会被移除，因此不提供旧版本存档。
> 请始终从[最新版本](https://github.com/Quan-0505/OPNsense-kixdns-web/releases/latest)安装。

每个包内附的 kixdns 二进制由本仓库 CI 从**上游 `main`** 交叉编译（`KIXDNS_REF`，默认 `main`），
因此包跟踪的是最新引擎，而不是最后那个已发版的 tag。
该二进制在构建时会**注入引擎版本号**，所以即便上游 `Cargo.toml` 仍写着 `0.1.0`，`kixdns --version` 也会报告 `0.2.0`。

### 2. 安装（无需 SSH）

**System → Diagnostics → Command Prompt** → *Execute Shell Command*：

```sh
# OPNsense 26.7+
pkg add https://github.com/Quan-0505/OPNsense-kixdns-web/releases/download/v0.5.1/os-kixdns-community-0.5.1-FreeBSD_15_amd64.pkg

# OPNsense 25.7 / 26.1
pkg add https://github.com/Quan-0505/OPNsense-kixdns-web/releases/download/v0.5.1/os-kixdns-community-0.5.1-FreeBSD_14_amd64.pkg

# OPNsense 26.7+ arm64（如 NanoPi R4S）
pkg add https://github.com/Quan-0505/OPNsense-kixdns-web/releases/download/v0.5.1/os-kixdns-community-0.5.1-FreeBSD_15_aarch64.pkg
```

安装后的钩子会自动重启 `configd`、执行迁移并重载 `OPNsense/KixDNS` 与 `OPNsense/Syslog` 模板 —— 无需手动重启服务。

### 3. 接管 53 端口

1. **Services → KixDNS** → 启用服务，调整管道（默认 `bind` 监听 **0.0.0.0:53**）。
2. 若 Unbound / Dnsmasq 正在运行，先停用它们以释放 `:53`。
3. 点击 **Apply Changes**。KixDNS 即成为解析器。

## ✅ 验证

```sh
configctl kixdns version                 # 例如 "kixdns 0.2.0" —— 二进制可运行
cat /etc/rc.conf.d/kixdns                # 启用后为 kixdns_enable="YES"
configctl kixdns status                  # "kixdns is running as pid ..."
curl -s -u "$APIKEY:$APISECRET" https://127.0.0.1/api/kixdns/stats/overview  # 控制台统计接口
sockstat -4 -l | grep ':53'              # KixDNS 已绑定 :53
tail -n 50 /var/log/kixdns/kixdns_*.log  # 或 Services → KixDNS → Log File
```

## 🔧 开发

`.github/workflows/build-opnsense.yml` 交叉编译 FreeBSD 二进制（`cross`，`x86_64-unknown-freebsd` / `aarch64-unknown-freebsd`），
校验其为 FreeBSD ELF，并打出带 `abi`/`arch`/`annotations`/`files`/`scripts` 的 `+MANIFEST`
（格式对照真实 `os-*` 包校验），在 `v*` tag 上发布三个 `.pkg`（FreeBSD 14/15 amd64 + FreeBSD 15 aarch64）。

本地检查（Python 3.11）：

```sh
python check_plugin.py       # 静态一致性与回归防护
python check_templates.py    # 渲染 configd 模板（Jinja2，镜像 configd 行为）
python check_packaging.py    # 演练 manifest/tar 打包逻辑
python verify_pkg.py         # 独立验证已构建的 .pkg
python build_pkg.py          # 构建全部 ABI 包（--target aarch64 构建 arm64 包）
```

## 📚 文档

- [DIAGNOSIS.md](DIAGNOSIS.md) —— v0.1 发现的完整缺陷清单及各自的修复方式 · 另提供[中文](DIAGNOSIS.md) / [English](DIAGNOSIS.en.md) 两版。

## 📄 许可证

GPL-3.0 —— 与上游 [KixDNS](https://github.com/olicesx/kixdns) 相同。
