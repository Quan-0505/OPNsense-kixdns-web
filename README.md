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
    <a href="#web-console">Web console</a> &nbsp;&middot;&nbsp;
    <a href="#native-observability">Observability</a> &nbsp;&middot;&nbsp;
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
- 📊 **AdGuardHome-style console** — A dashboard with query statistics, trend chart and Top-N tables, plus a filterable live query log (see [Web console](#web-console)).
- 🎛️ **Service control** — start/stop/restart with a live status light, shown next to the runtime takeover mode and version.
- 🔬 **Diagnostics** — The settings page reports `kixdns --version` at runtime, so missing binaries / wrong ABI are visible before you touch the pipeline.
- 📜 **Log rotation** — Built-in newsyslog config rotates `/var/log/kixdns/*.log` daily (7 gzip copies) — logs never grow unbounded.
- 📄 **ACL + log viewer** — Ships an ACL so non-admin users can be granted access, and a **Log File** menu entry that streams the kixdns log straight into the GUI.

## 📊 Web console

**Services → KixDNS** opens an AdGuardHome-style console with four tabs:

| Tab | Contents |
| --- | --- |
| **Dashboard** | Forwarded-query total, unique domains, average latency and slow-query count; an hourly query-trend chart (Chart.js ships with OPNsense); Top domains / Top clients / upstream distribution; response-code breakdown. |
| **Query Log** | Live table of handled queries (time · client · domain · type · rcode · upstream · latency · cache) with domain/client filters, row-count selector and 3s/5s/15s auto-refresh. |
| **Settings** | Enable/disable, listener label, log level, debug flag, UDP workers — unchanged from before. |
| **Pipeline Editor** | Graphical editing of `settings`, `pipeline_select` and `pipelines` with live JSON preview, import/export and per-rule builders — unchanged from before. |

The panes are backed by `api/kixdns/stats/*`, which parses the kixdns log **incrementally** (byte-offset cursor plus a short-lived cache file), so a resolver producing 100k+ log lines a day stays cheap to summarise.

> **Two data scopes.** With **Debug** enabled (kixdns >= 0.2.0 native observer, see below) the console
> reports true client queries, the real cache hit ratio and end-to-end latency. Without it the only
> records in the log are *forwarded* (upstream-bound) responses — cache hits are invisible, so the
> console then shows that narrower scope and says so in the status strip. Every ratio column always
> uses the total of the active scope as its denominator.

## 🔭 Native observability

kixdns >= 0.2.0 ships an **Observer API** (`EngineObserver`) and installs its reference implementation when the
daemon is started with `--debug`. This plugin passes `--debug` whenever **Settings → Debug** is enabled, so the
engine emits structured lifecycle events into the log:

```
event="request_started"  request_id=41 listener="default" client=192.168.5.106:53121 qname="www.baidu.com" qtype=A
event="cache_miss"       request_id=41
event="upstream_result"  request_id=41 upstream="202.96.128.86:53" outcome=Success latency_us=13004 rcode=No Error
event="request_finished" request_id=41 status=Completed latency_us=13220
event="cache_hit"        request_id=42 kind=Fresh remaining_ttl_s=10
```

The dashboard consumes them and switches to the **observer scope**:

| Metric | observer scope (Debug on) | log scope (Debug off) |
| --- | --- | --- |
| Queries | every client request | forwarded (upstream) responses only |
| Cache hit ratio | real `hits / (hits + misses)` | not available |
| Latency | end-to-end, including answers served from cache | upstream round-trip only |
| Upstream health | per-attempt outcome and latency | response records only |

**Cost and control.** The event stream is verbose — a busy gateway can write 100–250 MB of log per day. Two
things keep that bounded:

* the bundled newsyslog rule rotates daily **and** at 200 MB per file, keeping 3 compressed copies (~600 MB ceiling);
* `kixdns_debug_log_level` in `/etc/rc.conf.d/kixdns` sets the `RUST_LOG` filter used while Debug is on. The
  default is `info,kixdns::observe=debug`, which keeps the observer events and drops the rest of the debug
  stream. Set it to `debug` only when debugging the engine itself.

Reverting is a single switch: turn **Debug** off and the console falls back to log scope with the normal
`info` log volume.

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
| 25.7 / 26.1 | `FreeBSD:14:amd64` | `os-kixdns-community-0.5.1-FreeBSD_14_amd64.pkg` |
| 26.7 & newer (amd64) | `FreeBSD:15:amd64` | `os-kixdns-community-0.5.1-FreeBSD_15_amd64.pkg` |
| 26.7 & newer (arm64) | `FreeBSD:15:aarch64` | `os-kixdns-community-0.5.1-FreeBSD_15_aarch64.pkg` |

Check with `pkg config abi` if unsure.

Each package bundles a kixdns binary cross-built from **upstream `main`** by this repository's CI
(`KIXDNS_REF`, default `main`), so packages track the newest engine rather than the last tagged release.
The bundled binary is **stamped with the engine version at build time**, so `kixdns --version` reports `0.2.0` even though upstream leaves its own `Cargo.toml` at `0.1.0`.

### 2. Install (no SSH needed)

**System → Diagnostics → Command Prompt** → *Execute Shell Command*:

```sh
# OPNsense 26.7+
pkg add https://github.com/Quan-0505/OPNsense-kixdns-web/releases/download/v0.5.1/os-kixdns-community-0.5.1-FreeBSD_15_amd64.pkg

# OPNsense 25.7 / 26.1
pkg add https://github.com/Quan-0505/OPNsense-kixdns-web/releases/download/v0.5.1/os-kixdns-community-0.5.1-FreeBSD_14_amd64.pkg

# OPNsense 26.7+ on arm64 (e.g. NanoPi R4S)
pkg add https://github.com/Quan-0505/OPNsense-kixdns-web/releases/download/v0.5.1/os-kixdns-community-0.5.1-FreeBSD_15_aarch64.pkg
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
curl -s -u "$APIKEY:$APISECRET" https://127.0.0.1/api/kixdns/stats/overview  # console statistics API
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
