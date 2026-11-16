<?php

/*
 * Application actions built on top of the DNS x DPI fusion data:
 *
 *   list   GET  — the app catalogue with what each app looks like from both
 *                 engines (bytes, flows, DNS queries, known domain suffixes) and
 *                 whether it is currently blocked at the DNS layer.
 *   block  POST — preview or apply a kixdns pipeline rule that answers NXDOMAIN
 *                 for every domain suffix a given app uses. This is the one
 *                 capability that only exists *because* the two data sets were
 *                 joined: kixdns alone has no idea which names belong to which
 *                 application.
 *   feed   POST — render the fusion findings back into appdpi's own rule format
 *                 (`rule domain suffix <x> app <id>`), so the engine's domain
 *                 table stops being capped by --top.
 *
 * Everything here is opt-in: nothing is written until the operator POSTs, and
 * block apply keeps a copy of the previous pipeline JSON in /tmp so a mistake is
 * one restore away.
 */

namespace OPNsense\KixDNS\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\KixDNS\ConfigValidator;
use OPNsense\KixDNS\KixDNS;

class AppsController extends ApiControllerBase
{
    private const RULEPACK   = '/usr/local/share/appdpi/panabit.rulepack';
    private const IDX_CACHE  = '/tmp/kixdns_dpi_index.json';
    private const DPI_STATUS = '/var/run/appdpi/status.json';
    private const PIPELINE   = '/usr/local/etc/kixdns/pipeline.json';
    private const BLOCK_PREFIX = 'APP封堵: ';
    private const BACKUP     = '/tmp/kixdns_pipeline_before_block.json';
    private const FEED_DIR   = '/var/run/appdpi';
    private const MAX_SUFFIX = 240;    // suffixes per app in one pipeline rule

    /* ------------------------------------------------------------------ */
    /* GET /api/kixdns/apps/list                                           */
    /* ------------------------------------------------------------------ */
    public function listAction()
    {
        $idx = $this->index();
        $dpi = $this->dpiSnapshot();
        $blocked = $this->blockedApps();
        $suffixes = $this->suffixesByApp($idx);

        $rows = [];
        foreach ($dpi['apps'] as $id => $rec) {
            $rows[] = $this->row($id, $rec['name'], $idx, $suffixes, $blocked, [
                'bytes' => $rec['bytes'],
                'flows' => $rec['flows'],
            ]);
        }
        // apps that only the rulepack knows about still matter for blocking
        foreach ($suffixes as $id => $list) {
            if (isset($dpi['apps'][$id])) {
                continue;
            }
            $rows[] = $this->row($id, $idx['apps'][$id] ?? ('#' . $id), $idx, $suffixes, $blocked, [
                'bytes' => 0, 'flows' => 0,
            ]);
        }
        usort($rows, function ($a, $b) {
            return ($b['bytes'] <=> $a['bytes']) ?: (count($b['suffixes']) <=> count($a['suffixes']));
        });
        $rows = array_slice($rows, 0, 60);

        return [
            'apps' => $rows,
            'blocked_count' => count($blocked),
            'pipeline_source' => $this->pipelineSource(),
            'backend' => (new Backend())->configdRun('kixdns status') !== '',
        ];
    }

    private function row(int $id, string $name, array $idx, array $suffixes, array $blocked, array $extra): array
    {
        $list = $suffixes[$id] ?? [];
        return [
            'app_id' => $id,
            'name' => $name !== '' ? $name : $this->appNameFromIndex($id, $idx),
            'bytes' => $extra['bytes'],
            'flows' => $extra['flows'],
            'suffixes' => array_slice($list, 0, 8),
            'suffix_count' => count($list),
            'blocked' => in_array($id, $blocked, true),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* POST /api/kixdns/apps/block   apps=805,2131  mode=preview|apply      */
    /* ------------------------------------------------------------------ */
    public function blockAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $raw = (string)$this->request->getPost('apps', 'string', '');
        $mode = (string)$this->request->getPost('mode', 'string', 'preview');
        $rcode = strtoupper((string)$this->request->getPost('rcode', 'string', 'NXDOMAIN'));
        if (!in_array($rcode, ['NXDOMAIN', 'NOERROR', 'REFUSED'], true)) {
            $rcode = 'NXDOMAIN';
        }
        $ids = [];
        foreach (explode(',', $raw) as $p) {
            $p = trim($p);
            if ($p !== '' && ctype_digit($p) && (int)$p > 0) {
                $ids[] = (int)$p;
            }
        }
        if (!$ids) {
            return ['result' => 'failed', 'message' => 'no app ids given'];
        }

        $idx = $this->index();
        $suffixes = $this->suffixesByApp($idx);
        $doc = $this->pipelineDoc();
        if ($doc === null) {
            return ['result' => 'failed', 'message' => 'cannot read the current pipeline JSON'];
        }

        // drop previous generated rules so repeated applies stay idempotent
        $before = 0;
        foreach ($doc['pipelines'] as &$pl) {
            if (!isset($pl['rules']) || !is_array($pl['rules'])) {
                continue;
            }
            $keep = [];
            foreach ($pl['rules'] as $r) {
                if (isset($r['name']) && strpos((string)$r['name'], self::BLOCK_PREFIX) === 0) {
                    $before++;
                    continue;
                }
                $keep[] = $r;
            }
            $pl['rules'] = $keep;
        }
        unset($pl);

        $made = [];
        foreach ($ids as $id) {
            $list = array_slice($suffixes[$id] ?? [], 0, self::MAX_SUFFIX);
            if (!$list) {
                continue;
            }
            $matchers = [];
            foreach ($list as $suf) {
                $matchers[] = ['type' => 'domain_suffix', 'operator' => 'or', 'value' => $suf];
            }
            $made[] = [
                'name' => self::BLOCK_PREFIX . $this->appNameFromIndex($id, $idx) . ' (#' . $id . ')',
                'matchers' => $matchers,
                'matcher_operator' => 'or',
                'actions' => [['type' => 'static_response', 'rcode' => $rcode]],
                'response_matchers' => [],
                'response_actions_on_match' => [],
                'response_actions_on_miss' => [],
            ];
        }
        if (!$made) {
            return ['result' => 'failed', 'message' => 'none of the selected apps has a known domain suffix'];
        }

        // rules must sit in front of the forwarding rules of the first pipeline
        $target = null;
        foreach ($doc['pipelines'] as $i => $pl) {
            if (($pl['id'] ?? '') === '过滤响应') {
                $target = $i;
                break;
            }
        }
        if ($target === null) {
            $target = 0;
        }
        $doc['pipelines'][$target]['rules'] = array_merge($made, $doc['pipelines'][$target]['rules'] ?? []);

        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return ['result' => 'failed', 'message' => 'cannot encode pipeline JSON'];
        }
        $errors = ConfigValidator::validate($json);
        if ($errors) {
            return ['result' => 'failed', 'message' => implode(' ', $errors)];
        }

        $preview = [
            'rules' => $made,
            'rule_count' => count($made),
            'suffix_count' => array_sum(array_map(function ($r) {
                return count($r['matchers']);
            }, $made)),
            'replaced' => $before,
            'pipeline' => $doc['pipelines'][$target]['id'] ?? '(first)',
            'rcode' => $rcode,
            'json' => $json,
        ];

        if ($mode !== 'apply') {
            return ['result' => 'preview'] + $preview;
        }

        // ---- apply: previous pipeline kept next to the config, then reload ----
        $prev = (string)(new KixDNS())->general->config_json;
        if ($prev !== '') {
            @file_put_contents(self::BACKUP, $prev);
        }
        try {
            Config::getInstance()->lock();
            $mdl = new KixDNS();
            $mdl->general->config_json = $json;
            $validation = $mdl->performValidation();
            if ($validation->count() > 0) {
                $msgs = [];
                foreach ($validation as $msg) {
                    $msgs[] = $msg->getField() . ': ' . $msg->getMessage();
                }
                return ['result' => 'failed', 'message' => implode(' ', $msgs)];
            }
            $mdl->serializeToConfig(false, true);
            Config::getInstance()->save();
        } catch (\Throwable $e) {
            return ['result' => 'failed', 'message' => $e->getMessage()];
        }

        $backend = new Backend();
        $backend->configdRun('template reload OPNsense/KixDNS');
        $backend->configdRun('kixdns reload');

        return ['result' => 'applied', 'backup' => is_readable(self::BACKUP) ? self::BACKUP : ''] + $preview;
    }

    /* ------------------------------------------------------------------ */
    /* POST /api/kixdns/apps/feed                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Render the fusion state back into appdpi's own rule format.
     *
     * Two artifacts, both deliberately zero-risk:
     *
     *   kixdns-feed.rules        exact records for every name appdpi itself
     *                            attributed (`rule domain exact <name> app <id>`)
     *   panabit+kixdns.rulepack  the shipped rulepack with those same records
     *                            appended to [rules]
     *   kixdns-unmatched.csv     names nobody could attribute, with query counts,
     *                            for a human to write rules for
     *
     * Suffix inference is NOT done on purpose. The obvious heuristic — "this
     * unknown name shares a registrable domain with an attributed one" — looks
     * fine until it fires on shared hosting: github.com came back as the proxy
     * app, hihonorcloud.com as 小红书, because those hosts were reached through a
     * proxy or a probe. Turning that into a suffix rule would silently move real
     * traffic into the wrong app, so only appdpi's own attributions are frozen,
     * and everything else is reported for a human decision.
     */
    public function feedAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        if (!is_readable(self::RULEPACK)) {
            return ['result' => 'failed', 'message' => 'rulepack not found: ' . self::RULEPACK];
        }
        $idx = $this->index();
        $dpi = $this->dpiSnapshot();
        if (!$idx['suffix']) {
            return ['result' => 'failed', 'message' => 'rulepack index is empty'];
        }

        // 1. domains the fusion page cannot attribute, from the same scan cache
        $scan = $this->scanDomains();
        $orphans = [];
        foreach ($scan as $dom => $n) {
            if ($this->lookup($dom, $idx) > 0) {
                continue;
            }
            if (isset($dpi['domains'][$dom])) {
                continue;   // appdpi already had its say
            }
            $orphans[$dom] = $n;
        }
        arsort($orphans);

        // 2. exact records: the engine's own verdicts, frozen
        $exact = [];
        foreach ($dpi['domains'] as $dom => $rec) {
            $id = (int)$rec['app_id'];
            if ($id > 0 && strpos((string)$dom, '.') !== false) {
                $exact[(string)$dom] = $id;
            }
        }

        // 3. render
        if (!is_dir(self::FEED_DIR)) {
            @mkdir(self::FEED_DIR, 0750, true);
        }
        $csv = "# generated by os-kixdns-community fusion feed — do not edit\n";
        $csv .= "# appdpi refuses to load --rules together with --rulepack, so use\n";
        $csv .= "# panabit+kixdns.rulepack (see below) or merge this file by hand.\n";
        $seenApp = [];
        foreach ($exact as $dom => $id) {
            if (!isset($seenApp[$id])) {
                $seenApp[$id] = true;
                $csv .= sprintf(
                    "app %d %s\n",
                    $id,
                    preg_replace('/\s+/', '_', $this->appNameFromIndex($id, $idx))
                );
            }
            $csv .= sprintf(
                "rule domain exact %s app %d prio 800 note kixdns-feed\n",
                $dom,
                $id
            );
        }
        $csvPath = self::FEED_DIR . '/kixdns-feed.rules';
        @file_put_contents($csvPath, $csv);

        // unmatched list for a human
        $umPath = self::FEED_DIR . '/kixdns-unmatched.csv';
        $um = "# qname,queries — DNS 侧观察到、两个引擎都无法归属的域名\n";
        $umShown = 0;
        foreach ($orphans as $dom => $n) {
            $um .= $dom . ',' . $n . "\n";
            if (++$umShown >= 2000) {
                break;
            }
        }
        @file_put_contents($umPath, $um);

        // merged rulepack: append the exact records to the [rules] section
        $mergedPath = self::FEED_DIR . '/panabit+kixdns.rulepack';
        $mergedLines = 0;
        $src = @fopen(self::RULEPACK, 'r');
        $dst = @fopen($mergedPath . '.tmp', 'w');
        if ($src !== false && $dst !== false) {
            $inRules = false;
            $pending = [];
            foreach ($exact as $dom => $id) {
                $pending[] = sprintf(
                    "R9%05d\tdomain\texact\t%s\t%d\tkixdns-feed\thostkey_data\t0\t40\t0x0\tactive\tfrozen from appdpi observation\t\t%s\n",
                    count($pending),
                    $dom,
                    $id,
                    preg_replace('/[\t\r\n]/', ' ', $this->appNameFromIndex($id, $idx))
                );
            }
            $flushed = false;
            while (($line = fgets($src)) !== false) {
                if (!$inRules && preg_match('/^\[rules\]\s*$/', $line)) {
                    $inRules = true;
                    fwrite($dst, $line);
                    continue;
                }
                if ($inRules && !$flushed && preg_match('/^\[[a-z_]+\]\s*$/', $line)) {
                    foreach ($pending as $p) {
                        fwrite($dst, $p);
                        $mergedLines++;
                    }
                    $flushed = true;
                    $inRules = false;
                }
                fwrite($dst, $line);
            }
            if ($inRules && !$flushed) {
                foreach ($pending as $p) {
                    fwrite($dst, $p);
                    $mergedLines++;
                }
            }
            fclose($src);
            fclose($dst);
            @rename($mergedPath . '.tmp', $mergedPath);
            @chmod($mergedPath, 0644);
        } else {
            return ['result' => 'failed', 'message' => 'cannot write to ' . self::FEED_DIR];
        }

        return [
            'result' => 'ok',
            'exact_rules' => count($exact),
            'unmatched_domains' => count($orphans),
            'rule_lines' => $mergedLines,
            'csv' => $csvPath,
            'csv_bytes' => @filesize($csvPath),
            'unmatched_csv' => $umPath,
            'unmatched_bytes' => @filesize($umPath),
            'rulepack' => $mergedPath,
            'rulepack_bytes' => @filesize($mergedPath),
            'sample' => array_slice(array_keys($exact), 0, 12),
            'note' => '只固化 appdpi 自己的归属结论（exact 精确域名，不扩散）；'
                . 'appdpi 不支持同时加载 --rules 与 --rulepack，要用合并版 rulepack 并重启 appdpi 才生效',
        ];
    }

    /* ------------------------------------------------------------------ */
    /* GET /api/kixdns/apps/rules?app=878 — the engine's own rule dump      */
    /* ------------------------------------------------------------------ */
    /**
     * appdpi >= 1.0.3 can print the rules behind an app id (`appdpi rules
     * --app N`). The page cannot read the 977 KB rulepack itself, and this is
     * the engine's own view of the criteria — so ask it instead.
     */
    public function rulesAction()
    {
        $id = (int)$this->request->getQuery('app', 'int', 0);
        if ($id <= 0) {
            return ['result' => 'failed', 'message' => 'app id required'];
        }
        $pack = is_readable(self::RULEPACK) ? self::RULEPACK : '';
        $cmd = '/usr/local/bin/appdpi rules --app ' . $id;
        if ($pack !== '') {
            $cmd .= ' --rulepack ' . escapeshellarg($pack);
        }
        $out = (string)@shell_exec($cmd . ' 2>&1');
        return [
            'result' => 'ok',
            'app_id' => $id,
            'lines' => substr_count($out, "[rules]"),
            'text' => $out,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* POST /api/kixdns/apps/unblock   apps=878,2121                       */
    /* ------------------------------------------------------------------ */
    /**
     * Remove the block rules for the given app ids. The UI previously only knew
     * how to write rules (overwriting whatever was there), so once an app was
     * blocked there was no way back from the page — this is that way back.
     */
    public function unblockAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $raw = (string)$this->request->getPost('apps', 'string', '');
        $ids = [];
        foreach (explode(',', $raw) as $p) {
            $p = trim($p);
            if ($p !== '' && ctype_digit($p) && (int)$p > 0) {
                $ids[] = (int)$p;
            }
        }
        if (!$ids) {
            return ['result' => 'failed', 'message' => 'no app ids given'];
        }
        $doc = $this->pipelineDoc();
        if ($doc === null) {
            return ['result' => 'failed', 'message' => 'cannot read the current pipeline JSON'];
        }
        $removed = [];
        foreach ($doc['pipelines'] as &$pl) {
            if (!isset($pl['rules']) || !is_array($pl['rules'])) {
                continue;
            }
            $keep = [];
            foreach ($pl['rules'] as $r) {
                $name = (string)($r['name'] ?? '');
                if (strpos($name, self::BLOCK_PREFIX) !== 0) {
                    $keep[] = $r;
                    continue;
                }
                $hit = false;
                foreach ($ids as $id) {
                    if (strpos($name, '(#' . $id . ')') !== false) {
                        $hit = true;
                        break;
                    }
                }
                if ($hit) {
                    $removed[] = $name;
                    continue;
                }
                $keep[] = $r;
            }
            $pl['rules'] = $keep;
        }
        unset($pl);
        if (!$removed) {
            return ['result' => 'ok', 'removed' => 0, 'message' => 'these apps are not blocked'];
        }
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return ['result' => 'failed', 'message' => 'cannot encode pipeline JSON'];
        }
        $errors = ConfigValidator::validate($json);
        if ($errors) {
            return ['result' => 'failed', 'message' => implode(' ', $errors)];
        }
        $prev = (string)(new KixDNS())->general->config_json;
        if ($prev !== '') {
            @file_put_contents(self::BACKUP, $prev);
        }
        try {
            Config::getInstance()->lock();
            $mdl = new KixDNS();
            $mdl->general->config_json = $json;
            $validation = $mdl->performValidation();
            if ($validation->count() > 0) {
                $msgs = [];
                foreach ($validation as $msg) {
                    $msgs[] = $msg->getField() . ': ' . $msg->getMessage();
                }
                return ['result' => 'failed', 'message' => implode(' ', $msgs)];
            }
            $mdl->serializeToConfig(false, true);
            Config::getInstance()->save();
        } catch (\Throwable $e) {
            return ['result' => 'failed', 'message' => $e->getMessage()];
        }
        $backend = new Backend();
        $backend->configdRun('template reload OPNsense/KixDNS');
        $backend->configdRun('kixdns reload');
        return ['result' => 'applied', 'removed' => count($removed), 'rules' => $removed];
    }

    /* ------------------------------------------------------------------ */
    /* POST /api/kixdns/apps/alerttest — dry-run the cron alert checker     */
    /* ------------------------------------------------------------------ */
    public function alerttestAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $out = (string)(new Backend())->configdRun('kixdns fusion_alert_dry');
        return ['result' => 'ok', 'output' => $out];
    }

    /* ------------------------------------------------------------------ */
    /* helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** rulepack index, generated by FusionController and shared through /tmp */
    private function index(): array
    {
        $blank = ['apps' => [], 'suffix' => [], 'loose' => [], 'app_count' => 0];
        if (is_readable(self::IDX_CACHE)) {
            $c = json_decode((string)@file_get_contents(self::IDX_CACHE), true);
            if (is_array($c) && !empty($c['suffix'])) {
                return $c + $blank;
            }
        }
        return $this->rebuildIndex() + $blank;
    }

    /** minimal parser, only used when the fusion page has never been opened */
    private function rebuildIndex(): array
    {
        if (!is_readable(self::RULEPACK)) {
            return ['apps' => [], 'suffix' => [], 'loose' => [], 'app_count' => 0];
        }
        $apps = [];
        $suffix = [];
        $loose = [];
        $section = '';
        $fh = @fopen(self::RULEPACK, 'r');
        if ($fh === false) {
            return ['apps' => [], 'suffix' => [], 'loose' => [], 'app_count' => 0];
        }
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            if ($line[0] === '[') {
                $section = strtolower(trim($line, "[] \t"));
                continue;
            }
            $f = explode("\t", $line);
            if ($section === 'apps' && count($f) >= 3 && ctype_digit($f[0])) {
                $zh = $f[3] ?? '';
                $apps[(int)$f[0]] = $zh !== '' ? $zh : ($f[2] ?? '');
            } elseif ($section === 'rules' && count($f) >= 6) {
                $t = strtolower($f[1]);
                $appId = (int)$f[4];
                if ($appId <= 0 || !in_array($t, ['domain', 'host', 'cn'], true)) {
                    continue;
                }
                $pat = strtolower(trim($f[3]));
                if ($pat === '') {
                    continue;
                }
                if ($pat[0] === '.') {
                    $key = ltrim($pat, '.');
                    if (strlen($key) >= 4 && !isset($suffix[$key])) {
                        $suffix[$key] = $appId;
                    }
                } elseif (strlen($pat) >= 6) {
                    $loose[$pat] = $appId;
                }
            }
        }
        fclose($fh);
        $out = [
            'apps' => $apps, 'suffix' => $suffix, 'loose' => $loose,
            'app_count' => count($apps),
            'mtime' => (int)@filemtime(self::RULEPACK),
            'size' => (int)@filesize(self::RULEPACK),
            'built' => time(),
        ];
        @file_put_contents(self::IDX_CACHE, json_encode($out), LOCK_EX);
        return $out;
    }

    private function suffixesByApp(array $idx): array
    {
        $out = [];
        foreach ($idx['suffix'] as $suf => $id) {
            $out[(int)$id][] = (string)$suf;
        }
        // [subkeys] is a second, independent source: a main domain there carries
        // main_app=0 ("the domain alone means nothing") but every subkey row has
        // the real app_id. That is exactly why blocking Bilibili by rules alone
        // left i0.hdslb.com reachable — hdslb.com has no [rules] row at all.
        foreach ($this->subkeySuffixes() as $id => $list) {
            foreach ($list as $suf) {
                $out[$id][] = $suf;
            }
        }
        foreach ($out as &$list) {
            $list = array_values(array_unique($list));
            sort($list);
        }
        unset($list);
        return $out;
    }

    /**
     * app_id => [main domains] taken from the [subkeys] section.
     *
     * Columns: subkey_id, main_domain, main_app, pattern, app_id, match, segment
     * `main_app` is 0 for all of them (by design), so the app comes from column 5.
     * One main domain can serve several apps (hdslb.com: upos-/static/bimp/
     * activity -> 878, dl -> 910), which means blocking 878 also blocks 910's
     * subkey — acceptable for a per-app block, and reported by sharedSuffixes().
     */
    private function subkeySuffixes(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        if (!is_readable(self::RULEPACK)) {
            return $cache;
        }
        $fh = @fopen(self::RULEPACK, 'r');
        if ($fh === false) {
            return $cache;
        }
        $section = '';
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            if ($line[0] === '[') {
                $section = strtolower(trim($line, "[] \t"));
                continue;
            }
            if ($section !== 'subkeys') {
                continue;
            }
            $f = explode("\t", $line);
            if (count($f) < 5) {
                continue;
            }
            $appId = (int)$f[4];
            $suf = ltrim(strtolower(trim($f[1])), '.');
            if ($appId <= 0 || $suf === '' || strpos($suf, '.') === false) {
                continue;
            }
            $cache[$appId][$suf] = true;
        }
        fclose($fh);
        foreach ($cache as $id => $set) {
            $cache[$id] = array_keys($set);
        }
        return $cache;
    }

    /** suffix => other app ids that also claim it through [subkeys] */
    private function sharedSuffixes(): array
    {
        $map = [];
        foreach ($this->subkeySuffixes() as $id => $list) {
            foreach ($list as $suf) {
                $map[$suf][$id] = true;
            }
        }
        foreach ($map as $suf => $ids) {
            $map[$suf] = array_keys($ids);
        }
        return $map;
    }

    private function lookup(string $dom, array $idx): int
    {
        $d = strtolower(rtrim($dom, '.'));
        $parts = explode('.', $d);
        $n = count($parts);
        for ($i = 0; $i < $n - 1; $i++) {
            $suf = implode('.', array_slice($parts, $i));
            if (isset($idx['suffix'][$suf])) {
                return (int)$idx['suffix'][$suf];
            }
        }
        return 0;
    }

    private function appNameFromIndex(int $id, array $idx): string
    {
        return isset($idx['apps'][$id]) ? (string)$idx['apps'][$id] : ('#' . $id);
    }

    private function dpiSnapshot(): array
    {
        $out = ['apps' => [], 'domains' => []];
        if (!is_readable(self::DPI_STATUS)) {
            return $out;
        }
        $j = json_decode((string)@file_get_contents(self::DPI_STATUS), true);
        if (!is_array($j)) {
            return $out;
        }
        foreach ($j['apps'] ?? [] as $a) {
            $id = (int)($a['app_id'] ?? 0);
            if ($id > 0) {
                $out['apps'][$id] = [
                    'name' => (string)($a['name'] ?? ''),
                    'flows' => (int)($a['flows'] ?? 0),
                    'bytes' => (int)($a['bytes'] ?? 0),
                ];
            }
        }
        foreach ($j['domains'] ?? [] as $r) {
            $dom = strtolower((string)($r['domain'] ?? ''));
            if ($dom !== '') {
                $out['domains'][$dom] = ['app_id' => (int)($r['app_id'] ?? 0)];
            }
        }
        return $out;
    }

    /** the scan cache written by FusionController: qname => queries */
    private function scanDomains(): array
    {
        $out = [];
        if (!is_readable('/tmp/kixdns_fusion_scan.json')) {
            return $out;
        }
        $c = json_decode((string)@file_get_contents('/tmp/kixdns_fusion_scan.json'), true);
        foreach (($c['data']['domains'] ?? []) as $q => $rec) {
            $out[(string)$q] = (int)($rec['n'] ?? 0);
        }
        return $out;
    }

    /** last two labels; good enough for the feed, and never invents a suffix */
    private function registrable(string $dom): string
    {
        $parts = explode('.', strtolower(trim($dom)));
        $n = count($parts);
        if ($n < 2) {
            return '';
        }
        $last = $parts[$n - 1];
        if (strlen($last) < 2 || strlen($last) > 24) {
            return '';
        }
        return $parts[$n - 2] . '.' . $last;
    }

    /* ---------------- pipeline / block state --------------------------- */

    private function pipelineDoc(): ?array
    {
        $json = (string)(new KixDNS())->general->config_json;
        if (trim($json) === '' && is_readable(self::PIPELINE)) {
            $json = (string)@file_get_contents(self::PIPELINE);
        }
        $doc = json_decode($json, true);
        if (!is_array($doc) || !isset($doc['pipelines']) || !is_array($doc['pipelines'])) {
            return null;
        }
        return $doc;
    }

    private function pipelineSource(): string
    {
        $json = (string)(new KixDNS())->general->config_json;
        if (trim($json) !== '') {
            return 'config';
        }
        return is_readable(self::PIPELINE) ? 'runtime' : 'none';
    }

    private function blockedApps(): array
    {
        $doc = $this->pipelineDoc();
        if ($doc === null) {
            return [];
        }
        $ids = [];
        foreach ($doc['pipelines'] as $pl) {
            foreach (($pl['rules'] ?? []) as $r) {
                $name = (string)($r['name'] ?? '');
                if (strpos($name, self::BLOCK_PREFIX) !== 0) {
                    continue;
                }
                if (preg_match('/#(\d+)\)?\s*$/', $name, $m)) {
                    $ids[] = (int)$m[1];
                }
            }
        }
        return $ids;
    }
}
