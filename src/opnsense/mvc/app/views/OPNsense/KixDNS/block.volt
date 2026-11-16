{#
  Application blocking at the DNS layer.

  One row per app the engine identified; the suffix count comes from the rulepack
  ([rules] + [subkeys]), so "封堵 Bilibili" expands to every name suffix that
  actually belongs to it. Preview first, then write — the write path backs up the
  current pipeline and reloads kixdns.
#}

<script>
$(document).ready(function () {
    var POLL = 5000;

    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }
    function hb(v) {
        v = Number(v) || 0;
        var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
        while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
        return (i === 0 ? v.toFixed(0) : v.toFixed(v < 10 ? 2 : 1)) + ' ' + u[i];
    }
    function postJ(url, data) {
        return $.ajax({ url: url, type: 'POST', data: data, dataType: 'json',
                        headers: { 'X-CSRFToken': window.csrfToken || '' } });
    }
    function picked() {
        return $('.kb-pick:checked').map(function () { return this.value; }).get().join(',');
    }

    function loadApps() {
        $.getJSON('/api/kixdns/apps/list').done(function (d) {
            var rows = (d.apps || []).map(function (a) {
                return '<tr>'
                    + '<td><input type="checkbox" class="kb-pick" value="' + a.app_id + '"></td>'
                    + '<td><strong>' + esc(a.name) + '</strong> <span class="kb-muted">#' + a.app_id + '</span></td>'
                    + '<td class="kb-num">' + (a.bytes ? hb(a.bytes) : '<span class="kb-muted">-</span>') + '</td>'
                    + '<td class="kb-num">' + (a.suffix_count || 0) + '</td>'
                    + '<td>' + ((a.suffixes || []).slice(0, 4).map(function (s) {
                        return '<span class="kb-tag">' + esc(s) + '</span>';
                    }).join(' ') || '<span class="kb-muted">-</span>') + '</td>'
                    + '<td>' + (a.blocked
                        ? '<span class="kb-tag kb-err">已封堵</span>'
                        : '<span class="kb-muted">-</span>') + '</td>'
                    + '</tr>';
            }).join('');
            $('#kb-body').html(rows || '<tr><td colspan="6" class="kb-muted">没有数据</td></tr>');
            $('#kb-state').text(
                '已封堵 ' + (d.blocked_count || 0) + ' 个应用 · 规则来源 ' + (d.pipeline_source || '?')
            );
        }).fail(function (xhr) {
            $('#kb-body').html('<tr><td colspan="6" class="kb-muted">接口失败 ' + esc(xhr.status) + '</td></tr>');
        });
    }

    function doBlock(mode) {
        var ids = picked();
        var out = $('#kb-out');
        if (!ids) { out.text('先勾选要封堵的应用'); return; }
        out.text(mode === 'apply' ? '写入中…' : '生成预览…');
        postJ('/api/kixdns/apps/block', {
            apps: ids, mode: mode, rcode: $('#kb-rcode').val()
        }).done(function (d) {
            if (d.result === 'failed') { out.text('失败：' + d.message); return; }
            out.html('规则 <b>' + d.rule_count + '</b> 条 · 域名后缀 <b>' + d.suffix_count
                + '</b> 个 · pipeline <code>' + esc(d.pipeline) + '</code> · 应答 ' + esc(d.rcode)
                + (d.result === 'applied'
                    ? ' · <span class="kb-tag kb-ok">已写入并重载</span> 备份 <code>' + esc(d.backup) + '</code>'
                    : ' · <span class="kb-tag kb-warn">仅预览，未写入</span>'));
            if (mode === 'apply') { loadApps(); }
        }).fail(function (xhr) { out.text('接口失败 ' + xhr.status); });
    }

    function doUnblock() {
        var ids = picked();
        var out = $('#kb-out');
        if (!ids) { out.text('先勾选要解封的应用'); return; }
        if (!window.confirm('解除勾选应用的 DNS 封堵？会重启 kixdns（约 1 秒）。')) { return; }
        out.text('解封中…');
        postJ('/api/kixdns/apps/unblock', { apps: ids }).done(function (d) {
            if (d.result === 'failed') { out.text('失败：' + d.message); return; }
            out.html(d.removed
                ? '<span class="kb-tag kb-ok">已解除 ' + d.removed + ' 条规则</span> ' + esc((d.rules || []).join(' / '))
                : '<span class="kb-tag kb-warn">' + esc(d.message || '这些应用没有封堵规则') + '</span>');
            loadApps();
        }).fail(function (xhr) { out.text('接口失败 ' + xhr.status); });
    }

    $('#kb-preview').on('click', function () { doBlock('preview'); });
    $('#kb-apply').on('click', function () {
        if (window.confirm('把这些应用的全部域名后缀写成 DNS 封堵规则，并重启 kixdns（DNS 中断约 1 秒）。继续？')) {
            doBlock('apply');
        }
    });
    $('#kb-unblock').on('click', doUnblock);
    $('#kb-all').on('change', function () { $('.kb-pick').prop('checked', this.checked); });
    $('#kb-refresh').on('click', loadApps);

    loadApps();
    setInterval(loadApps, POLL);
});
</script>

<style>
    .kb-panel { background:#fff; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:14px; }
    .kb-head { padding:9px 14px; border-bottom:1px solid #edf2f7; font-weight:600; font-size:13px; }
    .kb-head .kb-muted { font-weight:400; margin-left:8px; }
    .kb-body { padding:12px 14px; }
    .kb-muted { color:#a0aec0; font-weight:400; }
    .kb-num { text-align:right; font-variant-numeric: tabular-nums; }
    .kb-tag { display:inline-block; padding:1px 6px; border-radius:3px; background:#edf2f7;
              color:#4a5568; font-size:11px; margin:1px 2px; }
    .kb-tag.kb-err { background:#fed7d7; color:#822727; }
    .kb-tag.kb-ok { background:#c6f6d5; color:#22543d; }
    .kb-tag.kb-warn { background:#fefcbf; color:#744210; }
    .kb-table { width:100%; border-collapse:collapse; font-size:12px; }
    .kb-table th { text-align:left; color:#718096; font-weight:600; padding:6px 8px;
                   border-bottom:1px solid #edf2f7; white-space:nowrap; }
    .kb-table td { padding:6px 8px; border-bottom:1px solid #f7fafc; }
    .kb-warnbox { background:#fffff0; border:1px solid #f6e05e; border-radius:6px;
                  padding:10px 14px; margin-bottom:12px; font-size:13px; color:#744210; }
</style>

<div class="kb-panel">
    <div class="kb-head">
        <i class="fa fa-ban"></i> 应用封堵（DNS 层）
        <span class="kb-muted">按 appdpi 的应用身份，把它的全部域名后缀一次性写成 DNS 封堵规则</span>
    </div>
    <div class="kb-body">
        <div class="kb-warnbox">
            <b>这是一个写操作。</b>封堵作用在 DNS 层：命中后缀的查询直接返回指定应答，客户端拿不到地址。
            对使用 HTTPDNS / 私有 CDN 节点的客户端只能挡住入口，挡不住已经建立的直连 —— 缓存内容仍可能播放。
        </div>
        <div class="kb-toolbar" style="margin-bottom:10px;">
            <select id="kb-rcode" class="form-control input-sm" style="width:auto; display:inline-block;">
                <option value="NXDOMAIN">NXDOMAIN（域不存在）</option>
                <option value="NOERROR">NOERROR（空应答）</option>
                <option value="REFUSED">REFUSED（拒绝）</option>
            </select>
            <button id="kb-preview" class="btn btn-default btn-sm" type="button"><i class="fa fa-eye"></i> 预览规则</button>
            <button id="kb-apply" class="btn btn-danger btn-sm" type="button"><i class="fa fa-shield"></i> 写入并重载 kixdns</button>
            <button id="kb-unblock" class="btn btn-success btn-sm" type="button"><i class="fa fa-unlock"></i> 解除封堵</button>
            <button id="kb-refresh" class="btn btn-default btn-sm" type="button"><i class="fa fa-refresh"></i></button>
            <span class="kb-tag" id="kb-state">…</span>
        </div>
        <div id="kb-out" class="kb-muted" style="margin-bottom:8px;">勾选应用后先预览，确认无误再写入</div>
        <table class="kb-table">
            <thead><tr>
                <th style="width:28px;"><input type="checkbox" id="kb-all"></th>
                <th>应用</th><th class="kb-num">流量</th><th class="kb-num">域名后缀</th>
                <th>后缀样例</th><th style="width:70px;">状态</th>
            </tr></thead>
            <tbody id="kb-body"><tr><td colspan="6" class="kb-muted">…</td></tr></tbody>
        </table>
    </div>
</div>
