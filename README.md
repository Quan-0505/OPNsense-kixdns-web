# os-kixdns-community

OPNsense 插件，把 [KixDNS](https://github.com/olicesx/kixdns)（Rust 编写的高性能异步 DNS 服务器）集成进 OPNsense
的 Web 界面：**Services → KixDNS**。

> 本仓库的 0.2 版修掉了 0.1 版「装上但跑不起来」的全部已知原因，详见 [DIAGNOSIS.md](DIAGNOSIS.md)。

## 功能

* 常规设置：启用/关闭、Listener Label、日志级别、Debug、UDP worker 数
* Pipeline 编辑器：完整的 settings / pipeline_select / pipelines 图形编辑 + JSON 预览与导入导出
* 服务托管：Services 菜单里的 start/stop/restart 与状态灯、Apply 后自动生成配置并重启
* 日志：进程输出经 syslog-ng 落到 `/var/log/kixdns/`，界面上 **Services → KixDNS → Log File** 直接看
* 诊断：常规设置页显示 `kixdns --version`，用来区分「二进制没跑起来」和「配置被拒」

## 安装

### 1. 选择正确的包（ABI 必须匹配）

| OPNsense | pkg ABI | 需要下载的文件 |
| --- | --- | --- |
| 25.7 / 26.1 | `FreeBSD:14:amd64` | `os-kixdns-community-0.2-FreeBSD_14_amd64.pkg` |
| 26.7 及以后 | `FreeBSD:15:amd64` | `os-kixdns-community-0.2-FreeBSD_15_amd64.pkg` |

不确定的话，在 OPNsense 里执行 `pkg config abi` 看输出。

### 2. 安装（无需 SSH）

界面进 **System → Diagnostics → Command Prompt**，在 “Execute Shell Command” 里执行：

```sh
# 旧版本先卸掉，避免文件残留
pkg delete -y os-kixdns-community || true

# 打 tag 触发的构建会自动发 Release，可以直接用 URL 安装
pkg add https://github.com/troubadour-hell/opn-kixdns/releases/download/v0.2/os-kixdns-community-0.2-FreeBSD_14_amd64.pkg
```

如果只跑了 Actions 没发 Release，就到 Actions → 对应 run → Artifacts 下载 zip，解压出 `.pkg`，
用同一页面的 “Upload File” 传到 `/tmp/`，然后 `pkg add /tmp/os-kixdns-community-0.2-FreeBSD_14_amd64.pkg`。

安装脚本（post-install）会自动：重启 configd、跑模型迁移、重载 `OPNsense/KixDNS` 与 `OPNsense/Syslog` 模板。
**不需要**手工重启任何服务。

### 3. 验证（每一步都应有输出）

```sh
configctl kixdns version                 # 例：kixdns 0.1.0 —— 证明二进制能在本机运行
cat /etc/rc.conf.d/kixdns                # 应看到 kixdns_enable="NO"（还没启用时）
cat /usr/local/etc/kixdns/pipeline.json  # 应是合法 JSON
configctl kixdns status                  # kixdns is not running.
```

然后界面 **Services → KixDNS**：勾选 Enable → **Apply Changes**，再看

```sh
configctl kixdns status                  # kixdns is running as pid NNN.
sockstat -4 -l | grep 5353               # 应看到 kixdns 监听 5353
tail -n 50 /var/log/kixdns/kixdns_*.log  # 或界面 Services → KixDNS → Log File
```

## 让 DNS 流量真正走 KixDNS

默认 pipeline 监听 `0.0.0.0:5353`（避免和 Unbound 抢 53 端口）。三种常见接法：

1. **放在 Unbound 后面**：Services → Unbound DNS → Query Forwarding，转发到 `127.0.0.1@5353`。
2. **端口转发**：Firewall → NAT → Port Forward，把 LAN 的 53/udp+tcp 重定向到 `127.0.0.1:5353`。
3. **直接占用 53**：关掉 Unbound/Dnsmasq，再在 Pipeline 编辑器里把 `bind_udp`/`bind_tcp` 改成 `0.0.0.0:53`。

## 从源码构建

`.github/workflows/build-opnsense.yml`：

* `build-binary`：用 [cross](https://github.com/cross-rs/cross) 交叉编译 `x86_64-unknown-freebsd`，
  并校验产物确实是 FreeBSD ELF（上游 kixdns 自己的 CI 也用这条路）。
* `package`：把 `src/` 映射到 `/usr/local/`，生成带 `abi`/`arch`/`annotations`/`files`/`scripts` 的
  `+MANIFEST`（格式对照真实的 `os-*` 包核对过），按 ABI 出两个 `.pkg`。
* 打 `v*` tag 会把包挂到 Release 上。

如果把本目录放进 [opnsense/plugins](https://github.com/opnsense/plugins) 的 checkout 里
（例如 `plugins/dns/kixdns-community`），并先把 FreeBSD 二进制放到 `src/usr/local/bin/kixdns`，
也可以用官方框架直接 `make package`。

## 卸载

```sh
pkg delete -y os-kixdns-community
```

## 许可证

GPL-3.0，与上游 KixDNS 一致。
