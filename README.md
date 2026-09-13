<div align="center">
  <h1>os-kixdns-community</h1>
  <p><strong>KixDNS</strong> — a high-performance, asynchronous DNS server written in Rust, integrated into OPNsense with hot-reloadable anti-pollution pipelines.</p>

  <p>
    <a href="https://github.com/Quan-0505/OPNsense-kixdns-web/releases"><img src="https://img.shields.io/github/v/release/Quan-0505/OPNsense-kixdns-web?style=flat-square&color=0b5" alt="Release" /></a>
    <a href="https://github.com/Quan-0505/OPNsense-kixdns-web/blob/main/LICENSE"><img src="https://img.shields.io/github/license/Quan-0505/OPNsense-kixdns-web?style=flat-square" alt="License" /></a>
    <a href="https://github.com/olicesx/kixdns"><img src="https://img.shields.io/badge/engine-KixDNS%20(Rust)-e05d44?style=flat-square" alt="Engine" /></a>
    <a href="https://opnsense.org/"><img src="https://img.shields.io/badge/OPNsense-25.7%20%C2%B7%2026.1%20%C2%B7%2026.7-0095D5?style=flat-square" alt="OPNsense" /></a>
    <a href="https://github.com/Quan-0505/OPNsense-kixdns-web/commits/main"><img src="https://img.shields.io/github/last-commit/Quan-0505/OPNsense-kixdns-web?style=flat-square" alt="Last commit" /></a>
  </p>

  <p>
    <a href="#features">Features</a> &nbsp;&middot;&nbsp;
    <a href="#how-it-works">How it works</a> &nbsp;&middot;&nbsp;
    <a href="#installation">Installation</a> &nbsp;&middot;&nbsp;
    <a href="#verify">Verify</a> &nbsp;&middot;&nbsp;
    <a href="#development">Development</a> &nbsp;&middot;&nbsp;
    <a href="#license">License</a>
  </p>
</div>

---

## ✨ Features

- 🦀 **Rust core** — Powered by [KixDNS](https://github.com/olicesx/kixdns): Tokio-based zero-copy UDP, async I/O and a pipeline rule engine for high-QPS forwarding.
- 🧩 **Pipeline editor** — Full graphical editing of `settings`, `pipeline_select` and `pipelines` with live JSON preview, import/export and a per-rule matcher/action builder.
- 🛡️ **Anti-DNS-pollution** — Multi-stage rules that query an ISP resolver first, detect poisoning (reserved/forged answer records), then re-resolve over **DoH** (Ali/Tencent) — HTTPS-encrypted and immune to UDP injection.
- 🔄 **Hot reload** — The config file is watched; edits are applied atomically without restarting the server or dropping the port.
- 📊 **Web UI integration** — Service start/stop/restart/status with a live status light, all managed from the OPNsense dashboard.
- 🔬 **Diagnostics** — The settings page reports `kixdns --version` at runtime, so missing binaries / wrong ABI are visible before you touch the pipeline.
- 📜 **Log rotation** — Built-in newsyslog config rotates `/var/log/kixdns/*.log` daily (7 gzip copies) — logs never grow unbounded.
- 📄 **ACL + log viewer** — Ships an ACL so non-admin users can be granted access, and a **Log File** menu entry that streams the kixdns log straight into the GUI.

## 🏗️ How it works

A query enters the pipeline and is routed by `pipeline_select`; each rule can forward to an upstream, inspect the response, and either pass or re-resolve:

```text
 client ──► KixDNS (UDP/TCP :53) ──► pipeline_select ──► rule ──► ISP resolver (UDP)
    │                                                      │
    │                                                      └──► response inspected
    │                                                            ├─ clean    ──► allow
    │                                                            └─ poisoned ──► continue
    │                                                                           │
    │                                                                           └──► rule_1 ──► DoH (Ali/Tencent)
    └──────────────────────────────────── answer ◄─────────────────────────────────┘
```

- **ISP-first** — the common path stays on your carrier's fast resolver (per-ISP upstreams supported).
- **Poisoning fallback** — only when a poisoned response (reserved/forged records) is detected does the query hop to DoH, so fallback cost is paid only when needed.

## 📦 Installation

### 1. Pick the right package (ABI must match)

| OPNsense | pkg ABI | package |
| --- | --- | --- |
| 25.7 / 26.1 | `FreeBSD:14:amd64` | `os-kixdns-community-0.3-FreeBSD_14_amd64.pkg` |
| 26.7 & newer (amd64) | `FreeBSD:15:amd64` | `os-kixdns-community-0.3-FreeBSD_15_amd64.pkg` |
| 26.7 & newer (arm64) | `FreeBSD:15:aarch64` | `os-kixdns-community-0.3-FreeBSD_15_aarch64.pkg` |

Check with `pkg config abi` if unsure.

### 2. Install (no SSH needed)

**System → Diagnostics → Command Prompt** → *Execute Shell Command*:

```sh
# OPNsense 26.7+
pkg add https://github.com/Quan-0505/OPNsense-kixdns-web/releases/download/v0.3/os-kixdns-community-0.3-FreeBSD_15_amd64.pkg

# OPNsense 25.7 / 26.1
pkg add https://github.com/Quan-0505/OPNsense-kixdns-web/releases/download/v0.3/os-kixdns-community-0.3-FreeBSD_14_amd64.pkg

# OPNsense 26.7+ on arm64 (e.g. NanoPi R4S)
pkg add https://github.com/Quan-0505/OPNsense-kixdns-web/releases/download/v0.3/os-kixdns-community-0.3-FreeBSD_15_aarch64.pkg
```

The post-install hook restarts `configd`, runs migrations, and reloads the `OPNsense/KixDNS` + `OPNsense/Syslog` templates automatically — no manual service restart required.

### 3. Take over port 53

1. **Services → KixDNS** → enable the service, tune the pipeline (default `bind` listens on **0.0.0.0:53**).
2. If Unbound / Dnsmasq is running, disable it first so `:53` is free.
3. **Apply Changes**. KixDNS becomes the resolver.

## ✅ Verify

```sh
configctl kixdns version                 # e.g. "kixdns 0.1.0" — binary runs
cat /etc/rc.conf.d/kixdns                # kixdns_enable="YES" once enabled
configctl kixdns status                  # "kixdns is running as pid ..."
sockstat -4 -l | grep ':53'              # KixDNS bound to :53
tail -n 50 /var/log/kixdns/kixdns_*.log  # or Services → KixDNS → Log File
```

## 🔧 Development

`.github/workflows/build-opnsense.yml` cross-compiles the FreeBSD binary (`cross`, `x86_64-unknown-freebsd`), validates it is a FreeBSD ELF, and packages a `+MANIFEST` with `abi`/`arch`/`annotations`/`files`/`scripts` (format checked against real `os-*` packages), releasing two `.pkg` files (FreeBSD 14/15) on `v*` tags.

Local checks (run with Python 3.11):

```sh
python check_plugin.py       # static consistency + regression guards
python check_templates.py    # render configd templates (Jinja2, mirrors configd)
python check_packaging.py    # dry-run the manifest/tar packaging logic
python verify_pkg.py         # independent verification of a built .pkg
python build_pkg.py          # build both ABI packages
```

## 📚 Documentation

- [DIAGNOSIS.md](DIAGNOSIS.md) — the full defect list found in v0.1 and how each was fixed.

## 📄 License

GPL-3.0 — same as upstream [KixDNS](https://github.com/olicesx/kixdns).
