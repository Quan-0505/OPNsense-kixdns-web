<?php

/*
 * DNS x DPI fusion API — one page that shows what the two engines each know
 * about the same traffic.
 *
 * Both plugins are already installed side by side and both describe the same
 * LAN from a different angle:
 *
 *   kixdns  : who asked (client_ip), what name (qname), did it resolve
 *             (rcode), how long (latency_ms), did the cache answer (cache_hit),
 *             which upstream (upstream).  No idea which *application* the name
 *             belongs to.
 *   appdpi  : who moved bytes (devices[].ip), to which app (apps[]), over which
 *             peer, and — for names actually seen in payload — which app a
 *             domain belongs to (domains[]).  No idea what was *asked* and
 *             never answered, no cache/latency/rcode view.
 *
 * The join keys are the two things both sides already emit: the client IP and
 * the domain name.  Domain -> app comes from the appdpi rulepack (2597 domain
 * rules + the 1622-app dictionary) with the engine's own observed domain table
 * taking precedence, so no C-side change is required.
 *
 * Read-only: this controller never touches config.xml, never restarts a
 * service, and degrades to a partial answer when either side is missing.
 */

namespace OPNsense\KixDNS\Api;

use OPNsense\Base\ApiControllerBase;

class FusionController extends ApiControllerBase
{
    private const DPI_STATUS  = '/var/run/appdpi/status.json';
    private const DPI_LEASES  = '/var/run/appdpi/leases.txt';
    private const DPI_NEIGH   = '/var/run/appdpi/neigh.txt';
    private const RULEPACK    = '/usr/local/share/appdpi/panabit.rulepack';
    private const IDX_CACHE   = '/tmp/kixdns_dpi_index.json';
    private const SCAN_CACHE  = '/tmp/kixdns_fusion_scan.json';
    private const LOGDIR      = '/var/log/kixdns';

    private const SCAN_TTL    = 20;        // seconds a scanned window stays fresh
    private const IDX_TTL     = 600;       // seconds the rulepack index stays fresh
    private const DEF_BYTES   = 16777216;  // 16 MiB tail window
    private const MAX_BYTES   = 268435456; // 256 MiB hard cap
    private const MIN_BYTES   = 524288;    // 512 KiB floor

    private const SCAN_SCHEMA = 2;         // bump when the scan shape changes
    private const TOP_DOMAINS = 40;        // rows in the fused domain table
    private const TOP_APPS    = 20;        // rows in the fused app table
    private const TOP_DEV     = 80;        // rows in the device table
    private const PER_DEV_TOP = 6;         // remembered domains per device
    private const MAX_KEYS    = 30000;     // cap per counter hash (bounds memory)
    private const MIN_SUFFIX  = 4;         // ignore absurdly short rule suffixes

    /* ------------------------------------------------------------------ */
    /* GET /api/kixdns/fusion/overview[?bytes=N]                           */
    /* ------------------------------------------------------------------ */
    public function overviewAction()
    {
        $bytes = (int)$this->request->get('bytes', 'int', self::DEF_BYTES);
        if ($bytes < self::MIN_BYTES) {
            $bytes = self::MIN_BYTES;
        }
        if ($bytes > self::MAX_BYTES) {
            $bytes = self::MAX_BYTES;
        }

        $t0 = microtime(true);
        $warnings = [];

        $index = $this->ruleIndex($warnings);
        $dpi = $this->dpiSnapshot($warnings);
        $scan = $this->scanLog($bytes, $warnings);

        $dnsApp = [];          // app_id => ['queries'=>int,'domains'=>hash,'top'=>[]]
        $domainRows = [];
        $srcMix = ['dpi' => 0, 'rule' => 0, 'loose' => 0, 'none' => 0];

        // --- domain table: kixdns query volume joined with appdpi observation --
        foreach ($scan['domains'] as $qname => $rec) {
            $hit = $this->classifyDomain((string)$qname, $index, $dpi);
            $obs = $dpi['domains'][$qname] ?? null;
            $appId = $hit['app_id'];
            if ($appId === 0 && $obs !== null && (int)$obs['app_id'] > 0) {
                $appId = (int)$obs['app_id'];
                $hit['src'] = 'dpi';
            }
            $srcMix[$hit['src']] = ($srcMix[$hit['src']] ?? 0) + 1;
            $appName = $appId > 0 ? ($index['apps'][$appId] ?? $this->dpiAppName($dpi, $appId)) : '';
            if ($appId > 0) {
                if (!isset($dnsApp[$appId])) {
                    $dnsApp[$appId] = ['queries' => 0, 'domains' => 0, 'top' => []];
                }
                $dnsApp[$appId]['queries'] += $rec['n'];
                $dnsApp[$appId]['domains']++;
                $dnsApp[$appId]['top'][$qname] = $rec['n'];
            }
            $domainRows[] = [
                'qname' => $qname,
                'queries' => $rec['n'],
                'clients' => count($rec['clients']),
                'rcode' => $this->topHash($rec['rcode'], 4),
                'app_id' => $appId,
                'app' => $appName,
                'src' => $hit['src'],
                'dpi_hits' => $obs !== null ? (int)$obs['hits'] : 0,
                'dpi_bytes' => $obs !== null ? (int)$obs['bytes'] : 0,
            ];
        }
        usort($domainRows, function ($a, $b) {
            return $b['queries'] <=> $a['queries'];
        });
        $domainRows = array_slice($domainRows, 0, self::TOP_DOMAINS);

        // --- app table: appdpi bytes + the DNS volume behind those names -------
        $appRows = [];
        foreach ($dpi['apps'] as $appId => $rec) {
            $dnsRec = $dnsApp[$appId] ?? null;
            $appRows[] = [
                'app_id' => $appId,
                'name' => $rec['name'],
                'flows' => $rec['flows'],
                'bytes' => $rec['bytes'],
                'dns_queries' => $dnsRec !== null ? $dnsRec['queries'] : 0,
                'dns_domains' => $dnsRec !== null ? $dnsRec['domains'] : 0,
                'top_domains' => $dnsRec !== null ? $this->topList($dnsRec['top'], 3) : [],
            ];
        }
        // apps known only from DNS (rules matched, engine never saw bytes)
        foreach ($dnsApp as $appId => $dnsRec) {
            if (isset($dpi['apps'][$appId])) {
                continue;
            }
            $appRows[] = [
                'app_id' => $appId,
                'name' => $index['apps'][$appId] ?? ('#' . $appId),
                'flows' => 0,
                'bytes' => 0,
                'dns_queries' => $dnsRec['queries'],
                'dns_domains' => $dnsRec['domains'],
                'top_domains' => $this->topList($dnsRec['top'], 3),
            ];
        }
        usort($appRows, function ($a, $b) {
            return ($b['bytes'] <=> $a['bytes']) ?: ($b['dns_queries'] <=> $a['dns_queries']);
        });
        $appRows = array_slice($appRows, 0, self::TOP_APPS);

        // --- device table: one row per client seen by either engine -----------
        $devRows = [];
        $ips = array_unique(array_merge(array_keys($scan['clients']), array_keys($dpi['devices'])));
        foreach ($ips as $ip) {
            $ip = (string)$ip;
            if ($ip === '') {
                continue;
            }
            $c = $scan['clients'][$ip] ?? null;
            $d = $dpi['devices'][$ip] ?? null;
            $queries = $c !== null ? $c['n'] : 0;
            $nx = $c !== null ? (int)($c['rcode']['NXDomain'] ?? 0) : 0;
            $refused = $c !== null ? (int)($c['rcode']['Refused'] ?? 0) : 0;
            $topDomains = [];
            if ($c !== null) {
                foreach ($this->topHash($c['dom'], self::PER_DEV_TOP) as $q => $n) {
                    $hit = $this->classifyDomain((string)$q, $index, $dpi);
                    if ($hit['app_id'] === 0) {
                        $obs = $dpi['domains'][$q] ?? null;
                        $hit['app_id'] = $obs !== null ? (int)$obs['app_id'] : 0;
                    }
                    $topDomains[] = [
                        'q' => (string)$q,
                        'n' => $n,
                        'app' => $hit['app_id'] > 0
                            ? ($index['apps'][$hit['app_id']] ?? $this->dpiAppName($dpi, $hit['app_id']))
                            : '',
                    ];
                }
            }
            $devRows[] = [
                'ip' => $ip,
                'mac' => $d['mac'] ?? ($dpi['mac'][$ip] ?? ''),
                'hostname' => $this->hostnameFor($ip, $d, $dpi),
                'vendor' => $d['vendor'] ?? '',
                'in_bytes' => $d['in_bytes'] ?? 0,
                'out_bytes' => $d['out_bytes'] ?? 0,
                'in_bps' => $d['in_bps'] ?? 0,
                'out_bps' => $d['out_bps'] ?? 0,
                'conns' => $d['conns'] ?? 0,
                'online_ms' => $d['online_ms'] ?? 0,
                'top_app' => $d !== null && (int)$d['top_app'] > 0
                    ? ($index['apps'][(int)$d['top_app']] ?? $this->dpiAppName($dpi, (int)$d['top_app']))
                    : '',
                'apps' => $dpi['dev_apps'][$ip] ?? [],
                'dns_queries' => $queries,
                'dns_domains' => $c !== null ? $c['uniq'] : 0,
                'nxdomain' => $nx,
                'refused' => $refused,
                'top_domains' => $topDomains,
            ];
        }
        usort($devRows, function ($a, $b) {
            return ($b['dns_queries'] <=> $a['dns_queries'])
                ?: (($b['in_bytes'] + $b['out_bytes']) <=> ($a['in_bytes'] + $a['out_bytes']));
        });
        $devRows = array_slice($devRows, 0, self::TOP_DEV);

        // --- unmatched domains: the rulepack's blind spot --------------------
        $unmatched = [];
        $unmatchedQueries = 0;
        foreach ($scan['domains'] as $qname => $rec) {
            $appId = $this->classifyDomain((string)$qname, $index, $dpi)['app_id'];
            if ($appId === 0) {
                $obs = $dpi['domains'][$qname] ?? null;
                if ($obs !== null && (int)$obs['app_id'] > 0) {
                    continue;
                }
                $unmatched[$qname] = $rec['n'];
                $unmatchedQueries += $rec['n'];
            }
        }
        arsort($unmatched);
        $unmatchedTop = $this->topList($unmatched, 15);

        $insights = $this->insights($devRows, $domainRows, $scan, $dpi, $unmatchedQueries);

        return [
            'generated' => date('c'),
            'elapsed_ms' => (int)round((microtime(true) - $t0) * 1000),
            'dpi' => [
                'available' => $dpi['ok'],
                'iface' => $dpi['meta']['iface'] ?? '',
                'rules' => (int)($dpi['meta']['rules'] ?? 0),
                'apps_loaded' => $index['app_count'],
                'apps_detected' => count($dpi['apps']),
                'online_devices' => count($dpi['devices']),
                'bps_in' => $dpi['bps_in'],
                'bps_out' => $dpi['bps_out'],
                'status_age_s' => $dpi['age'],
            ],
            'dns' => [
                'log_file' => $scan['file'],
                'window_bytes' => $bytes,
                'window_from' => $scan['from'],
                'window_to' => $scan['to'],
                'queries' => $scan['total'],
                'unique_domains' => count($scan['domains']),
                'clients' => count($scan['clients']),
                'rcode' => $this->topHash($scan['rcode'], 6),
                'noise_queries' => $scan['noise'],
                'src_mix' => $srcMix,
                'unmatched_queries' => $unmatchedQueries,
                'scan_ms' => $scan['ms'],
                'cached' => $scan['cached'],
            ],
            'devices' => $devRows,
            'domains' => $domainRows,
            'apps' => $appRows,
            'unmatched' => $unmatchedTop,
            'insights' => $insights,
            'warnings' => $warnings,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* rule index: app_id -> name, domain suffix -> app_id                 */
    /* ------------------------------------------------------------------ */

    /**
     * Parse the appdpi rulepack once per rulepack revision and cache the
     * inverted form we need: an exact suffix table (pattern `.abc.com` minus the
     * leading dot) so a lookup is "try every label boundary of the qname",
     * plus a small substring fallback list for rules that are not suffixes.
     */
    private function ruleIndex(array &$warnings): array
    {
        $blank = ['apps' => [], 'suffix' => [], 'loose' => [], 'app_count' => 0,
                  'mtime' => 0, 'size' => 0];
        if (!is_readable(self::RULEPACK)) {
            $warnings[] = 'appdpi rulepack not found at ' . self::RULEPACK
                . ' — domains will be classified from the engine snapshot only';
            if (is_readable(self::IDX_CACHE)) {
                $c = json_decode((string)@file_get_contents(self::IDX_CACHE), true);
                if (is_array($c) && isset($c['suffix'])) {
                    return $c + $blank;
                }
            }
            return $blank;
        }
        $mtime = (int)@filemtime(self::RULEPACK);
        $size = (int)@filesize(self::RULEPACK);

        if (is_readable(self::IDX_CACHE)) {
            $raw = (string)@file_get_contents(self::IDX_CACHE);
            $c = json_decode($raw, true);
            if (is_array($c) && ($c['mtime'] ?? 0) === $mtime && ($c['size'] ?? 0) === $size
                && isset($c['suffix']) && (time() - (int)($c['built'] ?? 0)) < self::IDX_TTL) {
                return $c + $blank;
            }
        }

        $apps = [];
        $suffix = [];
        $loose = [];
        $section = '';
        $fh = @fopen(self::RULEPACK, 'r');
        if ($fh === false) {
            return $blank;
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
            if ($section === 'apps') {
                // app_id name name_zh ...
                $f = explode("\t", $line);
                if (count($f) < 3 || !ctype_digit($f[0])) {
                    continue;
                }
                $id = (int)$f[0];
                $zh = $f[3] ?? '';
                $en = $f[2] ?? '';
                $apps[$id] = $zh !== '' ? $zh : $en;
            } elseif ($section === 'rules') {
                // rule_id type match pattern app_id app_name segment ... app_name_zh
                $f = explode("\t", $line);
                if (count($f) < 6) {
                    continue;
                }
                $type = strtolower($f[1]);
                if ($type !== 'domain' && $type !== 'host' && $type !== 'cn') {
                    continue;
                }
                $appId = (int)$f[4];
                if ($appId <= 0) {
                    continue;
                }
                $pat = strtolower(trim($f[3]));
                if ($pat === '' || strlen($pat) < 3) {
                    continue;
                }
                if ($pat[0] === '.') {
                    $key = ltrim($pat, '.');
                    if (strlen($key) >= self::MIN_SUFFIX && !isset($suffix[$key])) {
                        $suffix[$key] = $appId;
                    }
                } else {
                    // not a suffix rule: keep it for a substring fallback pass,
                    // but only when long enough not to misfire
                    if (strlen($pat) >= 6) {
                        $loose[$pat] = $appId;
                    }
                }
            }
        }
        fclose($fh);

        $out = [
            'apps' => $apps,
            'suffix' => $suffix,
            'loose' => $loose,
            'app_count' => count($apps),
            'mtime' => $mtime,
            'size' => $size,
            'built' => time(),
        ];
        @file_put_contents(self::IDX_CACHE, json_encode($out), LOCK_EX);
        return $out + $blank;
    }

    /**
     * Domain -> app. Checks every label boundary of the name against the suffix
     * table (longest first, which is the most specific rule), then the substring
     * fallback list. Returns ['app_id'=>int,'src'=>'rule|loose|none'].
     */
    private function classifyDomain(string $qname, array $index, array $dpi): array
    {
        $d = strtolower(rtrim(trim($qname), '.'));
        if ($d === '' || $d === '*' || ctype_digit(str_replace('.', '', $d))) {
            return ['app_id' => 0, 'src' => 'none'];
        }
        // the engine's own observation wins when it has one
        if (isset($dpi['domains'][$d]) && (int)$dpi['domains'][$d]['app_id'] > 0) {
            return ['app_id' => (int)$dpi['domains'][$d]['app_id'], 'src' => 'dpi'];
        }
        if (!empty($index['suffix'])) {
            $parts = explode('.', $d);
            $n = count($parts);
            for ($i = 0; $i < $n - 1; $i++) {
                $suf = implode('.', array_slice($parts, $i));
                if (isset($index['suffix'][$suf])) {
                    return ['app_id' => (int)$index['suffix'][$suf], 'src' => 'rule'];
                }
            }
        }
        if (!empty($index['loose'])) {
            foreach ($index['loose'] as $pat => $appId) {
                if (strpos($d, (string)$pat) !== false) {
                    return ['app_id' => (int)$appId, 'src' => 'loose'];
                }
            }
        }
        return ['app_id' => 0, 'src' => 'none'];
    }

    /* ------------------------------------------------------------------ */
    /* appdpi snapshot                                                     */
    /* ------------------------------------------------------------------ */

    private function dpiSnapshot(array &$warnings): array
    {
        $out = [
            'ok' => false, 'age' => null, 'meta' => [],
            'devices' => [], 'apps' => [], 'dev_apps' => [], 'domains' => [],
            'bps_in' => 0, 'bps_out' => 0, 'mac' => [], 'names' => [],
        ];
        if (!is_readable(self::DPI_STATUS)) {
            $warnings[] = 'appdpi is not running (no ' . self::DPI_STATUS . ') — '
                . 'the page shows the DNS half only';
            return $out;
        }
        $raw = @file_get_contents(self::DPI_STATUS);
        $j = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($j)) {
            $warnings[] = 'appdpi snapshot unreadable or mid-rewrite — using DNS data only';
            return $out;
        }
        $out['ok'] = true;
        $out['meta'] = $j['meta'] ?? [];
        $out['age'] = isset($out['meta']['ts']) ? max(0, time() - (int)$out['meta']['ts']) : null;

        foreach ($j['devices'] ?? [] as $d) {
            if (!isset($d['ip'])) {
                continue;
            }
            $out['devices'][(string)$d['ip']] = $d;
            if (!empty($d['mac'])) {
                $out['mac'][(string)$d['ip']] = (string)$d['mac'];
            }
            $out['bps_in'] += (int)($d['in_bps'] ?? 0);
            $out['bps_out'] += (int)($d['out_bps'] ?? 0);
            if (!empty($d['hostname'])) {
                $out['names'][(string)$d['ip']] = (string)$d['hostname'];
            }
        }
        foreach ($j['apps'] ?? [] as $a) {
            $id = (int)($a['app_id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out['apps'][$id] = [
                'name' => (string)($a['name'] ?? ('#' . $id)),
                'flows' => (int)($a['flows'] ?? 0),
                'bytes' => (int)($a['bytes'] ?? 0),
            ];
        }
        foreach ($j['dev_apps'] ?? [] as $r) {
            $ip = (string)($r['dev'] ?? '');
            if ($ip === '') {
                continue;
            }
            $out['dev_apps'][$ip][] = [
                'app_id' => (int)($r['app_id'] ?? 0),
                'name' => (string)($r['name'] ?? ''),
                'flows' => (int)($r['flows'] ?? 0),
                'bytes' => (int)($r['bytes'] ?? 0),
            ];
        }
        foreach ($j['domains'] ?? [] as $r) {
            $dom = strtolower((string)($r['domain'] ?? ''));
            if ($dom === '') {
                continue;
            }
            $out['domains'][$dom] = [
                'app_id' => (int)($r['app_id'] ?? 0),
                'hits' => (int)($r['hits'] ?? 0),
                'bytes' => (int)($r['bytes'] ?? 0),
            ];
        }
        // dhcp lease + arp as a hostname fallback for IPs the engine saw only
        // as a MAC
        foreach ($this->leaseNames() as $ip => $name) {
            if (!isset($out['names'][$ip])) {
                $out['names'][$ip] = $name;
            }
        }
        return $out;
    }

    private function dpiAppName(array $dpi, int $appId): string
    {
        if (isset($dpi['apps'][$appId]['name'])) {
            return (string)$dpi['apps'][$appId]['name'];
        }
        return $appId > 0 ? ('#' . $appId) : '';
    }

    private function leaseNames(): array
    {
        $out = [];
        if (!is_readable(self::DPI_LEASES)) {
            return $out;
        }
        $fh = @fopen(self::DPI_LEASES, 'r');
        if ($fh === false) {
            return $out;
        }
        while (($line = fgets($fh)) !== false) {
            $f = preg_split('/\s+/', trim($line));
            if (count($f) < 4) {
                continue;
            }
            $ip = $f[2];
            $name = $f[3];
            if ($ip !== '' && $name !== '' && $name !== '*') {
                $out[$ip] = $name;
            }
        }
        fclose($fh);
        return $out;
    }

    private function hostnameFor(string $ip, ?array $dev, array $dpi): string
    {
        if ($dev !== null && !empty($dev['hostname'])) {
            return (string)$dev['hostname'];
        }
        return (string)($dpi['names'][$ip] ?? '');
    }

    /* ------------------------------------------------------------------ */
    /* kixdns log scan                                                     */
    /* ------------------------------------------------------------------ */

    private function newestLog(): ?string
    {
        $files = glob(self::LOGDIR . '/kixdns_*.log');
        if (!$files) {
            return null;
        }
        usort($files, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });
        return $files[0];
    }

    /**
     * Scan the tail window of the newest log and build the three views the page
     * needs: per client, per domain, and the global rcode split. Results are
     * cached for SCAN_TTL seconds keyed on (file, size, window).
     */
    private function scanLog(int $bytes, array &$warnings): array
    {
        $blank = [
            'file' => null, 'total' => 0, 'noise' => 0, 'rcode' => [],
            'clients' => [], 'domains' => [], 'from' => null, 'to' => null,
            'ms' => 0, 'cached' => false,
        ];
        $file = $this->newestLog();
        if ($file === null) {
            $warnings[] = 'no kixdns log file in ' . self::LOGDIR
                . ' — the DPI half is shown without DNS attribution';
            return $blank;
        }
        $size = (int)@filesize($file);

        if (is_readable(self::SCAN_CACHE)) {
            $c = json_decode((string)@file_get_contents(self::SCAN_CACHE), true);
            if (is_array($c) && (int)($c['v'] ?? 0) === self::SCAN_SCHEMA
                && ($c['file'] ?? '') === $file && (int)($c['bytes'] ?? 0) === $bytes
                && (time() - (int)($c['ts'] ?? 0)) < self::SCAN_TTL
                && $size - (int)($c['size'] ?? 0) < max(262144, intdiv($bytes, 4))
                && isset($c['data'])) {
                $c['data']['cached'] = true;
                return $c['data'];
            }
        }

        $t0 = microtime(true);
        $fh = @fopen($file, 'r');
        if ($fh === false) {
            $warnings[] = 'cannot read ' . $file;
            return $blank;
        }
        $start = max(0, $size - $bytes);
        if ($start > 0) {
            fseek($fh, $start);
            fgets($fh);   // drop the torn first line
        }
        $clients = [];
        $domains = [];
        $rcode = [];
        $total = 0;
        $noise = 0;
        $from = null;
        $to = null;

        while (($line = fgets($fh)) !== false) {
            if (strpos($line, 'event="dns_response"') === false) {
                continue;
            }
            $t = $this->tokens($line);
            if (!isset($t['qname'])) {
                continue;
            }
            $q = strtolower(rtrim($t['qname'], '.'));
            if ($q === '') {
                continue;
            }
            $client = $t['client_ip'] ?? '';
            $rc = $this->canonicalRcode($t['rcode'] ?? '');
            $total++;
            if ($rc !== '') {
                $rcode[$rc] = ($rcode[$rc] ?? 0) + 1;
            }
            if (preg_match('/T(\d{2}:\d{2}:\d{2})/', $line, $m)) {
                if ($from === null) {
                    $from = $m[1];
                }
                $to = $m[1];
            }
            // wpad.localdomain, in-addr.arpa and other single-label names are
            // infrastructure chatter, not application traffic: they count as
            // queries but never as a "domain the rulepack should know".
            $isNoise = $this->isNoiseDomain($q);
            if ($isNoise) {
                $noise++;
            }
            if (!$isNoise) {
                if (!isset($domains[$q])) {
                    if (count($domains) < self::MAX_KEYS) {
                        $domains[$q] = ['n' => 0, 'clients' => [], 'rcode' => []];
                    }
                }
                if (isset($domains[$q])) {
                    $domains[$q]['n']++;
                    if ($client !== '') {
                        $domains[$q]['clients'][$client] = 1;
                    }
                    $domains[$q]['rcode'][$rc] = ($domains[$q]['rcode'][$rc] ?? 0) + 1;
                }
            }
            if ($client !== '') {
                if (!isset($clients[$client])) {
                    if (count($clients) < 4096) {
                        $clients[$client] = ['n' => 0, 'rcode' => [], 'dom' => [], 'uniq' => 0];
                    }
                }
                if (isset($clients[$client])) {
                    $clients[$client]['n']++;
                    $clients[$client]['rcode'][$rc] = ($clients[$client]['rcode'][$rc] ?? 0) + 1;
                    if (!$isNoise) {
                        if (!isset($clients[$client]['dom'][$q])) {
                            $clients[$client]['uniq']++;
                        }
                        $clients[$client]['dom'][$q] = ($clients[$client]['dom'][$q] ?? 0) + 1;
                        if (count($clients[$client]['dom']) > 2000) {
                            arsort($clients[$client]['dom']);
                            $clients[$client]['dom'] = array_slice(
                                $clients[$client]['dom'], 0, 500, true
                            );
                        }
                    }
                }
            }
        }
        fclose($fh);

        $data = [
            'file' => $file,
            'total' => $total,
            'noise' => $noise,
            'rcode' => $rcode,
            'clients' => $clients,
            'domains' => $domains,
            'from' => $from,
            'to' => $to,
            'ms' => (int)round((microtime(true) - $t0) * 1000),
            'cached' => false,
        ];
        $cache = [
            'v' => self::SCAN_SCHEMA, 'file' => $file, 'size' => $size,
            'bytes' => $bytes, 'ts' => time(), 'data' => $data,
        ];
        @file_put_contents(self::SCAN_CACHE, json_encode($cache), LOCK_EX);
        return $data;
    }

    /* ------------------------------------------------------------------ */
    /* small helpers                                                       */
    /* ------------------------------------------------------------------ */

    private function tokens(string $line): array
    {
        $out = [];
        $len = strlen($line);
        $i = 0;
        while ($i < $len) {
            $eq = strpos($line, '=', $i);
            if ($eq === false) {
                break;
            }
            $ks = $eq;
            while ($ks > 0 && !ctype_space($line[$ks - 1]) && $line[$ks - 1] !== '"') {
                $ks--;
            }
            $key = substr($line, $ks, $eq - $ks);
            if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                $i = $eq + 1;
                continue;
            }
            $j = $eq + 1;
            if ($j < $len && $line[$j] === '"') {
                $end = strpos($line, '"', $j + 1);
                if ($end === false) {
                    $val = substr($line, $j + 1);
                    $i = $len;
                } else {
                    $val = substr($line, $j + 1, $end - $j - 1);
                    $i = $end + 1;
                }
            } else {
                $end = $j;
                while ($end < $len && !ctype_space($line[$end])) {
                    $end++;
                }
                $val = substr($line, $j, $end - $j);
                $i = $end;
            }
            $out[$key] = $val;
        }
        return $out;
    }

    /**
     * Infrastructure names that must never be counted as "a domain the rulepack
     * is missing": single-label hosts (wpad, localhost), reverse lookups and the
     * reserved local-use suffixes. They are still real DNS queries, so the query
     * counters keep them; only the domain/coverage views drop them.
     */
    private function isNoiseDomain(string $q): bool
    {
        static $cache = [];
        if (isset($cache[$q])) {
            return $cache[$q];
        }
        $noise = false;
        if (strpos($q, '.') === false) {
            $noise = true;                                  // wpad, localhost
        } elseif (preg_match('/\.(arpa|local|localdomain|lan|home|internal|invalid|test|onion|example)$/', $q)) {
            $noise = true;                                  // PTR, mDNS, reserved
        } elseif (strncmp($q, 'wpad.', 5) === 0 || strncmp($q, 'isatap.', 7) === 0) {
            $noise = true;
        }
        $cache[$q] = $noise;
        return $noise;
    }

    private function canonicalRcode(string $rcode): string
    {
        $r = trim($rcode, " \t\"");
        if ($r === '') {
            return '';
        }
        $map = [
            'noerror' => 'NoError', 'no error' => 'NoError', 'nxdomain' => 'NXDomain',
            'nx domain' => 'NXDomain', 'servfail' => 'ServFail', 'server failure' => 'ServFail',
            'refused' => 'Refused', 'formerr' => 'FormErr', 'form error' => 'FormErr',
            'notimp' => 'NotImp', 'not implemented' => 'NotImp',
        ];
        $k = strtolower($r);
        return $map[$k] ?? $r;
    }

    private function topHash(array $hash, int $n): array
    {
        if (!$hash) {
            return [];
        }
        arsort($hash);
        return array_slice($hash, 0, $n, true);
    }

    private function topList(array $hash, int $n): array
    {
        $out = [];
        foreach ($this->topHash($hash, $n) as $k => $v) {
            $out[] = ['q' => (string)$k, 'n' => (int)$v];
        }
        return $out;
    }

    /**
     * Cross-engine findings. Each one is a statement about the current window,
     * never an alarm: the DNS window is the log tail, the DPI window is the
     * engine's own snapshot.
     */
    private function insights(array $devices, array $domains, array $scan, array $dpi, int $unmatchedQueries): array
    {
        $out = [];
        if ($scan['total'] <= 0) {
            return $out;
        }
        // coverage is measured against real names only: wpad/PTR chatter would
        // otherwise inflate the "rulepack is blind" number
        $denom = max(1, (int)$scan['total'] - (int)($scan['noise'] ?? 0));

        // 1. heavy traffic but almost no DNS -> DoH / hardcoded IP / proxy
        foreach ($devices as $d) {
            $bytes = (int)$d['in_bytes'] + (int)$d['out_bytes'];
            if ($bytes >= 3 * 1024 * 1024 && (int)$d['dns_queries'] <= 3) {
                $out[] = [
                    'level' => 'warn', 'kind' => 'doh',
                    'text' => $d['ip'] . ($d['hostname'] !== '' ? ' (' . $d['hostname'] . ')' : '')
                        . ' 窗口内流量 ' . $this->humanBytes($bytes) . '，DNS 查询仅 '
                        . (int)$d['dns_queries'] . ' 次 — 疑似 DoH/硬编码 IP 或走了代理',
                ];
            }
        }

        // 2. NXDOMAIN storms
        foreach ($devices as $d) {
            if ((int)$d['dns_queries'] >= 30) {
                $ratio = $d['dns_queries'] > 0 ? ((int)$d['nxdomain'] / (int)$d['dns_queries']) : 0;
                if ($ratio >= 0.3) {
                    $out[] = [
                        'level' => 'warn', 'kind' => 'nxdomain',
                        'text' => $d['ip'] . ' 的 NXDOMAIN 占比 ' . round($ratio * 100)
                            . '%（' . (int)$d['nxdomain'] . '/' . (int)$d['dns_queries']
                            . '）— 广告 SDK 探测、DGA 或域名已失效',
                    ];
                }
            }
        }

        // 3. queried a lot but the engine saw zero bytes for those names
        $dnsOnly = [];
        foreach ($domains as $r) {
            if ($r['queries'] >= 20 && $r['dpi_bytes'] === 0 && $r['src'] !== 'none') {
                $dnsOnly[] = $r['qname'];
            }
        }
        if ($dnsOnly) {
            $out[] = [
                'level' => 'info', 'kind' => 'dns-only',
                'text' => count($dnsOnly) . ' 个域名窗口内查询 ≥20 次但 appdpi 未见流量（如 '
                    . implode(', ', array_slice($dnsOnly, 0, 3))
                    . '）— 被墙/解析失败、预取，或流量走了别的出口',
            ];
        }

        // 4. appdpi observed names the resolver never logged -> bypass
        $missing = [];
        foreach ($dpi['domains'] as $dom => $r) {
            if ((int)$r['hits'] >= 3 && !isset($scan['domains'][$dom])) {
                $missing[] = (string)$dom;
            }
        }
        if ($missing) {
            $out[] = [
                'level' => 'info', 'kind' => 'bypass',
                'text' => count($missing) . ' 个 appdpi 观察到的域名在本窗口内没有任何 DNS 查询（如 '
                    . implode(', ', array_slice($missing, 0, 3))
                    . '）— 可能用 DoH/DoT 自行解析，或只是 TTL 较长',
            ];
        }

        // 5. one device hammering one name: heartbeat, probe, or a hijacked client
        foreach ($devices as $d) {
            if ((int)$d['dns_queries'] < 100 || empty($d['top_domains'])) {
                continue;
            }
            $t = $d['top_domains'][0];
            $share = (int)$d['dns_queries'] > 0 ? $t['n'] / (int)$d['dns_queries'] : 0;
            if ($share >= 0.8) {
                $out[] = [
                    'level' => 'info', 'kind' => 'poll',
                    'text' => $d['ip'] . ' 的查询高度集中：' . $t['q'] . ' 占 '
                        . round($share * 100) . '%（' . $t['n'] . '/' . (int)$d['dns_queries']
                        . '）— 心跳/连通性探测，或客户端被劫持在刷一个域名',
                ];
            }
        }

        // 6. one client dominating the whole window (a forwarder or a runaway)
        if (count($devices) >= 3 && (int)$devices[0]['dns_queries'] >= 200) {
            $share = (int)$devices[0]['dns_queries'] / $denom;
            if ($share >= 0.5) {
                $out[] = [
                    'level' => 'info', 'kind' => 'concentration',
                    'text' => $devices[0]['ip'] . ' 一台就占了窗口内 DNS 查询的 '
                        . round($share * 100) . '% — 它可能是下游转发器，或自身在异常刷查询',
                ];
            }
        }

        // 7. rulepack blind spot
        $share = round(100.0 * $unmatchedQueries / $denom, 1);
        if ($share >= 30) {
            $out[] = [
                'level' => 'info', 'kind' => 'coverage',
                'text' => '未识别域名的查询占比 ' . $share . '%（' . $unmatchedQueries . '/'
                    . $denom . '）— 规则库对此网段的覆盖偏低，'
                    . '参考下方「未识别域名」清单补规则',
            ];
        }

        // 6. everything matched
        if (!$out) {
            $out[] = [
                'level' => 'ok', 'kind' => 'clean',
                'text' => '两个引擎在这个窗口内没有发现异常：DNS 与流量可以对上',
            ];
        }
        return $out;
    }

    private function humanBytes(int $n): string
    {
        $u = ['B', 'K', 'M', 'G', 'T'];
        $i = 0;
        $v = (float)$n;
        while ($v >= 1024 && $i < count($u) - 1) {
            $v /= 1024;
            $i++;
        }
        return ($i === 0 ? (string)(int)$v : number_format($v, $v < 10 ? 2 : 1)) . $u[$i];
    }
}
