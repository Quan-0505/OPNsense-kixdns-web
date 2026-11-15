{#
 # DNS x DPI fusion view — joins the KixDNS query log with the appdpi engine
 # snapshot on client IP and domain name.
 #
 # Data: /api/kixdns/fusion/overview  (FusionController, read-only)
 #}
<style>
    .fx-wrap { padding: 14px 2px 24px; }
    .fx-toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
    .fx-toolbar .form-control { width:auto; min-width:110px; }
    .fx-chip { font-size:11px; color:#4a5568; background:#f7fafc; border:1px solid #e2e8f0;
               border-radius:12px; padding:3px 10px; }
    .fx-grid { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
    .fx-card { flex:1; min-width:158px; background:#fff; border:1px solid #e2e8f0;
               border-radius:10px; padding:12px 14px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .fx-card .fx-label { font-size:11px; color:#718096; letter-spacing:.3px; }
    .fx-card .fx-value { font-size:24px; font-weight:700; color:#2d3748; line-height:1.2; margin-top:3px;
                         font-variant-numeric:tabular-nums; }
    .fx-card .fx-sub { font-size:11px; color:#a0aec0; margin-top:3px; }
    .fx-card.dns { border-top:2px solid #4299e1; }
    .fx-card.dpi { border-top:2px solid #48bb78; }
    .fx-card.mix { border-top:2px solid #9f7aea; }
    .fx-panel { background:#fff; border:1px solid #e2e8f0; border-radius:10px; margin-bottom:14px;
                box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .fx-panel-head { padding:9px 14px; border-bottom:1px solid #edf2f7; font-weight:600; font-size:13px;
                     color:#2d3748; display:flex; justify-content:space-between; align-items:center; }
    .fx-panel-body { padding:10px 14px; }
    .fx-panel-body.tight { padding:0; }
    .fx-table { width:100%; font-size:13px; border-collapse:collapse; table-layout:fixed; }
    .fx-table th { text-align:left; color:#718096; font-weight:600; font-size:11px; letter-spacing:.3px;
                   padding:7px 8px; border-bottom:1px solid #edf2f7; white-space:nowrap;
                   overflow:hidden; text-overflow:ellipsis; }
    .fx-table td { padding:6px 8px; border-bottom:1px solid #f7fafc; color:#2d3748;
                   vertical-align:middle; overflow:hidden; }
    .fx-table tbody tr:hover td { background:#f7fafc; }
    .fx-table td.num, .fx-table th.num { text-align:right; font-variant-numeric:tabular-nums;
                                         white-space:nowrap; }
    .fx-table td.mono { font-family:'Consolas','Monaco',monospace; font-size:12px; }
    .fx-bar { height:6px; border-radius:3px; background:#4299e1; display:inline-block; vertical-align:middle; }
    .fx-bar.g { background:#48bb78; }
    .fx-tag { display:inline-block; padding:1px 7px; border-radius:10px; font-size:11px; font-weight:600;
              background:#edf2f7; color:#4a5568; }
    .fx-tag.app { background:#e6fffa; color:#234e52; }
    .fx-tag.rule { background:#ebf8ff; color:#2a4365; }
    .fx-tag.dpi { background:#f0fff4; color:#22543d; }
    .fx-tag.none { background:#fff5f5; color:#822727; }
    .fx-tag.warn { background:#fefcbf; color:#744210; }
    .fx-tag.err { background:#fed7d7; color:#822727; }
    .fx-tag.ok { background:#c6f6d5; color:#22543d; }
    .fx-muted { color:#a0aec0; font-size:12px; }
    .fx-dom { display:block; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .fx-ins { border-left:3px solid #4299e1; background:#f7fafc; border-radius:4px;
              padding:8px 12px; margin-bottom:8px; font-size:13px; color:#2d3748; }
    .fx-ins.warn { border-left-color:#ecc94b; background:#fffff0; }
    .fx-ins.err { border-left-color:#f56565; background:#fff5f5; }
    .fx-ins.ok { border-left-color:#48bb78; background:#f0fff4; }
    .fx-chips { display:flex; gap:6px; flex-wrap:wrap; }
    .fx-chipd { font-size:12px; background:#fff5f5; color:#822727; border:1px solid #fed7d7;
                border-radius:6px; padding:2px 8px; font-family:'Consolas','Monaco',monospace; }
    .fx-warnbox { background:#fffaf0; border:1px solid #fbd38d; color:#7b341e; border-radius:8px;
                  padding:8px 12px; margin-bottom:12px; font-size:12.5px; }
    .fx-spin { display:inline-block; animation:fxspin 1s linear infinite; }
    @keyframes fxspin { to { transform:rotate(360deg); } }
</style>

<div class="fx-wrap">
    <div class="fx-toolbar">
        <label class="fx-muted" for="fx-window">DNS 扫描窗口</label>
        <select id="fx-window" class="form-control input-sm">
            <option value="4194304">最近 4 MB</option>
            <option value="16777216" selected>最近 16 MB</option>
            <option value="67108864">最近 64 MB</option>
            <option value="268435456">今日全量（最多 256 MB）</option>
        </select>
        <button id="fx-refresh" class="btn btn-primary btn-sm"><i class="fa fa-refresh"></i> 刷新</button>
        <label class="checkbox-inline fx-muted" style="margin-left:6px;">
            <input type="checkbox" id="fx-auto" checked> 自动刷新 30s
        </label>
        <span class="fx-chip" id="fx-meta">加载中…</span>
        <span class="fx-chip" id="fx-scope" title="只统计这些网段的客户端，其余网段与 IPv6 链路本地地址不计">…</span>
        <span style="flex:1"></span>
        <a class="fx-chip" href="/ui/appdpi" title="打开应用识别页"><i class="fa fa-chart-pie"></i> 应用识别</a>
        <a class="fx-chip" href="/ui/kixdns" title="打开 KixDNS 页"><i class="fa fa-database"></i> KixDNS</a>
    </div>

    <div id="fx-warn"></div>

    <div class="fx-grid" id="fx-kpi-dns"></div>
    <div class="fx-grid" id="fx-kpi-dpi"></div>
    <div class="fx-grid" id="fx-kpi-mix"></div>

    <div class="fx-panel">
        <div class="fx-panel-head"><span><i class="fa fa-lightbulb-o"></i> 交叉洞察（DNS 与流量对不上时最有价值）</span></div>
        <div class="fx-panel-body" id="fx-insights"><span class="fx-muted">…</span></div>
    </div>

    <div class="fx-panel">
        <div class="fx-panel-head">
            <span><i class="fa fa-desktop"></i> 设备画像 <span class="fx-muted" id="fx-dev-count"></span></span>
            <span class="fx-muted">DNS 查询来自 kixdns · 流量/应用来自 appdpi</span>
        </div>
        <div class="fx-panel-body tight">
            <table class="fx-table" id="fx-dev-table">
                <colgroup>
                    <col style="width:13%"><col style="width:12%"><col style="width:8%">
                    <col style="width:8%"><col style="width:9%"><col style="width:9%">
                    <col style="width:8%"><col style="width:8%"><col style="width:25%">
                </colgroup>
                <thead><tr>
                    <th>设备</th><th>MAC</th><th>当前下行</th><th class="num">上行</th>
                    <th>应用 Top</th><th class="num">DNS 查询</th><th class="num">唯一域名</th>
                    <th class="num">NXDOMAIN</th><th>查询 Top</th>
                </tr></thead>
                <tbody><tr><td colspan="9" class="fx-muted">…</td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="fx-panel">
        <div class="fx-panel-head">
            <span><i class="fa fa-globe"></i> 域名融合排行 <span class="fx-muted" id="fx-dom-count"></span></span>
            <span class="fx-muted">查询数来自 KixDNS · 应用归属来自 appdpi 规则库</span>
        </div>
        <div class="fx-panel-body tight">
            <table class="fx-table" id="fx-dom-table">
                <colgroup>
                    <col style="width:34%"><col style="width:12%"><col style="width:12%">
                    <col style="width:9%"><col style="width:9%"><col style="width:11%">
                    <col style="width:13%">
                </colgroup>
                <thead><tr>
                    <th>域名</th><th>应用</th><th class="num">DNS 查询</th><th class="num">客户端</th>
                    <th class="num">DPI 命中</th><th class="num">DPI 流量</th><th>一致性</th>
                </tr></thead>
                <tbody><tr><td colspan="7" class="fx-muted">…</td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="fx-panel">
        <div class="fx-panel-head">
            <span><i class="fa fa-th-large"></i> 应用融合排行</span>
            <span class="fx-muted">字节数来自 appdpi · DNS 列是把域名按规则归并后的查询量</span>
        </div>
        <div class="fx-panel-body tight">
            <table class="fx-table" id="fx-app-table">
                <colgroup>
                    <col style="width:20%"><col style="width:14%"><col style="width:10%">
                    <col style="width:10%"><col style="width:10%"><col style="width:36%">
                </colgroup>
                <thead><tr>
                    <th>应用</th><th class="num">流量</th><th class="num">连接</th>
                    <th class="num">DNS 查询</th><th class="num">域名数</th><th>域名样本</th>
                </tr></thead>
                <tbody><tr><td colspan="6" class="fx-muted">…</td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="fx-panel" id="fx-unmatched-panel">
        <div class="fx-panel-head">
            <span><i class="fa fa-question-circle"></i> 未识别域名（规则库盲区）</span>
            <span class="fx-muted">把这些补进 appdpi 规则，覆盖率会直接上去</span>
        </div>
        <div class="fx-panel-body"><div class="fx-chips" id="fx-unmatched"><span class="fx-muted">…</span></div></div>
    </div>

    <div class="fx-panel">
        <div class="fx-panel-head">
            <span><i class="fa fa-ban"></i> 应用封堵（DNS 层）</span>
            <span class="fx-muted">按 appdpi 的应用身份，在 DNS 层把它的全部域名后缀一次性封掉</span>
        </div>
        <div class="fx-panel-body">
            <div class="fx-toolbar">
                <select id="fx-block-rcode" class="form-control input-sm">
                    <option value="NXDOMAIN">NXDOMAIN（域不存在）</option>
                    <option value="NOERROR">NOERROR（空应答）</option>
                    <option value="REFUSED">REFUSED（拒绝）</option>
                </select>
                <button id="fx-block-preview" class="btn btn-default btn-sm"><i class="fa fa-eye"></i> 预览规则</button>
                <button id="fx-block-apply" class="btn btn-danger btn-sm"><i class="fa fa-shield"></i> 写入并重载 kixdns</button>
                <span class="fx-chip" id="fx-block-state">…</span>
            </div>
            <div id="fx-block-out" class="fx-muted" style="margin-bottom:8px;">勾选应用后先预览，确认无误再写入</div>
            <table class="fx-table" id="fx-apps-table">
                <thead><tr>
                    <th style="width:30px;"><input type="checkbox" id="fx-apps-all"></th>
                    <th>应用</th><th class="num">流量</th><th class="num">域名后缀</th>
                    <th>后缀样例</th><th>状态</th>
                </tr></thead>
                <tbody><tr><td colspan="6" class="fx-muted">…</td></tr></tbody>
            </table>
        </div>
    </div>

    <div class="fx-panel">
        <div class="fx-panel-head">
            <span><i class="fa fa-share-square-o"></i> 回灌 appdpi（生成它的规则格式）</span>
            <span class="fx-muted">把 DNS 侧看到、引擎没列出的名字写成 appdpi 规则，绕开 --top 截断</span>
        </div>
        <div class="fx-panel-body">
            <div class="fx-toolbar">
                <button id="fx-feed-run" class="btn btn-primary btn-sm"><i class="fa fa-flask"></i> 生成注入文件</button>
                <span class="fx-chip" id="fx-feed-state">未生成</span>
            </div>
            <div id="fx-feed-out" class="fx-muted">生成 <code>/var/run/appdpi/kixdns-feed.rules</code>
                （只固化 appdpi 自己的归属结论，<b>精确域名 exact</b>，不做后缀扩散）、
                <code>/var/run/appdpi/panabit+kixdns.rulepack</code>（合并版）以及
                <code>/var/run/appdpi/kixdns-unmatched.csv</code>（两引擎都归不了的域名清单，供人工补规则）。
                appdpi 不支持同时加载 <code>--rules</code> 与 <code>--rulepack</code>，要用合并版并重启 appdpi 才生效。</div>
        </div>
    </div>

    <div class="fx-panel">
        <div class="fx-panel-head">
            <span><i class="fa fa-bell-o"></i> 异常告警（cron 每 10 分钟）</span>
            <span class="fx-muted">同一类发现在 6 小时内不重复报</span>
        </div>
        <div class="fx-panel-body">
            <div class="fx-toolbar">
                <button id="fx-alert-test" class="btn btn-default btn-sm"><i class="fa fa-stethoscope"></i> 试运行（只看不发）</button>
                <span class="fx-chip">配置 <code>/usr/local/etc/kixdns-fusion-alert.json</code></span>
                <span class="fx-chip">日志 <code>/var/log/kixdns-fusion-alert.log</code></span>
            </div>
            <pre id="fx-alert-out" style="max-height:200px;overflow:auto;background:#f7fafc;border:1px solid #e2e8f0;border-radius:6px;padding:8px;font-size:12px;color:#4a5568;">…</pre>
        </div>
    </div>
</div>

<script>
(function () {
    var timer = null;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
    function num(n) {
        if (n == null) { return '-'; }
        n = Number(n);
        return isFinite(n) ? n.toLocaleString() : '-';
    }
    function hbytes(n) {
        n = Number(n) || 0;
        var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0, v = n;
        while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
        return (i === 0 ? v.toFixed(0) : v.toFixed(v < 10 ? 2 : 1)) + ' ' + u[i];
    }
    function hbps(n) {
        n = Number(n) || 0;
        if (n <= 0) { return '0'; }
        var u = ['b/s', 'kb/s', 'Mb/s', 'Gb/s'], i = 0, v = n;
        while (v >= 1000 && i < u.length - 1) { v /= 1000; i++; }
        return (i === 0 ? v.toFixed(0) : v.toFixed(v < 10 ? 2 : 1)) + ' ' + u[i];
    }
    function pct(a, b) { return b > 0 ? Math.round(1000 * a / b) / 10 : 0; }

    function card(kind, label, value, sub) {
        return '<div class="fx-card ' + kind + '"><div class="fx-label">' + esc(label) + '</div>'
            + '<div class="fx-value">' + value + '</div>'
            + '<div class="fx-sub">' + (sub || '&nbsp;') + '</div></div>';
    }

    function render(d) {
        var dns = d.dns || {}, dpi = d.dpi || {};

        // ---- warnings -------------------------------------------------
        var w = (d.warnings || []).slice();
        if (dpi.available === false) {
            w.unshift('appdpi 引擎未运行：页面只剩 DNS 半边，应用列将全为「未识别」');
        }
        document.getElementById('fx-warn').innerHTML = w.length
            ? '<div class="fx-warnbox"><i class="fa fa-exclamation-triangle"></i> ' +
              w.map(esc).join('<br>') + '</div>'
            : '';

        // ---- DNS side -------------------------------------------------
        var rc = dns.rcode || {};
        var ok = (rc['NoError'] || 0), nx = (rc['NXDomain'] || 0), sf = (rc['ServFail'] || 0);
        var rcLine = [];
        Object.keys(rc).forEach(function (k) { rcLine.push(k + ' ' + num(rc[k])); });
        document.getElementById('fx-kpi-dns').innerHTML =
            card('dns', 'DNS 查询（窗口）', num(dns.queries),
                 '窗口 ' + esc(dns.window_from || '?') + ' → ' + esc(dns.window_to || '?')
                 + ' · ' + hbytes(dns.window_bytes)) +
            card('dns', '唯一域名', num(dns.unique_domains), '客户端 ' + num(dns.clients) + ' 台') +
            card('dns', 'NoError / NXDomain', num(ok) + ' / ' + num(nx),
                 'NX 占比 ' + pct(nx, dns.queries) + '% · ServFail ' + num(sf)) +
            card('dns', '未识别查询', num(dns.unmatched_queries),
                 '占 ' + pct(dns.unmatched_queries, dns.queries) + '% · 扫描 ' +
                 num(dns.scan_ms) + ' ms' + (dns.cached ? ' (缓存)' : ''));

        // ---- DPI side -------------------------------------------------
        document.getElementById('fx-kpi-dpi').innerHTML =
            card('dpi', '在线设备', num(dpi.online_devices),
                 'IPv4 ' + num(dpi.online_devices_v4) + ' · IPv6 ' + num(dpi.online_devices_v6)
                 + ' · 唯一 MAC ' + num(dpi.unique_macs)) +
            card('dpi', '识别应用', num(dpi.apps_detected),
                 '规则库 ' + num(dpi.apps_loaded) + ' 应用 / ' + num(dpi.rules) + ' 规则'
                 + (dpi.iface ? ' · ' + esc(dpi.iface) : '')) +
            card('dpi', '当前下行', hbps(dpi.bps_in), '') +
            card('dpi', '当前上行', hbps(dpi.bps_out),
                 dpi.status_age_s != null ? ('快照 ' + dpi.status_age_s + 's 前') : '');

        // ---- fusion side ---------------------------------------------
        var doms = d.domains || [], apps = d.apps || [], devs = d.devices || [];
        var both = 0, dnsOnly = 0, dpiOnly = 0;
        doms.forEach(function (r) {
            if (r.dpi_bytes > 0 && r.queries > 0) { both++; }
            else if (r.dpi_bytes === 0) { dnsOnly++; }
        });
        (d.unmatched || []).forEach(function () { });
        document.getElementById('fx-kpi-mix').innerHTML =
            card('mix', '双引擎都可见的域名', num(both), 'DNS 有查询 + DPI 有流量') +
            card('mix', '只有 DNS 的域名', num(dnsOnly), '查了但引擎没看到流量') +
            card('mix', 'DNS+DPI 客户端并集', num(devs.length), '设备画像行数') +
            card('mix', '页面耗时', num(d.elapsed_ms) + ' ms', '生成于 ' + esc(d.generated));

        // ---- insights -------------------------------------------------
        var ins = d.insights || [];
        document.getElementById('fx-insights').innerHTML = ins.length
            ? ins.map(function (i) {
                return '<div class="fx-ins ' + esc(i.level) + '">' + esc(i.text) + '</div>';
            }).join('')
            : '<span class="fx-muted">没有洞察</span>';

        // ---- devices --------------------------------------------------
        var maxQ = 1;
        devs.forEach(function (x) { if (x.dns_queries > maxQ) { maxQ = x.dns_queries; } });
        var dh = devs.map(function (x) {
            var nxr = x.dns_queries > 0 ? (100 * x.nxdomain / x.dns_queries) : 0;
            var nxTag = x.nxdomain > 0
                ? '<span class="fx-tag ' + (nxr >= 30 ? 'err' : 'warn') + '">' +
                  x.nxdomain + ' (' + nxr.toFixed(0) + '%)</span>'
                : '<span class="fx-muted">0</span>';
            var topDom = (x.top_domains || []).slice(0, 3).map(function (t) {
                return '<span class="fx-dom" title="' + esc(t.q) + '">' + esc(t.q) +
                    (t.app ? ' <span class="fx-tag dpi">' + esc(t.app) + '</span>' : '') + '</span>';
            }).join('') || '<span class="fx-muted">-</span>';
            var appTag = x.top_app ? '<span class="fx-tag app">' + esc(x.top_app) + '</span>' : '<span class="fx-muted">-</span>';
            return '<tr>'
                + '<td><strong>' + esc(x.ip) + '</strong>'
                + (x.hostname ? '<div class="fx-muted">' + esc(x.hostname) + '</div>' : '') + '</td>'
                + '<td class="mono">' + (x.mac ? esc(x.mac) : '<span class="fx-muted">-</span>') + '</td>'
                + '<td class="num">' + hbps(x.in_bps) + '</td>'
                + '<td class="num">' + hbps(x.out_bps) + '</td>'
                + '<td>' + appTag + '</td>'
                + '<td class="num">' + num(x.dns_queries)
                + '<div><span class="fx-bar" style="width:' + Math.max(2, Math.round(48 * x.dns_queries / maxQ)) + 'px"></span></div></td>'
                + '<td class="num">' + num(x.dns_domains) + '</td>'
                + '<td class="num">' + nxTag + '</td>'
                + '<td>' + topDom + '</td>'
                + '</tr>';
        }).join('');
        document.querySelector('#fx-dev-table tbody').innerHTML = dh ||
            '<tr><td colspan="9" class="fx-muted">没有数据</td></tr>';
        document.getElementById('fx-dev-count').textContent = '（' + devs.length + ' 台）';

        // ---- domains --------------------------------------------------
        var maxD = 1;
        doms.forEach(function (x) { if (x.queries > maxD) { maxD = x.queries; } });
        var dhtml = doms.map(function (x) {
            var srcTag = x.app
                ? '<span class="fx-tag ' + esc(x.src) + '">' + esc(x.app) + '</span>'
                : (x.conflict
                    ? '<span class="fx-tag none" title="appdpi 判未识别；规则库判为 '
                      + esc(x.rule_app) + '（以 appdpi 为准）">未识别 ⚠</span>'
                    : '<span class="fx-tag none">未识别</span>');
            var cons = (x.dpi_bytes > 0 && x.queries > 0)
                ? '<span class="fx-tag ok">双引擎</span>'
                : (x.queries > 0 ? '<span class="fx-tag warn">仅 DNS</span>' : '<span class="fx-tag">仅流量</span>');
            return '<tr>'
                + '<td class="mono"><span class="fx-dom" title="' + esc(x.qname) + '">' + esc(x.qname) + '</span></td>'
                + '<td>' + srcTag + '</td>'
                + '<td class="num">' + num(x.queries)
                + '<div><span class="fx-bar" style="width:' + Math.max(2, Math.round(56 * x.queries / maxD)) + 'px"></span></div></td>'
                + '<td class="num">' + num(x.clients) + '</td>'
                + '<td class="num">' + num(x.dpi_hits) + '</td>'
                + '<td class="num">' + (x.dpi_bytes ? hbytes(x.dpi_bytes) : '<span class="fx-muted">-</span>') + '</td>'
                + '<td>' + cons + '</td>'
                + '</tr>';
        }).join('');
        document.querySelector('#fx-dom-table tbody').innerHTML = dhtml ||
            '<tr><td colspan="7" class="fx-muted">没有数据</td></tr>';
        document.getElementById('fx-dom-count').textContent = '（Top ' + doms.length + '）';

        // ---- apps -----------------------------------------------------
        var maxA = 1;
        apps.forEach(function (x) { if (x.bytes > maxA) { maxA = x.bytes; } });
        var ahtml = apps.map(function (x) {
            var samples = (x.top_domains || []).map(function (t) {
                return '<span class="fx-tag rule">' + esc(t.q) + '</span>';
            }).join(' ') || '<span class="fx-muted">-</span>';
            return '<tr>'
                + '<td><span class="fx-tag app">' + esc(x.name) + '</span>'
                + '<span class="fx-muted"> #' + x.app_id + '</span></td>'
                + '<td class="num">' + (x.bytes ? hbytes(x.bytes) : '<span class="fx-muted">-</span>')
                + '<div><span class="fx-bar g" style="width:' + Math.max(2, Math.round(70 * x.bytes / maxA)) + 'px"></span></div></td>'
                + '<td class="num">' + num(x.flows) + '</td>'
                + '<td class="num">' + num(x.dns_queries) + '</td>'
                + '<td class="num">' + num(x.dns_domains) + '</td>'
                + '<td>' + samples + '</td>'
                + '</tr>';
        }).join('');
        document.querySelector('#fx-app-table tbody').innerHTML = ahtml ||
            '<tr><td colspan="6" class="fx-muted">没有数据</td></tr>';

        // ---- unmatched ------------------------------------------------
        var um = d.unmatched || [];
        document.getElementById('fx-unmatched').innerHTML = um.length
            ? um.map(function (x) {
                return '<span class="fx-chipd" title="查询 ' + x.n + ' 次">' + esc(x.q)
                    + ' <span style="opacity:.6">' + x.n + '</span></span>';
            }).join('')
            : '<span class="fx-muted">全部域名都命中了规则库</span>';
        var share = pct(dns.unmatched_queries, dns.queries);
        document.getElementById('fx-unmatched-panel').style.display = um.length ? '' : 'none';

        document.getElementById('fx-meta').textContent =
            '窗口 ' + esc(dns.window_to || '-') + ' · 查询 ' + num(dns.queries)
            + ' · 未识别 ' + share + '% · ' + num(d.elapsed_ms) + 'ms';
        var cidrs = dns.filter_cidrs || [];
        document.getElementById('fx-scope').textContent = cidrs.length
            ? '网段 ' + cidrs.join(' · ')
            : '全部网段';
    }

    function postJ(url, data) {
        return $.ajax({
            url: url, type: 'POST', data: data, dataType: 'json',
            headers: { 'X-CSRFToken': (window.csrfToken || '') }
        });
    }

    // ---- app blocking (DNS layer) -------------------------------------
    function loadApps() {
        $.getJSON('/api/kixdns/apps/list').done(function (d) {
            var rows = (d.apps || []).map(function (a) {
                return '<tr>'
                    + '<td><input type="checkbox" class="fx-app-pick" value="' + a.app_id + '"></td>'
                    + '<td><strong>' + esc(a.name) + '</strong> <span class="fx-muted">#' + a.app_id + '</span></td>'
                    + '<td class="num">' + (a.bytes ? hbytes(a.bytes) : '<span class="fx-muted">-</span>') + '</td>'
                    + '<td class="num">' + num(a.suffix_count) + '</td>'
                    + '<td>' + ((a.suffixes || []).slice(0, 3).map(function (s) {
                        return '<span class="fx-tag rule">' + esc(s) + '</span>';
                    }).join(' ') || '<span class="fx-muted">-</span>') + '</td>'
                    + '<td>' + (a.blocked
                        ? '<span class="fx-tag err">已封堵</span>'
                        : '<span class="fx-muted">-</span>') + '</td>'
                    + '</tr>';
            }).join('');
            document.querySelector('#fx-apps-table tbody').innerHTML = rows ||
                '<tr><td colspan="6" class="fx-muted">没有数据</td></tr>';
            document.getElementById('fx-block-state').textContent =
                '已封堵 ' + (d.blocked_count || 0) + ' 个应用 · 规则来源 ' + (d.pipeline_source || '?');
        }).fail(function (xhr) {
            document.querySelector('#fx-apps-table tbody').innerHTML =
                '<tr><td colspan="6" class="fx-muted">接口失败 ' + esc(xhr.status) + '</td></tr>';
        });
    }

    function picked() {
        return $('.fx-app-pick:checked').map(function () { return this.value; }).get().join(',');
    }

    function doBlock(mode) {
        var ids = picked();
        var out = document.getElementById('fx-block-out');
        if (!ids) { out.textContent = '先勾选要封堵的应用'; return; }
        out.textContent = mode === 'apply' ? '写入中…' : '生成预览…';
        postJ('/api/kixdns/apps/block', {
            apps: ids, mode: mode,
            rcode: document.getElementById('fx-block-rcode').value
        }).done(function (d) {
            if (d.result === 'failed') { out.textContent = '失败：' + d.message; return; }
            out.innerHTML = '规则 <b>' + d.rule_count + '</b> 条 · 域名后缀 <b>' + d.suffix_count
                + '</b> 个 · 目标 pipeline <code>' + esc(d.pipeline) + '</code> · 应答 ' + esc(d.rcode)
                + (d.result === 'applied'
                    ? ' · <span class="fx-tag ok">已写入并重载</span> 备份 <code>' + esc(d.backup) + '</code>'
                    : ' · <span class="fx-tag warn">仅预览，未写入</span>');
            if (mode === 'apply') { loadApps(); }
        }).fail(function (xhr) { out.textContent = '接口失败 ' + xhr.status; });
    }

    // ---- feed back into appdpi ----------------------------------------
    function runFeed() {
        var st = document.getElementById('fx-feed-state');
        var out = document.getElementById('fx-feed-out');
        st.textContent = '生成中…';
        postJ('/api/kixdns/apps/feed', {}).done(function (d) {
            if (d.result === 'failed') { st.textContent = '失败：' + d.message; return; }
            st.textContent = '已固化 ' + d.exact_rules + ' 条精确规则';
            out.innerHTML = 'appdpi 自己的归属结论 <b>' + d.exact_rules + '</b> 条 → 冻结为精确域名规则<br>'
                + '两引擎都归不了的域名 <b>' + d.unmatched_domains + '</b> 个 → 人工补规则清单<br>'
                + '<code>' + esc(d.csv) + '</code>（' + hbytes(d.csv_bytes) + '）<br>'
                + '<code>' + esc(d.rulepack) + '</code>（' + hbytes(d.rulepack_bytes) + '，含原库全部规则）<br>'
                + '<code>' + esc(d.unmatched_csv) + '</code>（' + hbytes(d.unmatched_bytes) + '）<br>'
                + '样例：' + (d.sample || []).map(function (s) {
                    return '<span class="fx-tag rule">' + esc(s) + '</span>';
                }).join(' ') + '<br><span class="fx-muted">' + esc(d.note) + '</span>';
        }).fail(function (xhr) { st.textContent = '接口失败 ' + xhr.status; });
    }

    // ---- alert checker -------------------------------------------------
    function runAlertTest() {
        var out = document.getElementById('fx-alert-out');
        out.textContent = '运行中…';
        postJ('/api/kixdns/apps/alerttest', {}).done(function (d) {
            out.textContent = (d.output || '(无输出)').trim() || '(无输出)';
        }).fail(function (xhr) { out.textContent = '接口失败 ' + xhr.status; });
    }

    function load() {
        var bytes = document.getElementById('fx-window').value || '16777216';
        var btn = document.getElementById('fx-refresh');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-refresh fx-spin"></i> 刷新';
        $.getJSON('/api/kixdns/fusion/overview', { bytes: bytes })
            .done(render)
            .fail(function (xhr) {
                document.getElementById('fx-warn').innerHTML =
                    '<div class="fx-warnbox"><i class="fa fa-exclamation-triangle"></i> 接口调用失败：'
                    + esc(xhr.status + ' ' + (xhr.statusText || '')) + '</div>';
            })
            .always(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-refresh"></i> 刷新';
            });
    }

    function arm() {
        if (timer) { clearInterval(timer); timer = null; }
        if (document.getElementById('fx-auto').checked) {
            timer = setInterval(load, 30000);
        }
    }

    $(function () {
        load();
        arm();
        loadApps();
        document.getElementById('fx-refresh').addEventListener('click', load);
        document.getElementById('fx-window').addEventListener('change', load);
        document.getElementById('fx-auto').addEventListener('change', arm);
        document.getElementById('fx-block-preview').addEventListener('click', function () {
            doBlock('preview');
        });
        document.getElementById('fx-block-apply').addEventListener('click', function () {
            if (window.confirm('将把勾选应用的全部域名后缀写成 DNS 封堵规则，并重启 kixdns（DNS 短暂中断约 1 秒）。继续？')) {
                doBlock('apply');
            }
        });
        document.getElementById('fx-apps-all').addEventListener('change', function () {
            var on = this.checked;
            $('.fx-app-pick').each(function () { this.checked = on; });
        });
        document.getElementById('fx-feed-run').addEventListener('click', runFeed);
        document.getElementById('fx-alert-test').addEventListener('click', runAlertTest);
    });
})();
</script>
