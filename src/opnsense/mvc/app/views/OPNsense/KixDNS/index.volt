{#
 # KixDNS management view - AdGuardHome-style UI
 # Copyright (C) 2025 KixDNS Project
 #}
<style>
    .kixdns-editor { min-height: 600px; }
    .kixdns-editor .editor-pane { height: calc(100vh - 250px); overflow-y: auto; border-right: 1px solid #ddd; padding: 15px; }
    .kixdns-editor .preview-pane { height: calc(100vh - 250px); overflow-y: auto; background-color: #1e1e1e; color: #d4d4d4; padding: 15px; }
    .kixdns-editor .matcher-item, .kixdns-editor .action-item { background-color: #f8f9fa; border: 1px solid #dee2e6; padding: 8px; margin-bottom: 8px; border-radius: 4px; }
    .kixdns-editor .rule-card { border-left: 3px solid #17a2b8; margin-bottom: 10px; }
    .kixdns-editor .pipeline-panel { margin-bottom: 15px; }
    .kixdns-editor .section-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 11px; margin-bottom: 5px; }
    .kixdns-editor .badge-matcher { background: #17a2b8; color: white; }
    .kixdns-editor .badge-action { background: #ffc107; color: black; }
    .kixdns-editor .badge-response { background: #28a745; color: white; }
    .kixdns-editor .json-textarea { font-family: 'Consolas', 'Monaco', monospace; font-size: 12px; background: #1e1e1e; color: #d4d4d4; border: none; resize: none; }

    /* ---------- AdGuardHome-style theme (kixdns) ---------- */
    .agh-wrap { padding: 14px 4px 22px; }
    .agh-status { display:flex; align-items:center; gap:10px; font-size:13px; color:#4a5568;
                  background:#f7fafc; border:1px solid #e2e8f0; border-radius:8px; padding:8px 14px; margin-bottom:14px; }
    .agh-dot { width:10px; height:10px; border-radius:50%; background:#cbd5e0; flex:0 0 auto; }
    .agh-dot.on { background:#48bb78; box-shadow:0 0 6px #48bb78; }
    .agh-dot.off { background:#f56565; }
    .agh-sep { color:#cbd5e0; }
    .agh-cards { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:14px; }
    .agh-card { flex:1; min-width:165px; background:#fff; border:1px solid #e2e8f0; border-radius:10px;
                padding:14px 16px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .agh-label { font-size:12px; color:#718096; }
    .agh-value { font-size:26px; font-weight:700; color:#2d3748; margin-top:4px; line-height:1.15; }
    .agh-sub { font-size:11px; color:#a0aec0; margin-top:2px; }
    .agh-panel { background:#fff; border:1px solid #e2e8f0; border-radius:10px;
                 margin-bottom:14px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .agh-panel-head { padding:10px 14px; border-bottom:1px solid #edf2f7; font-weight:600;
                      font-size:13px; color:#2d3748; }
    .agh-panel-body { padding:12px 14px; }
    .agh-table { width:100%; font-size:13px; border-collapse:collapse; }
    .agh-table th { text-align:left; color:#718096; font-weight:600; font-size:12px;
                    padding:6px 8px; border-bottom:1px solid #edf2f7; }
    .agh-table td { padding:6px 8px; border-bottom:1px solid #f7fafc; color:#2d3748; }
    .agh-table tbody tr:hover td { background:#f7fafc; }
    .agh-table td.num, .agh-table th.num { text-align:right; font-variant-numeric:tabular-nums; }
    .agh-bar { height:6px; border-radius:3px; background:#48bb78; display:inline-block; vertical-align:middle; }
    .agh-muted { color:#a0aec0; font-size:12px; }
    .agh-toolbar { display:flex; gap:8px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
    .agh-toolbar input.form-control, .agh-toolbar select.form-control { width:auto; min-width:150px; }
    .agh-pill { display:inline-block; padding:1px 7px; border-radius:10px; font-size:11px; font-weight:600; }
    .agh-pill.ok { background:#c6f6d5; color:#22543d; }
    .agh-pill.warn { background:#fefcbf; color:#744210; }
    .agh-pill.err { background:#fed7d7; color:#822727; }
    .agh-pill.cache { background:#bee3f8; color:#2a4365; }
    .agh-pill.dim { background:#edf2f7; color:#4a5568; }
    .agh-log td { white-space:nowrap; }
    .agh-log td.k-domain { max-width:360px; overflow:hidden; text-overflow:ellipsis; }

</style>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#dashboard"><i class="fa fa-tachometer"></i> {{ lang._('Dashboard') }}</a></li>
    <li><a data-toggle="tab" href="#querylog" id="querylog-tab"><i class="fa fa-search"></i> {{ lang._('Query Log') }}</a></li>
    <li><a data-toggle="tab" href="#general"><i class="fa fa-cog"></i> {{ lang._('Settings') }}</a></li>
    <li><a data-toggle="tab" href="#editor" id="editor-tab"><i class="fa fa-sitemap"></i> {{ lang._('Pipeline Editor') }}</a></li>
</ul>

<div class="tab-content content-box">
    <!-- Dashboard (AdGuardHome style) -->
    <div id="dashboard" class="tab-pane fade in active">
        <div class="agh-wrap">
            <div class="agh-status">
                <span class="agh-dot" id="k-dot"></span>
                <strong id="k-state">...</strong>
                <span class="agh-sep">&middot;</span>
                <span id="k-version" class="agh-muted">...</span>
                <span class="agh-sep">&middot;</span>
                <span class="agh-muted">bind <code id="k-bind">...</code></span>
                <span class="agh-sep">&middot;</span>
                <span class="agh-muted">接管 takeover <span id="k-mode">...</span></span>
                <span class="agh-sep">&middot;</span>
                <span class="agh-muted" id="k-debug">...</span>
                <span class="agh-sep">&middot;</span>
                <span class="agh-muted" id="k-scope"></span>
                <span class="pull-right agh-muted" id="k-generated"></span>
            </div>

            <div class="agh-cards">
                <div class="agh-card">
                    <div class="agh-label" id="k-c1-label">Queries</div>
                    <div class="agh-value" id="k-total">&ndash;</div>
                    <div class="agh-sub" id="k-total-sub">&nbsp;</div>
                </div>
                <div class="agh-card">
                    <div class="agh-label" id="k-c2-label">Unique domains</div>
                    <div class="agh-value" id="k-domains">&ndash;</div>
                    <div class="agh-sub" id="k-domains-sub">&nbsp;</div>
                </div>
                <div class="agh-card">
                    <div class="agh-label" id="k-c3-label">Latency</div>
                    <div class="agh-value" id="k-latency">&ndash;</div>
                    <div class="agh-sub" id="k-latency-sub">&nbsp;</div>
                </div>
                <div class="agh-card">
                    <div class="agh-label" id="k-c4-label">Slow queries</div>
                    <div class="agh-value" id="k-slow">&ndash;</div>
                    <div class="agh-sub" id="k-slow-sub">&nbsp;</div>
                </div>
            </div>

            <div class="agh-panel">
                <div class="agh-panel-head">
                    查询趋势 Query trend <span class="agh-muted">(per hour, today)</span>
                    <span class="pull-right agh-muted" id="k-logfile"></span>
                </div>
                <div class="agh-panel-body">
                    <canvas id="k-chart-trend" height="80"></canvas>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="agh-panel">
                        <div class="agh-panel-head">Top 域名 Top queried domains</div>
                        <div class="agh-panel-body">
                            <table class="agh-table"><tbody id="k-top-domains"></tbody></table>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="agh-panel">
                        <div class="agh-panel-head">Top 客户端 Top clients</div>
                        <div class="agh-panel-body">
                            <table class="agh-table"><tbody id="k-top-clients"></tbody></table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-7">
                    <div class="agh-panel">
                        <div class="agh-panel-head">上游响应 Upstreams</div>
                        <div class="agh-panel-body">
                            <table class="agh-table">
                                <thead><tr><th>上游 upstream</th><th class="num">查询数</th><th>占比 share</th></tr></thead>
                                <tbody id="k-upstreams"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="agh-panel">
                        <div class="agh-panel-head">响应码 Response codes</div>
                        <div class="agh-panel-body">
                            <table class="agh-table">
                                <thead><tr><th>code</th><th class="num">查询数</th><th>占比 share</th></tr></thead>
                                <tbody id="k-rcodes"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="agh-muted">
                <i class="fa fa-refresh"></i>
                <a href="#" id="k-refresh">刷新 refresh</a> &middot;
                <label style="font-weight:normal;margin-left:6px;">
                    <input type="checkbox" id="k-autorefresh" checked> 自动刷新 auto (5s)
                </label>
            </div>
        </div>
    </div>

    <!-- Query Log (AdGuardHome style) -->
    <div id="querylog" class="tab-pane fade">
        <div class="agh-wrap">
            <div class="agh-toolbar">
                <input type="text" class="form-control input-sm" id="k-q-domain" placeholder="过滤域名 filter domain">
                <input type="text" class="form-control input-sm" id="k-q-client" placeholder="过滤客户端 filter client">
                <select class="form-control input-sm" id="k-q-limit">
                    <option value="100">100 条</option>
                    <option value="200" selected>200 条</option>
                    <option value="500">500 条</option>
                    <option value="1000">1000 条</option>
                </select>
                <select class="form-control input-sm" id="k-q-auto">
                    <option value="0">自动刷新 off</option>
                    <option value="3000">3s</option>
                    <option value="5000" selected>5s</option>
                    <option value="15000">15s</option>
                </select>
                <button class="btn btn-sm btn-primary" id="k-q-refresh"><i class="fa fa-refresh"></i> 刷新</button>
                <span class="agh-muted" id="k-q-meta"></span>
            </div>
            <div class="agh-panel">
                <div class="agh-panel-body" style="overflow-x:auto;">
                    <table class="agh-table agh-log">
                        <thead>
                            <tr>
                                <th>时间</th><th>客户端</th><th>域名</th><th>类型</th>
                                <th>结果</th><th>上游</th><th class="num">延迟</th><th>缓存</th>
                            </tr>
                        </thead>
                        <tbody id="k-q-body">
                            <tr><td colspan="8" class="agh-muted">加载中 loading...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="general" class="tab-pane fade">
        <div class="content-box" style="padding-bottom: 1.5em;">
            {{ partial("layout_partials/base_form",['fields':generalForm,'id':'frm_general_settings']) }}
            <div class="col-md-12">
                <hr />
                <button class="btn btn-primary" id="saveAct" type="button"><b>{{ lang._('Save') }}</b></button>
                <button class="btn btn-primary" id="reloadAct" type="button"><b>{{ lang._('Apply Changes') }}</b></button>
                <br /><br />
                <div class="text-muted">
                    <i class="fa fa-info-circle"></i>
                    {{ lang._('Installed KixDNS binary') }}: <span id="kixdns_version">...</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Pipeline Editor Tab -->
    <div id="editor" class="tab-pane fade">
        <div class="kixdns-editor">
            <div class="row">
                <!-- Editor Pane -->
                <div class="col-md-7 editor-pane">
                    <!-- Toolbar -->
                    <div class="btn-toolbar" style="margin-bottom: 15px;">
                        <div class="btn-group">
                            <button class="btn btn-primary" id="btn-load-json"><i class="fa fa-download"></i> {{ lang._('Load from JSON') }}</button>
                            <button class="btn btn-success" id="btn-export-json"><i class="fa fa-upload"></i> {{ lang._('Export JSON') }}</button>
                            <label class="btn btn-default">
                                <i class="fa fa-folder-open"></i> {{ lang._('Import File') }}
                                <input type="file" id="file-import" accept=".json" style="display: none;">
                            </label>
                        </div>
                        <div class="btn-group pull-right">
                            <button class="btn btn-warning" id="btn-save-config"><i class="fa fa-save"></i> {{ lang._('Save') }}</button>
                            <button class="btn btn-danger" id="btn-apply-config"><i class="fa fa-check"></i> {{ lang._('Save & Apply') }}</button>
                        </div>
                    </div>

                    <!-- Global Settings -->
                    <div class="panel panel-default">
                        <div class="panel-heading"><strong><i class="fa fa-cog"></i> {{ lang._('Global Settings') }}</strong></div>
                        <div class="panel-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>{{ lang._('UDP Listen Address') }}</label>
                                        <input type="text" class="form-control input-sm" id="cfg-bind-udp" placeholder="0.0.0.0:5353">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>{{ lang._('TCP Listen Address') }}</label>
                                        <input type="text" class="form-control input-sm" id="cfg-bind-tcp" placeholder="0.0.0.0:5353">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>{{ lang._('Default Upstream') }}</label>
                                        <input type="text" class="form-control input-sm" id="cfg-default-upstream" placeholder="1.1.1.1:53">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>{{ lang._('Min TTL') }}</label>
                                        <input type="number" class="form-control input-sm" id="cfg-min-ttl" placeholder="0">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>{{ lang._('Timeout (ms)') }}</label>
                                        <input type="number" class="form-control input-sm" id="cfg-timeout" placeholder="2000">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>{{ lang._('Jump Limit') }}</label>
                                        <input type="number" class="form-control input-sm" id="cfg-jump-limit" placeholder="10">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>{{ lang._('UDP Pool') }}</label>
                                        <input type="number" class="form-control input-sm" id="cfg-udp-pool" placeholder="0">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pipeline Selectors -->
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <strong><i class="fa fa-random"></i> {{ lang._('Pipeline Selectors') }}</strong>
                            <button class="btn btn-xs btn-primary pull-right" id="btn-add-selector"><i class="fa fa-plus"></i></button>
                        </div>
                        <div class="panel-body" id="selectors-container"></div>
                    </div>

                    <!-- Pipelines -->
                    <div class="panel panel-default">
                        <div class="panel-heading">
                            <strong><i class="fa fa-sitemap"></i> {{ lang._('Pipelines') }}</strong>
                            <button class="btn btn-xs btn-primary pull-right" id="btn-add-pipeline"><i class="fa fa-plus"></i></button>
                        </div>
                        <div class="panel-body" id="pipelines-container"></div>
                    </div>
                </div>

                <!-- JSON Preview Pane -->
                <div class="col-md-5 preview-pane">
                    <h5 style="color: #9cdcfe; margin-bottom: 10px;"><i class="fa fa-code"></i> JSON Preview / Edit</h5>
                    <textarea class="form-control json-textarea" id="json-preview" style="height: calc(100vh - 300px);"></textarea>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    var data_get_map = {'frm_general_settings':"/api/kixdns/settings/get"};

    mapDataToFormUI(data_get_map).done(function(){
        formatTokenizersUI();
        $('.selectpicker').selectpicker('refresh');
        updateServiceControlUI('kixdns');
    });

    // show which binary is installed, this fails loudly when the packaged
    // binary is missing or built for the wrong architecture
    ajaxGet("/api/kixdns/service/version", {}, function(data, status) {
        if (status === 'success' && data && data.version) {
            $("#kixdns_version").text(data.version);
        } else {
            $("#kixdns_version").html('<span class="text-danger">' +
                "{{ lang._('unavailable - /usr/local/bin/kixdns did not run') }}" + '</span>');
        }
    });

    $("#saveAct").click(function(){
        saveFormToEndpoint("/api/kixdns/settings/set", 'frm_general_settings', function(){
            $("#saveAct").blur();
        });
    });

    $("#reloadAct").click(function(){
        saveFormToEndpoint("/api/kixdns/settings/set", 'frm_general_settings', function(){
            ajaxCall("/api/kixdns/service/reconfigure", {}, function(data, status) {
                updateServiceControlUI('kixdns');
                if (data && data.status === 'ok') {
                    BootstrapDialog.show({
                        type: BootstrapDialog.TYPE_SUCCESS,
                        title: "{{ lang._('Success') }}",
                        message: "{{ lang._('Configuration applied successfully.') }}",
                        buttons: [{ label: "{{ lang._('Close') }}", action: function(d) { d.close(); } }]
                    });
                } else {
                    BootstrapDialog.show({
                        type: BootstrapDialog.TYPE_DANGER,
                        title: "{{ lang._('Error reconfiguring KixDNS') }}",
                        message: "{{ lang._('The service did not apply the configuration. Check Services - KixDNS - Log File.') }}",
                        buttons: [{ label: "{{ lang._('Close') }}", action: function(d) { d.close(); } }]
                    });
                }
            });
        });
    });

    // Load editor on tab switch
    $('#editor-tab').on('shown.bs.tab', function() {
        KixDNSEditor.load();
    });
});

// KixDNS Pipeline Editor
var KixDNSEditor = (function($) {
    'use strict';

    var config = {
        version: "1.0",
        settings: {
            min_ttl: 0,
            bind_udp: "0.0.0.0:5353",
            bind_tcp: "0.0.0.0:5353",
            default_upstream: "1.1.1.1:53",
            upstream_timeout_ms: 2000,
            response_jump_limit: 10,
            udp_pool_size: 0
        },
        pipeline_select: [],
        pipelines: []
    };

    var SELECTOR_MATCHER_TYPES = {
        'listener_label': 'Listener Label',
        'client_ip': 'Client IP (CIDR)',
        'domain_suffix': 'Domain Suffix',
        'domain_regex': 'Domain Regex',
        'qclass': 'QClass',
        'edns_present': 'EDNS Present',
        'any': 'Any'
    };

    var REQUEST_MATCHER_TYPES = {
        'any': 'Any',
        'domain_suffix': 'Domain Suffix',
        'domain_regex': 'Domain Regex',
        'client_ip': 'Client IP (CIDR)',
        'qclass': 'QClass',
        'edns_present': 'EDNS Present'
    };

    var RESPONSE_MATCHER_TYPES = {
        'upstream_equals': 'Upstream Equals',
        'request_domain_suffix': 'Req Domain Suffix',
        'request_domain_regex': 'Req Domain Regex',
        'response_type': 'Response Type',
        'response_rcode': 'Response RCode',
        'response_qclass': 'Response QClass',
        'response_edns_present': 'Response EDNS',
        'response_upstream_ip': 'Upstream IP (CIDR)',
        'response_answer_ip': 'Answer IP (CIDR)'
    };

    var MATCHER_FIELDS = {
        'listener_label': ['value'], 'client_ip': ['cidr'], 'domain_suffix': ['value'],
        'domain_regex': ['value'], 'qclass': ['value'], 'edns_present': ['expect'],
        'upstream_equals': ['value'], 'request_domain_suffix': ['value'],
        'request_domain_regex': ['value'], 'response_type': ['value'],
        'response_rcode': ['value'], 'response_qclass': ['value'],
        'response_edns_present': ['expect'], 'response_upstream_ip': ['cidr'],
        'response_answer_ip': ['cidr'], 'any': []
    };

    function toJson() {
        return JSON.stringify(config, null, 2);
    }

    function updatePreview() {
        $('#json-preview').val(toJson());
    }

    function syncFromUI() {
        config.settings.bind_udp = $('#cfg-bind-udp').val() || '0.0.0.0:5353';
        config.settings.bind_tcp = $('#cfg-bind-tcp').val() || '0.0.0.0:5353';
        config.settings.default_upstream = $('#cfg-default-upstream').val() || '1.1.1.1:53';
        config.settings.min_ttl = parseInt($('#cfg-min-ttl').val()) || 0;
        config.settings.upstream_timeout_ms = parseInt($('#cfg-timeout').val()) || 2000;
        config.settings.response_jump_limit = parseInt($('#cfg-jump-limit').val()) || 10;
        config.settings.udp_pool_size = parseInt($('#cfg-udp-pool').val()) || 0;
        updatePreview();
    }

    function syncToUI() {
        $('#cfg-bind-udp').val(config.settings.bind_udp);
        $('#cfg-bind-tcp').val(config.settings.bind_tcp);
        $('#cfg-default-upstream').val(config.settings.default_upstream);
        $('#cfg-min-ttl').val(config.settings.min_ttl);
        $('#cfg-timeout').val(config.settings.upstream_timeout_ms);
        $('#cfg-jump-limit').val(config.settings.response_jump_limit);
        $('#cfg-udp-pool').val(config.settings.udp_pool_size);
    }

    function renderMatcherSelect(types, selected) {
        var html = '<select class="form-control input-sm matcher-type" style="width:130px;">';
        $.each(types, function(k, v) {
            html += '<option value="'+k+'"'+(k===selected?' selected':'')+'>'+v+'</option>';
        });
        return html + '</select>';
    }

    function renderMatcherInput(matcher) {
        var type = matcher.type || 'any';
        var fields = MATCHER_FIELDS[type] || [];
        var html = '';
        if (fields.indexOf('value') >= 0) {
            html += '<input type="text" class="form-control input-sm matcher-value" placeholder="Value" value="'+(matcher.value||'')+'" style="width:150px;">';
        }
        if (fields.indexOf('cidr') >= 0) {
            html += '<input type="text" class="form-control input-sm matcher-cidr" placeholder="CIDR" value="'+(matcher.cidr||'')+'" style="width:150px;">';
        }
        if (fields.indexOf('expect') >= 0) {
            html += '<label class="checkbox-inline" style="margin-left:5px;"><input type="checkbox" class="matcher-expect"'+(matcher.expect!==false?' checked':'')+'> Expect</label>';
        }
        return html;
    }

    function renderMatcherRow(matcher, types, idx) {
        return '<div class="input-group input-group-sm matcher-row" style="margin-bottom:3px;" data-idx="'+idx+'">' +
            renderMatcherSelect(types, matcher.type) +
            renderMatcherInput(matcher) +
            '<span class="input-group-btn"><button class="btn btn-danger btn-sm btn-remove-matcher" type="button">&times;</button></span>' +
            '</div>';
    }

    function renderMatcherList(matchers, types, containerId) {
        var html = '';
        (matchers || []).forEach(function(m, i) {
            html += renderMatcherRow(m, types, i);
        });
        html += '<button class="btn btn-link btn-xs btn-add-matcher" type="button"><i class="fa fa-plus"></i> Add</button>';
        $(containerId).html(html);
    }

    function renderActionRow(action, pipelineId, idx) {
        var type = action.type || 'log';
        var html = '<div class="input-group input-group-sm action-row" style="margin-bottom:3px;" data-idx="'+idx+'">';
        html += '<select class="form-control input-sm action-type" style="width:130px;">';
        ['log','static_response','static_ip_response','forward','jump_to_pipeline','allow','deny','continue'].forEach(function(t) {
            html += '<option value="'+t+'"'+(t===type?' selected':'')+'>'+t+'</option>';
        });
        html += '</select>';

        if (type === 'log') {
            html += '<select class="form-control input-sm action-level" style="width:80px;">';
            ['trace','debug','info','warn','error'].forEach(function(l) {
                html += '<option value="'+l+'"'+(l===(action.level||'info')?' selected':'')+'>'+l+'</option>';
            });
            html += '</select>';
        } else if (type === 'static_response') {
            html += '<select class="form-control input-sm action-rcode" style="width:100px;">';
            ['NOERROR','NXDOMAIN','SERVFAIL','REFUSED'].forEach(function(r) {
                html += '<option value="'+r+'"'+(r===action.rcode?' selected':'')+'>'+r+'</option>';
            });
            html += '</select>';
        } else if (type === 'static_ip_response') {
            html += '<input type="text" class="form-control input-sm action-ip" placeholder="IP" value="'+(action.ip||'')+'" style="width:120px;">';
        } else if (type === 'forward') {
            html += '<input type="text" class="form-control input-sm action-upstream" placeholder="Upstream (optional)" value="'+(action.upstream||'')+'" style="width:140px;">';
            html += '<select class="form-control input-sm action-transport" style="width:70px;">';
            html += '<option value=""'+((!action.transport)?' selected':'')+'>Auto</option>';
            html += '<option value="udp"'+((action.transport==='udp')?' selected':'')+'>UDP</option>';
            html += '<option value="tcp"'+((action.transport==='tcp')?' selected':'')+'>TCP</option>';
            html += '</select>';
        } else if (type === 'jump_to_pipeline') {
            html += '<select class="form-control input-sm action-pipeline" style="width:120px;">';
            html += '<option value="">Select...</option>';
            config.pipelines.forEach(function(p) {
                if (p.id !== pipelineId) {
                    html += '<option value="'+p.id+'"'+(p.id===action.pipeline?' selected':'')+'>'+p.id+'</option>';
                }
            });
            html += '</select>';
        }

        html += '<span class="input-group-btn"><button class="btn btn-danger btn-sm btn-remove-action" type="button">&times;</button></span>';
        html += '</div>';
        return html;
    }

    function renderActionList(actions, pipelineId, containerId) {
        var html = '';
        (actions || []).forEach(function(a, i) {
            html += renderActionRow(a, pipelineId, i);
        });
        html += '<button class="btn btn-link btn-xs btn-add-action" type="button"><i class="fa fa-plus"></i> Add</button>';
        $(containerId).html(html);
    }

    function renderSelectors() {
        var container = $('#selectors-container');
        var html = '';
        config.pipeline_select.forEach(function(sel, idx) {
            html += '<div class="matcher-item selector-item" data-idx="'+idx+'">';
            html += '<div class="row"><div class="col-xs-8">';
            html += '<select class="form-control input-sm selector-pipeline">';
            html += '<option value="">Select Pipeline...</option>';
            config.pipelines.forEach(function(p) {
                html += '<option value="'+p.id+'"'+(p.id===sel.pipeline?' selected':'')+'>'+p.id+'</option>';
            });
            html += '</select></div>';
            html += '<div class="col-xs-4 text-right"><button class="btn btn-danger btn-xs btn-remove-selector"><i class="fa fa-trash"></i></button></div></div>';
            html += '<div class="selector-matchers" style="margin-top:8px;"></div>';
            html += '</div>';
        });
        container.html(html);

        // Render matchers for each selector
        config.pipeline_select.forEach(function(sel, idx) {
            renderMatcherList(sel.matchers, SELECTOR_MATCHER_TYPES, '.selector-item[data-idx='+idx+'] .selector-matchers');
        });
    }

    function renderRule(rule, pipelineId, ruleIdx) {
        var hasForward = (rule.actions || []).some(function(a) { return a.type === 'forward'; });
        var html = '<div class="panel panel-default rule-card" data-ridx="'+ruleIdx+'">';
        html += '<div class="panel-heading" style="padding:5px 10px;">';
        html += '<input type="text" class="form-control input-sm rule-name" value="'+(rule.name||'')+'" placeholder="Rule name" style="width:200px;display:inline-block;">';
        html += '<button class="btn btn-danger btn-xs pull-right btn-remove-rule"><i class="fa fa-trash"></i></button>';
        html += '</div><div class="panel-body" style="padding:10px;">';

        html += '<span class="section-badge badge-matcher">Matchers</span>';
        html += '<div class="rule-matchers"></div>';

        html += '<span class="section-badge badge-action" style="margin-top:8px;">Actions</span>';
        html += '<div class="rule-actions"></div>';

        if (hasForward) {
            html += '<span class="section-badge badge-response" style="margin-top:8px;">Response Matchers</span>';
            html += '<div class="rule-resp-matchers"></div>';
            html += '<span class="section-badge" style="background:#007bff;color:white;margin-top:8px;">On Match Actions</span>';
            html += '<div class="rule-resp-match-actions"></div>';
            html += '<span class="section-badge" style="background:#dc3545;color:white;margin-top:8px;">On Miss Actions</span>';
            html += '<div class="rule-resp-miss-actions"></div>';
        }

        html += '</div></div>';
        return html;
    }

    function renderPipeline(pipeline, idx) {
        var html = '<div class="panel panel-info pipeline-panel" data-pidx="'+idx+'">';
        html += '<div class="panel-heading">';
        html += '<input type="text" class="form-control input-sm pipeline-id" value="'+pipeline.id+'" style="width:150px;display:inline-block;font-weight:bold;">';
        html += '<span class="badge" style="margin-left:10px;">'+(pipeline.rules||[]).length+' rules</span>';
        html += '<button class="btn btn-danger btn-xs pull-right btn-remove-pipeline"><i class="fa fa-trash"></i></button>';
        html += '</div><div class="panel-body">';

        (pipeline.rules || []).forEach(function(rule, rIdx) {
            html += renderRule(rule, pipeline.id, rIdx);
        });

        html += '<button class="btn btn-default btn-sm btn-add-rule" style="width:100%;"><i class="fa fa-plus"></i> Add Rule</button>';
        html += '</div></div>';
        return html;
    }

    function renderPipelines() {
        var container = $('#pipelines-container');
        var html = '';
        config.pipelines.forEach(function(p, idx) {
            html += renderPipeline(p, idx);
        });
        container.html(html);

        // Render nested lists
        config.pipelines.forEach(function(p, pIdx) {
            (p.rules || []).forEach(function(r, rIdx) {
                var ruleEl = '.pipeline-panel[data-pidx='+pIdx+'] .rule-card[data-ridx='+rIdx+']';
                renderMatcherList(r.matchers, REQUEST_MATCHER_TYPES, ruleEl + ' .rule-matchers');
                renderActionList(r.actions, p.id, ruleEl + ' .rule-actions');
                if ((r.actions || []).some(function(a) { return a.type === 'forward'; })) {
                    renderMatcherList(r.response_matchers, RESPONSE_MATCHER_TYPES, ruleEl + ' .rule-resp-matchers');
                    renderActionList(r.response_actions_on_match, p.id, ruleEl + ' .rule-resp-match-actions');
                    renderActionList(r.response_actions_on_miss, p.id, ruleEl + ' .rule-resp-miss-actions');
                }
            });
        });
    }

    function render() {
        syncToUI();
        renderSelectors();
        renderPipelines();
        updatePreview();
    }

    function collectMatchersFromContainer(container) {
        var matchers = [];
        container.find('.matcher-row').each(function() {
            var row = $(this);
            var m = { type: row.find('.matcher-type').val() };
            if (row.find('.matcher-value').length) m.value = row.find('.matcher-value').val();
            if (row.find('.matcher-cidr').length) m.cidr = row.find('.matcher-cidr').val();
            if (row.find('.matcher-expect').length) m.expect = row.find('.matcher-expect').is(':checked');
            matchers.push(m);
        });
        return matchers;
    }

    function collectActionsFromContainer(container) {
        var actions = [];
        container.find('.action-row').each(function() {
            var row = $(this);
            var a = { type: row.find('.action-type').val() };
            if (a.type === 'log') a.level = row.find('.action-level').val();
            if (a.type === 'static_response') a.rcode = row.find('.action-rcode').val();
            if (a.type === 'static_ip_response') a.ip = row.find('.action-ip').val();
            if (a.type === 'forward') {
                var up = row.find('.action-upstream').val();
                if (up) a.upstream = up; else a.upstream = null;
                var tr = row.find('.action-transport').val();
                if (tr) a.transport = tr; else a.transport = null;
            }
            if (a.type === 'jump_to_pipeline') a.pipeline = row.find('.action-pipeline').val();
            actions.push(a);
        });
        return actions;
    }

    function collectFromUI() {
        syncFromUI();

        // Collect selectors
        config.pipeline_select = [];
        $('.selector-item').each(function() {
            var item = $(this);
            config.pipeline_select.push({
                pipeline: item.find('.selector-pipeline').val(),
                matchers: collectMatchersFromContainer(item.find('.selector-matchers'))
            });
        });

        // Collect pipelines
        config.pipelines = [];
        $('.pipeline-panel').each(function() {
            var panel = $(this);
            var pipeline = {
                id: panel.find('.pipeline-id').val(),
                rules: []
            };
            panel.find('.rule-card').each(function() {
                var ruleEl = $(this);
                var rule = {
                    name: ruleEl.find('.rule-name').val(),
                    matchers: collectMatchersFromContainer(ruleEl.find('.rule-matchers')),
                    actions: collectActionsFromContainer(ruleEl.find('.rule-actions')),
                    response_matchers: collectMatchersFromContainer(ruleEl.find('.rule-resp-matchers')),
                    response_actions_on_match: collectActionsFromContainer(ruleEl.find('.rule-resp-match-actions')),
                    response_actions_on_miss: collectActionsFromContainer(ruleEl.find('.rule-resp-miss-actions'))
                };
                pipeline.rules.push(rule);
            });
            config.pipelines.push(pipeline);
        });

        updatePreview();
    }

    function load() {
        $.ajax({
            url: '/api/kixdns/settings/getConfigJson',
            method: 'GET',
            dataType: 'json',
            success: function(data) {
                console.log('Load response:', data);
                if (data.config_json && data.config_json.trim() !== '') {
                    try {
                        var parsed = JSON.parse(data.config_json);
                        // Merge with defaults
                        config.version = parsed.version || "1.0";
                        config.settings = $.extend({}, config.settings, parsed.settings || {});
                        config.pipeline_select = parsed.pipeline_select || [];
                        config.pipelines = parsed.pipelines || [];
                        console.log('Loaded config:', config);
                    } catch(e) {
                        console.error('Parse error:', e);
                    }
                } else {
                    console.log('No saved config, using defaults');
                }
                render();
            },
            error: function(xhr, status, err) {
                console.error('Load error:', err);
                render();
            }
        });
    }

    function save(callback) {
        // Use the JSON from the preview textarea directly
        var jsonData = $('#json-preview').val();
        
        // Validate JSON before saving
        try {
            var parsed = JSON.parse(jsonData);
            // Update internal config object
            config = parsed;
            console.log('Saving config:', jsonData.substring(0, 200) + '...');
        } catch(e) {
            alert('Invalid JSON: ' + e.message);
            return;
        }
        
        $.ajax({
            url: '/api/kixdns/settings/saveConfigJson',
            method: 'POST',
            data: { config_json: jsonData },
            dataType: 'json',
            success: function(data) {
                console.log('Save response:', data);
                if (data.result === 'saved') {
                    BootstrapDialog.show({
                        type: BootstrapDialog.TYPE_SUCCESS,
                        title: 'Success',
                        message: 'Configuration saved.',
                        buttons: [{ label: 'Close', action: function(d) { d.close(); } }]
                    });
                    if (callback) callback();
                } else {
                    alert('Save failed: ' + (data.message || 'Unknown error'));
                }
            },
            error: function(xhr, status, err) {
                console.error('Save error:', xhr.responseText);
                alert('Save error: ' + err);
            }
        });
    }

    function apply() {
        save(function() {
            // NOTE: reconfigure lives on the *service* controller. The old
            // /api/kixdns/settings/reconfigure endpoint does not exist and
            // returned an error page instead of applying anything.
            $.ajax({
                url: '/api/kixdns/service/reconfigure',
                method: 'POST',
                dataType: 'json',
                success: function(data) {
                    if (typeof updateServiceControlUI === 'function') {
                        updateServiceControlUI('kixdns');
                    }
                    if (data && data.status === 'ok') {
                        BootstrapDialog.show({
                            type: BootstrapDialog.TYPE_SUCCESS,
                            title: 'Success',
                            message: 'Configuration saved and applied.',
                            buttons: [{ label: 'Close', action: function(d) { d.close(); } }]
                        });
                    } else {
                        BootstrapDialog.show({
                            type: BootstrapDialog.TYPE_DANGER,
                            title: 'Error',
                            message: 'Saved, but applying failed. Check Services - KixDNS - Log File.',
                            buttons: [{ label: 'Close', action: function(d) { d.close(); } }]
                        });
                    }
                },
                error: function(xhr) {
                    BootstrapDialog.show({
                        type: BootstrapDialog.TYPE_DANGER,
                        title: 'Error',
                        message: 'Apply request failed (HTTP ' + xhr.status + ').',
                        buttons: [{ label: 'Close', action: function(d) { d.close(); } }]
                    });
                }
            });
        });
    }

    // Event bindings
    $(document).on('change input', '#cfg-bind-udp,#cfg-bind-tcp,#cfg-default-upstream,#cfg-min-ttl,#cfg-timeout,#cfg-jump-limit,#cfg-udp-pool', function() {
        collectFromUI();
    });

    $(document).on('change', '.selector-pipeline,.pipeline-id,.rule-name,.matcher-type,.matcher-value,.matcher-cidr,.matcher-expect,.action-type,.action-level,.action-rcode,.action-ip,.action-upstream,.action-transport,.action-pipeline', function() {
        collectFromUI();
        // Re-render if action type changed to/from forward
        if ($(this).hasClass('action-type')) {
            render();
        }
    });

    $(document).on('click', '#btn-add-selector', function() {
        config.pipeline_select.push({ pipeline: '', matchers: [] });
        render();
    });

    $(document).on('click', '.btn-remove-selector', function() {
        var idx = $(this).closest('.selector-item').data('idx');
        config.pipeline_select.splice(idx, 1);
        render();
    });

    $(document).on('click', '#btn-add-pipeline', function() {
        var id = 'pipeline_' + (config.pipelines.length + 1);
        config.pipelines.push({ id: id, rules: [] });
        render();
    });

    $(document).on('click', '.btn-remove-pipeline', function() {
        var idx = $(this).closest('.pipeline-panel').data('pidx');
        config.pipelines.splice(idx, 1);
        render();
    });

    $(document).on('click', '.btn-add-rule', function() {
        var idx = $(this).closest('.pipeline-panel').data('pidx');
        var ruleNum = (config.pipelines[idx].rules || []).length + 1;
        config.pipelines[idx].rules.push({ name: 'rule_' + ruleNum, matchers: [], actions: [] });
        render();
    });

    $(document).on('click', '.btn-remove-rule', function() {
        collectFromUI();
        var panel = $(this).closest('.pipeline-panel');
        var pIdx = panel.data('pidx');
        var rIdx = $(this).closest('.rule-card').data('ridx');
        config.pipelines[pIdx].rules.splice(rIdx, 1);
        render();
    });

    $(document).on('click', '.btn-add-matcher', function() {
        collectFromUI();
        var container = $(this).closest('.selector-matchers,.rule-matchers,.rule-resp-matchers');
        var firstType = Object.keys(container.closest('.selector-matchers').length ? SELECTOR_MATCHER_TYPES : 
            (container.closest('.rule-resp-matchers').length ? RESPONSE_MATCHER_TYPES : REQUEST_MATCHER_TYPES))[0];
        
        // Find which array to push to
        if (container.hasClass('selector-matchers')) {
            var sIdx = container.closest('.selector-item').data('idx');
            config.pipeline_select[sIdx].matchers.push({ type: firstType });
        } else {
            var pIdx = container.closest('.pipeline-panel').data('pidx');
            var rIdx = container.closest('.rule-card').data('ridx');
            if (container.hasClass('rule-matchers')) {
                config.pipelines[pIdx].rules[rIdx].matchers.push({ type: firstType });
            } else {
                if (!config.pipelines[pIdx].rules[rIdx].response_matchers) config.pipelines[pIdx].rules[rIdx].response_matchers = [];
                config.pipelines[pIdx].rules[rIdx].response_matchers.push({ type: firstType });
            }
        }
        render();
    });

    $(document).on('click', '.btn-remove-matcher', function() {
        collectFromUI();
        var row = $(this).closest('.matcher-row');
        var mIdx = row.data('idx');
        var container = row.closest('.selector-matchers,.rule-matchers,.rule-resp-matchers');
        
        if (container.hasClass('selector-matchers')) {
            var sIdx = container.closest('.selector-item').data('idx');
            config.pipeline_select[sIdx].matchers.splice(mIdx, 1);
        } else {
            var pIdx = container.closest('.pipeline-panel').data('pidx');
            var rIdx = container.closest('.rule-card').data('ridx');
            if (container.hasClass('rule-matchers')) {
                config.pipelines[pIdx].rules[rIdx].matchers.splice(mIdx, 1);
            } else {
                config.pipelines[pIdx].rules[rIdx].response_matchers.splice(mIdx, 1);
            }
        }
        render();
    });

    $(document).on('click', '.btn-add-action', function() {
        collectFromUI();
        var container = $(this).closest('.rule-actions,.rule-resp-match-actions,.rule-resp-miss-actions');
        var pIdx = container.closest('.pipeline-panel').data('pidx');
        var rIdx = container.closest('.rule-card').data('ridx');
        
        if (container.hasClass('rule-actions')) {
            config.pipelines[pIdx].rules[rIdx].actions.push({ type: 'log', level: 'info' });
        } else if (container.hasClass('rule-resp-match-actions')) {
            if (!config.pipelines[pIdx].rules[rIdx].response_actions_on_match) config.pipelines[pIdx].rules[rIdx].response_actions_on_match = [];
            config.pipelines[pIdx].rules[rIdx].response_actions_on_match.push({ type: 'log', level: 'info' });
        } else {
            if (!config.pipelines[pIdx].rules[rIdx].response_actions_on_miss) config.pipelines[pIdx].rules[rIdx].response_actions_on_miss = [];
            config.pipelines[pIdx].rules[rIdx].response_actions_on_miss.push({ type: 'log', level: 'info' });
        }
        render();
    });

    $(document).on('click', '.btn-remove-action', function() {
        collectFromUI();
        var row = $(this).closest('.action-row');
        var aIdx = row.data('idx');
        var container = row.closest('.rule-actions,.rule-resp-match-actions,.rule-resp-miss-actions');
        var pIdx = container.closest('.pipeline-panel').data('pidx');
        var rIdx = container.closest('.rule-card').data('ridx');
        
        if (container.hasClass('rule-actions')) {
            config.pipelines[pIdx].rules[rIdx].actions.splice(aIdx, 1);
        } else if (container.hasClass('rule-resp-match-actions')) {
            config.pipelines[pIdx].rules[rIdx].response_actions_on_match.splice(aIdx, 1);
        } else {
            config.pipelines[pIdx].rules[rIdx].response_actions_on_miss.splice(aIdx, 1);
        }
        render();
    });

    $('#btn-load-json').click(function() {
        try {
            config = JSON.parse($('#json-preview').val());
            render();
        } catch(e) {
            alert('Invalid JSON: ' + e.message);
        }
    });

    $('#btn-export-json').click(function() {
        collectFromUI();
        var blob = new Blob([toJson()], { type: 'application/json' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = 'pipeline.json';
        a.click();
    });

    $('#file-import').change(function(e) {
        var file = e.target.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function(e) {
            try {
                config = JSON.parse(e.target.result);
                render();
            } catch(err) {
                alert('Invalid JSON file');
            }
        };
        reader.readAsText(file);
    });

    $('#btn-save-config').click(function() {
        save();
    });

    $('#btn-apply-config').click(apply);

    return { load: load, save: save, apply: apply };
})(jQuery);
</script>


<script>
/* ---------- AdGuardHome-style dashboard + query log ---------- */
(function ($) {
    var trendChart = null;
    var dashTimer = null;
    var logTimer = null;

    function num(n) {
        if (n === undefined || n === null || n === '') return '\u2013';
        return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    function esc(s) {
        return String(s === undefined || s === null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function api(path, params, done) {
        var qs = $.param(params || {});
        $.getJSON('/api/kixdns/stats/' + path + (qs ? '?' + qs : ''))
            .done(function (data) { done(null, data); })
            .fail(function (xhr) { done(xhr, null); });
    }

    function ensureChart(cb) {
        if (typeof Chart !== 'undefined') { cb(); return; }
        var s = document.createElement('script');
        s.src = '/ui/js/chart.umd.min.js';
        s.onload = cb;
        s.onerror = cb;
        document.head.appendChild(s);
    }

    // rows: [{name,count}]; bar width is relative to the largest entry,
    // the numeric share (when shown) is relative to the query total
    function renderRows(tbody, rows, total) {
        var $b = $(tbody).empty();
        if (!rows || !rows.length) {
            $b.append('<tr><td colspan="3" class="agh-muted">no data</td></tr>');
            return;
        }
        var max = rows[0].count || 1;
        rows.forEach(function (r) {
            var w = max > 0 ? Math.round(100 * r.count / max) : 0;
            var share = total > 0 ? (Math.round(1000 * r.count / total) / 10) : 0;
            $b.append(
                '<tr><td class="k-domain">' + esc(r.name) + '</td>' +
                '<td class="num">' + num(r.count) + '</td>' +
                '<td><span class="agh-bar" style="width:' + Math.max(2, w) + 'px"></span>' +
                ' <span class="agh-muted">' + share + '%</span></td></tr>'
            );
        });
    }

    // obj: {key: count}; share is relative to the query total
    function renderSimple(tbody, obj, limit, total) {
        var $b = $(tbody).empty();
        var keys = Object.keys(obj || {});
        if (!keys.length) { $b.append('<tr><td colspan="3" class="agh-muted">no data</td></tr>'); return; }
        var max = 0;
        keys.forEach(function (k) { if (obj[k] > max) max = obj[k]; });
        keys.slice(0, limit || 10).forEach(function (k) {
            var w = max > 0 ? Math.round(100 * obj[k] / max) : 0;
            var share = total > 0 ? (Math.round(1000 * obj[k] / total) / 10) : 0;
            $b.append(
                '<tr><td>' + esc(k) + '</td><td class="num">' + num(obj[k]) + '</td>' +
                '<td><span class="agh-bar" style="width:' + Math.max(2, w) + 'px"></span>' +
                ' <span class="agh-muted">' + share + '%</span></td></tr>'
            );
        });
    }

    function loadService() {
        api('service', {}, function (err, d) {
            if (err || !d) return;
            $('#k-dot').attr('class', 'agh-dot ' + (d.running ? 'on' : 'off'));
            $('#k-state').text(d.running ? '运行中 running' : '已停止 stopped');
            $('#k-version').text(d.version || '');
            $('#k-bind').text(d.bind || '');
            $('#k-debug').text(d.debug ? 'debug on' : 'debug off');
            var mode = d.mode === 'direct' ? ('直接监听 ' + d.bind)
                     : (d.mode === 'redirect' ? ('iptables 重定向 (' + d.iptables_rules + ' 条)') : '未接管');
            $('#k-mode').text(mode);
        });
    }

    function drawTrend(hourly) {
        var labels = [], data = [];
        Object.keys(hourly).sort().forEach(function (h) {
            labels.push(h + ':00');
            data.push(hourly[h]);
        });
        ensureChart(function () {
            if (typeof Chart === 'undefined') return;
            var ctx = document.getElementById('k-chart-trend');
            if (!ctx) return;
            if (trendChart) {
                trendChart.data.labels = labels;
                trendChart.data.datasets[0].data = data;
                trendChart.update();
                return;
            }
            trendChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'queries',
                        data: data,
                        backgroundColor: 'rgba(72,187,120,0.65)',
                        borderColor: '#48bb78',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: { display: false },
                    scales: {
                        yAxes: [{ ticks: { beginAtZero: true, precision: 0 } }],
                        xAxes: [{ gridLines: { display: false } }]
                    }
                }
            });
        });
    }

    function loadOverview() {
        api('overview', {}, function (err, d) {
            if (err || !d) return;
            if (d.observer) {
                // native observer data (kixdns >= 0.2.0 with --debug)
                $('#k-scope').text('原生观测 observer');
                $('#k-c1-label').text('客户端请求 Client queries');
                $('#k-total').text(num(d.requests));
                var tsub = num(d.cache_hits) + ' 命中 / ' + num(d.cache_misses) + ' 回源';
                if (d.caps && d.caps.inflight) {
                    tsub += ' / ' + num(d.inflight_joined) + ' 合并';
                }
                $('#k-total-sub').text(tsub);
                $('#k-c2-label').text('缓存命中率 Cache hit ratio');
                $('#k-domains').text(d.cache_hit_ratio === null ? '\u2013' : d.cache_hit_ratio + '%');
                var csub = num(d.unique_domains) + ' 个唯一域名';
                var srcs = d.cache_sources || [];
                if (d.caps && d.caps.cache_source && srcs.length) {
                    csub += ' · 缓存来源 ' + srcs[0].name + (srcs.length > 1 ? ' 等 ' + srcs.length + ' 个上游' : '');
                }
                $('#k-domains-sub').text(csub);
                $('#k-c3-label').text('端到端延迟 End-to-end');
                $('#k-latency').text(d.e2e_avg_latency === null ? '\u2013' : d.e2e_avg_latency);
                var lsub = 'ms（含缓存命中；上游 ' + (d.upstream_avg_latency === null ? '-' : d.upstream_avg_latency) + ' ms）';
                if (d.caps && d.caps.response_bytes && d.avg_response_bytes !== null) {
                    lsub += ' · 应答均 ' + d.avg_response_bytes + ' B';
                }
                $('#k-latency-sub').text(lsub);
                $('#k-c4-label').text('慢查询 Slow >500ms');
                $('#k-slow').text(num(d.e2e_slow));
                $('#k-slow-sub').text(d.requests > 0 ? (Math.round(1000 * d.e2e_slow / d.requests) / 10) + '% of requests' : '');
            } else {
                // info-level fallback: forwarded (upstream) responses only
                $('#k-scope').text('日志口径 forwarded（开启 Debug 可得原生观测）');
                $('#k-c1-label').text('转发查询 Forwarded queries');
                $('#k-total').text(num(d.forwarded_total));
                $('#k-total-sub').text('回源转发（缓存命中不在此口径内）');
                $('#k-c2-label').text('唯一域名 Unique domains');
                $('#k-domains').text(num(d.unique_domains));
                $('#k-domains-sub').text(num(d.cache_hits) + ' 条响应被缓存');
                $('#k-c3-label').text('回源延迟 Upstream latency');
                $('#k-latency').text(d.avg_latency);
                $('#k-latency-sub').text('ms（仅回源请求）');
                $('#k-c4-label').text('慢查询 Slow >500ms');
                $('#k-slow').text(num(d.slow_queries));
                $('#k-slow-sub').text(d.forwarded_total > 0 ? (Math.round(1000 * d.slow_queries / d.forwarded_total) / 10) + '% of forwarded' : '');
            }
            $('#k-generated').text('更新 ' + (d.generated || '').replace('T', ' ').substring(0, 19));
            $('#k-logfile').text(d.log_file || '');
            drawTrend(d.hourly || {});
            var scopeTotal = d.observer ? d.requests : d.forwarded_total;
            renderRows('#k-top-domains', d.top_domains, scopeTotal);
            renderRows('#k-top-clients', d.top_clients, scopeTotal);
            renderRows('#k-upstreams', d.upstreams, scopeTotal);
            renderSimple('#k-rcodes', d.rcode, 8, scopeTotal);
        });
    }

    function rcodePill(rcode) {
        var r = (rcode || '').toLowerCase();
        if (r === 'noerror') return 'ok';
        if (r === 'nxdomain') return 'dim';
        if (r === 'servfail' || r === 'refused') return 'err';
        return 'warn';
    }

    function loadLog() {
        var params = {
            limit: $('#k-q-limit').val() || 200,
            qname: $('#k-q-domain').val() || '',
            client: $('#k-q-client').val() || ''
        };
        api('querylog', params, function (err, d) {
            var $b = $('#k-q-body');
            if (err || !d) {
                $b.html('<tr><td colspan="8" class="agh-muted">加载失败 load failed</td></tr>');
                return;
            }
            var rows = d.rows || [];
            $('#k-q-meta').text(rows.length + ' 条记录 · ' + (d.log_file || ''));
            if (!rows.length) {
                $b.html('<tr><td colspan="8" class="agh-muted">无记录 no records</td></tr>');
                return;
            }
            var html = '';
            rows.forEach(function (r) {
                var cached = r.cache === 'true';
                html += '<tr>' +
                    '<td class="agh-muted">' + esc(r.time) + '</td>' +
                    '<td>' + esc(r.client_ip) + '</td>' +
                    '<td class="k-domain" title="' + esc(r.qname) + '">' + esc(r.qname) + '</td>' +
                    '<td>' + esc(r.qtype) + '</td>' +
                    '<td><span class="agh-pill ' + rcodePill(r.rcode) + '">' + esc(r.rcode) + '</span></td>' +
                    '<td class="agh-muted">' + esc(r.upstream) + '</td>' +
                    '<td class="num">' + esc(r.latency_ms) + ' ms</td>' +
                    '<td>' + (cached ? '<span class="agh-pill cache">cache</span>' : '<span class="agh-pill dim">upstream</span>') + '</td>' +
                    '</tr>';
            });
            $b.html(html);
        });
    }

    function startDashAuto() {
        stopDashAuto();
        if ($('#k-autorefresh').is(':checked')) {
            dashTimer = setInterval(function () {
                loadService();
                loadOverview();
            }, 5000);
        }
    }

    function stopDashAuto() {
        if (dashTimer) { clearInterval(dashTimer); dashTimer = null; }
    }

    function startLogAuto() {
        if (logTimer) { clearInterval(logTimer); logTimer = null; }
        var ms = parseInt($('#k-q-auto').val(), 10);
        if (ms > 0) logTimer = setInterval(loadLog, ms);
    }

    $(document).ready(function () {
        loadService();
        loadOverview();
        startDashAuto();
        startLogAuto();

        $('#k-refresh').click(function (e) { e.preventDefault(); loadService(); loadOverview(); });
        $('#k-autorefresh').change(startDashAuto);
        $('#k-q-refresh').click(loadLog);
        $('#k-q-auto').change(startLogAuto);
        $('#k-q-limit').change(loadLog);
        $('#k-q-domain').on('keyup', function (e) { if (e.which === 13) loadLog(); });
        $('#k-q-client').on('keyup', function (e) { if (e.which === 13) loadLog(); });

        // load the query log lazily the first time its tab is opened
        $('a[href="#querylog"]').on('shown.bs.tab', function () { loadLog(); });
        $('a[href="#dashboard"]').on('shown.bs.tab', function () {
            if (trendChart) { trendChart.resize(); }
        });
    });
})(jQuery);
</script>
