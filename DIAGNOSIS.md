# 为什么 0.1 版「跑不起来」，以及 0.2 改了什么

**[简体中文](./DIAGNOSIS.md)** &nbsp;|&nbsp; **[English](./DIAGNOSIS.en.md)**

每条结论都对照了真实源码，不是猜的：

* `opnsense/core` master 与 stable/24.7、25.1、25.7（MVC 控制器/模型基类、Router、ACL、configd 模板引擎）
* `opnsense/plugins` master（ndp-proxy-go 等官方插件范式、plugins.mk 的打包脚本生成规则、真实 `os-*` 包的 +MANIFEST）
* `olicesx/kixdns` main（`src/main.rs` 的 clap CLI、`src/config.rs` 的配置 schema）
* FreeBSD `usr.sbin/daemon` 与 `rc.subr`（daemon 的 -f/-S/-T/-P 语义、rc.subr 状态匹配）

## 缺陷清单

| # | 位置 | 问题 | 证据 | 修复 |
| --- | --- | --- | --- | --- |
| 1 | 打包 `+POST_INSTALL` | 构建工作流把它覆盖成 `exit 0`，post-install 什么都没做：configd 不知道新 actions、`/etc/rc.conf.d/kixdns` 和 `/usr/local/etc/kixdns/pipeline.json` 从不生成 | 官方框架 plugins.mk 自动生成的是「重启 configd + run_migrations + rc.configure_plugins + template reload」四段；对照真实 `os-ndp-proxy-go` 包内 `+MANIFEST` 的 `scripts.post-install` 完全一致 | 脚本嵌入 `+MANIFEST.scripts`（post-install / pre-deinstall / post-deinstall），内容与官方一致 |
| 2 | `ServiceController::reconfigureAction()` | 调用了 `$this->sessionClose()`——这个方法在 OPNsense 24.7/25.1/25.7/master 的 `ApiControllerBase` 里**都不存在**，点 Apply 必 500 | grep 全部四个版本的 ApiControllerBase，0 命中 | 删掉整个自定义 reconfigure，交给 `ApiMutableServiceControllerBase`（基类做 template reload + start/stop + 状态判断） |
| 3 | `SettingsController::getAction()` | `getBase('general','general')` 最终调用 `$mdl->general->Add()`，而 `Add()` 只存在于 ArrayField；`general` 是 ContainerField，GET 设置页必 500，表单永远空白 | core `ApiMutableModelControllerBase::getBase()` 的 `$uuid == null` 分支；`ContainerField` 没有 `Add()` | 删掉自定义 get/set，用基类 `getAction()`/`setAction()`（`{kixdns: {...}}` 结构），表单字段 id 改为 `kixdns.general.*` |
| 4 | `SettingsController::setAction()` | `setBase('general','general')` 缺第三个必填参数 `$uuid`，PHP 抛 ArgumentCountError，保存必 500 | `setBase($post_field, $path, $uuid, $overlay = null)` | 同 #3 |
| 5 | 表单 id | 字段 id 是 `general.enabled` 而基类 get 返回 `kixdns.general.enabled`，就算 #3 不炸数据也对不上；`saveFormToEndpoint` 发的 POST 也同理 | 对照官方插件 `ndpproxy.general.*` 的写法 | id 全部前缀 `kixdns.` |
| 6 | `rc.d/kixdns` 启动参数 | 用 `kixdns --config ... --listener-label ...` 启动，但 kixdns 是 clap 子命令 CLI，只有 `kixdns run --config ...` 才被解析；不带子命令时所有参数被丢弃、服务按默认配置跑（`config/pipeline.json` 相对路径根本不存在，直接崩） | `main.rs`：`#[command(subcommand)] command: Option<Commands>`，flag 全部挂在 `Run` 子命令下 | 启动命令改为 `kixdns run --config ${kixdns_config} --listener-label ...`，并按 `RUST_LOG` 传日志级别 |
| 7 | rc.conf.d 生成方式 | 旧代码用 PHP 以 www 身份 `file_put_contents('/etc/rc.conf.d/kixdns')`——/etc 不可写，静默失败，`kixdns_enable` 永远没写进去，rc.subr 永远不启动；且只随「Apply」触发，重启机器后配置消失 | OPNsense 的 rc.subr 行为 + 官方插件全部用 configd 模板（`+TARGETS` 里 `xxx:/etc/rc.conf.d/xxx`） | 新增模板 `OPNsense/KixDNS/kixdns → /etc/rc.conf.d/kixdns`，由 configd 以 root 生成 |
| 8 | actions.d `[status]` | 没有 `errors:no`。rc.subr 在服务没跑时 `exit 1`，configd 的 `script_output` 执行 `subprocess.run(check=True)` 把非零退出变成 `"Execute error"`，状态判断全废 | configd `script_output.py`：`check=not self.disable_errors`；对照官方 `actions_ndpproxy.conf` | status 加 `errors:no`；stop 也加（停一个已停的服务返回 1 属正常） |
| 9 | Pipeline 编辑器 Apply | JS 调 `/api/kixdns/settings/reconfigure`——这个 action 不存在（只有 service controller 有 reconfigure），Apply 走错路 | 路由：`settings` namespace 下无 reconfigure；ServiceController 才有 | 改调 `/api/kixdns/service/reconfigure` |
| 10 | 包 ABI | `+MANIFEST` 硬编码 `FreeBSD:14:amd64`。OPNsense 26.7 换到 FreeBSD 15 基座后 `pkg add` 直接拒装 | `https://pkg.opnsense.org/FreeBSD:14:amd64/26.7/` 404、`FreeBSD:15:amd64/26.7/` 200 | workflow 按 ABI 矩阵出两个包（14/26.1 与 15/26.7），并附 `opnsense-version` annotations |
| 11 | 缺 ACL | 没有 `models/OPNsense/KixDNS/ACL/ACL.xml`，非 admin 用户无法通过 ACL 授权访问（admin 有 page-all 不受影响，但这是插件规范缺失） | 官方插件目录结构 | 补 ACL（ui/api kixdns 与日志页） |
| 12 | 无日志出口 | 二进制输出只有 stdout/stderr，daemon 默认丢 /dev/null，没有任何可读日志 | 无 SSH 时无从诊断 | `daemon -S -T kixdns` + syslog-ng 本地 filter `kixdns.conf`，日志进 `/var/log/kixdns/`，菜单加 Log File 页 |
| 13 | 无版本标记 | 没装 `/usr/local/opnsense/version/kixdns-community`，Firmware 页不认识这个插件 | 官方 install 步骤会写 version 文件 | workflow stage 时写入 |
| 14 | `rc.d` 变量 `kixdns_program` | 变量名与 FreeBSD rc.subr 的 `${name}_program` 机制冲突（`_rc_namevarlist` 含 `program`），rc.subr 把它当作"每服务命令覆盖"，导致 `/usr/sbin/daemon` 被丢弃、实际执行 `kixdns -f -S ...`，kixdns 报 `unexpected argument '-f'` | **OPNsense 26.7 实机复现**（`sh -x` trace 显示 `_doit` 以 kixdns 开头而非 daemon），FreeBSD 15 rc.subr 源码的 `_rc_namevarlist` 定义 | 改名 `kixdns_bin`，check_plugin.py 加防回归断言 |

## 几个没改但要说清楚的

* **默认监听 5353 而不是 53**：有意保留。需要 53 时关 Unbound 后在 Pipeline 编辑器里改 bind 地址。
* **`kixdns reload` 等价于 restart**：kixdns 自带文件 watcher，但 bind 地址等变更 watcher 不管，
  保守起见 reload = restart。
* **`general.log_level` 现在真的生效**：作为 `RUST_LOG` 传入（tracing 的 EnvFilter 优先于 `--debug`），
  不是摆设。
