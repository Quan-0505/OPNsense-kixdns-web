<?php

/*
 * KixDNS statistics / query-log API.
 *
 * kixdns has no built-in stats API, so this builds AdGuardHome-style
 * observability out of its own log stream. Each handled query is logged as:
 *
 *   forwarded event="dns_response" upstream=udp:202.96.128.86:53 qname=host
 *   qtype=A rcode=NoError latency_ms=8 client_ip=192.168.5.101
 *   pipeline=过滤响应 cache=true resp_match=true transport=None
 *
 * Aggregation is incremental: the parse cursor (offset) and the counters are
 * kept in a small JSON cache file, so a steady-state refresh only parses the
 * lines appended since the previous call.
 */

namespace OPNsense\KixDNS\Api;

use OPNsense\Base\ApiControllerBase;

class StatsController extends ApiControllerBase
{
    private const LOGDIR = '/var/log/kixdns';
    private const CACHE = '/tmp/kixdns_stats_cache.json';
    private const CACHE_TTL = 15;      // seconds a cached aggregation stays fresh
    private const TOPN = 10;
    private const MAX_KEYS = 20000;     // cap per counter hash to bound memory

    /**
     * Resolve the newest kixdns log file.
     */
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
     * Parse one log line into a query record, or null when it is not a
     * forwarded query line.
     */
    private function parseLine(string $line): ?array
    {
        if (strpos($line, 'forwarded') === false || strpos($line, 'qname=') === false) {
            return null;
        }
        $rec = [];
        foreach (explode(' ', $line) as $tok) {
            $eq = strpos($tok, '=');
            if ($eq === false || $eq === 0) {
                continue;
            }
            $k = substr($tok, 0, $eq);
            $v = trim(substr($tok, $eq + 1), '"');
            switch ($k) {
                case 'qname':
                case 'qtype':
                case 'rcode':
                case 'client_ip':
                case 'upstream':
                case 'pipeline':
                case 'latency_ms':
                case 'cache':
                    $rec[$k] = $v;
                    break;
                default:
                    break;
            }
        }
        if (!isset($rec['qname'])) {
            return null;
        }
        return $rec;
    }

    /**
     * Hour bucket (0-23) from the leading syslog timestamp of a line.
     */
    private function hourOf(string $line): ?string
    {
        if (preg_match('/\d{4}-\d{2}-\d{2}T(\d{2}):/', $line, $m)) {
            return $m[1];
        }
        return null;
    }

    private function bump(array &$hash, string $key): void
    {
        if (count($hash) >= self::MAX_KEYS && !isset($hash[$key])) {
            return;
        }
        $hash[$key] = ($hash[$key] ?? 0) + 1;
    }

    /**
     * Incremental aggregation over the newest log file.
     */
    private function aggregate(): array
    {
        $blank = [
            'file' => null, 'offset' => 0, 'total' => 0, 'cache_hits' => 0,
            'rcode' => [], 'qtype' => [], 'upstream' => [], 'client' => [],
            'qname' => [], 'hourly' => [], 'latency_sum' => 0, 'latency_count' => 0,
            'slow' => 0, 'last_ts' => 0,
        ];

        $file = $this->newestLog();
        if ($file === null) {
            return $blank;
        }

        $state = null;
        if (is_readable(self::CACHE)) {
            $raw = @file_get_contents(self::CACHE);
            if ($raw !== false) {
                $state = json_decode($raw, true);
            }
        }
        // restart parsing when the log rotated or the file shrank
        if (!is_array($state) || ($state['file'] ?? null) !== $file
            || ($state['offset'] ?? 0) > filesize($file)) {
            $state = $blank;
        }
        $state['file'] = $file;

        $fh = @fopen($file, 'r');
        if ($fh === false) {
            return $state;
        }
        fseek($fh, (int)$state['offset']);
        $partial = '';
        while (($line = fgets($fh)) !== false) {
            // skip a torn trailing line from a previous partial read
            $partial .= $line;
            if (substr($partial, -1) !== "\n") {
                continue;
            }
            $line = $partial;
            $partial = '';
            $rec = $this->parseLine($line);
            if ($rec === null) {
                continue;
            }
            $state['total']++;
            if (($rec['cache'] ?? '') === 'true') {
                $state['cache_hits']++;
            }
            if (($rec['latency_ms'] ?? '') !== '') {
                $ms = (int)$rec['latency_ms'];
                $state['latency_sum'] += $ms;
                $state['latency_count']++;
                if ($ms >= 500) {
                    $state['slow']++;
                }
            }
            $this->bump($state['rcode'], $rec['rcode'] ?? 'UNKNOWN');
            $this->bump($state['qtype'], $rec['qtype'] ?? 'UNKNOWN');
            $this->bump($state['upstream'], $rec['upstream'] ?? 'unknown');
            $this->bump($state['client'], $rec['client_ip'] ?? 'unknown');
            $this->bump($state['qname'], $rec['qname']);
            $hour = $this->hourOf($line);
            if ($hour !== null) {
                $state['hourly'][$hour] = ($state['hourly'][$hour] ?? 0) + 1;
            }
        }
        $state['offset'] = ftell($fh);
        fclose($fh);
        $state['last_ts'] = time();

        // trim oversized hashes so the cache file stays small
        foreach (['qname', 'client', 'upstream', 'qtype', 'rcode'] as $k) {
            if (count($state[$k]) > self::MAX_KEYS) {
                arsort($state[$k]);
                $state[$k] = array_slice($state[$k], 0, self::MAX_KEYS, true);
            }
        }
        @file_put_contents(self::CACHE, json_encode($state), LOCK_EX);
        return $state;
    }

    private function cachedAggregate(): array
    {
        if (is_readable(self::CACHE)) {
            $raw = @file_get_contents(self::CACHE);
            if ($raw !== false) {
                $state = json_decode($raw, true);
                if (is_array($state) && (time() - (int)($state['last_ts'] ?? 0)) < self::CACHE_TTL) {
                    $file = $this->newestLog();
                    // still fresh AND same file -> reuse
                    if (($state['file'] ?? null) === $file) {
                        return $state;
                    }
                }
            }
        }
        return $this->aggregate();
    }

    private function top(array $hash, int $n = self::TOPN): array
    {
        arsort($hash);
        $out = [];
        foreach (array_slice($hash, 0, $n, true) as $k => $v) {
            $out[] = ['name' => (string)$k, 'count' => (int)$v];
        }
        return $out;
    }

    /**
     * GET /api/kixdns/stats/overview
     */
    public function overviewAction()
    {
        $s = $this->cachedAggregate();
        $total = (int)$s['total'];
        $hist = [];
        for ($h = 0; $h < 24; $h++) {
            $key = sprintf('%02d', $h);
            $hist[$key] = (int)($s['hourly'][$key] ?? 0);
        }
        $rcodes = $s['rcode'];
        arsort($rcodes);

        // Scope note: at info log level kixdns only emits "forwarded" records
        // (upstream responses). Cache hits are logged at debug level
        // (engine/phases.rs "cache hit"), so they are absent here — "total" is
        // forwarded queries, not all client queries.
        return [
            'total' => $total,
            'cache_hits' => (int)$s['cache_hits'],
            'cached_ratio' => $total > 0 ? round(100.0 * $s['cache_hits'] / $total, 1) : 0.0,
            'unique_domains' => count($s['qname']),
            'unique_domains_limit' => self::MAX_KEYS,
            'avg_latency' => $s['latency_count'] > 0
                ? round($s['latency_sum'] / $s['latency_count'], 1) : 0.0,
            'slow_queries' => (int)$s['slow'],
            'rcode' => $rcodes,
            'hourly' => $hist,
            'qtype' => $this->top($s['qtype']),
            'upstreams' => $this->top($s['upstream']),
            'top_domains' => $this->top($s['qname']),
            'top_clients' => $this->top($s['client']),
            'log_file' => $s['file'],
            'log_scope' => 'forwarded',
            'generated' => date('c'),
        ];
    }

    /**
     * GET /api/kixdns/stats/querylog&limit=&qname=&client=
     */
    public function querylogAction()
    {
        $limit = (int)($this->request->get('limit') ?? 200);
        if ($limit <= 0 || $limit > 2000) {
            $limit = 200;
        }
        $fq = trim((string)($this->request->get('qname') ?? ''));
        $fc = trim((string)($this->request->get('client') ?? ''));

        $file = $this->newestLog();
        if ($file === null) {
            return ['rows' => [], 'log_file' => null];
        }

        // read the tail of the file (last ~1 MB is plenty for a few hundred rows)
        $size = filesize($file);
        $chunk = 1024 * 1024;
        $fh = fopen($file, 'r');
        if ($fh === false) {
            return ['rows' => [], 'log_file' => $file];
        }
        $start = max(0, $size - $chunk);
        fseek($fh, $start);
        $data = stream_get_contents($fh);
        fclose($fh);
        if ($start > 0) {
            // drop the first (possibly partial) line
            $nl = strpos($data, "\n");
            $data = $nl === false ? '' : substr($data, $nl + 1);
        }

        $rows = [];
        foreach (array_reverse(explode("\n", $data)) as $line) {
            if ($limit <= 0) {
                break;
            }
            $rec = $this->parseLine($line);
            if ($rec === null) {
                continue;
            }
            if ($fq !== '' && stripos($rec['qname'], $fq) === false) {
                continue;
            }
            if ($fc !== '' && stripos($rec['client_ip'] ?? '', $fc) === false) {
                continue;
            }
            if (preg_match('/T(\d{2}:\d{2}:\d{2})/', $line, $m)) {
                $rec['time'] = $m[1];
            } else {
                $rec['time'] = '';
            }
            $rows[] = $rec;
            $limit--;
        }

        return [
            'rows' => $rows,
            'log_file' => $file,
            'count' => count($rows),
        ];
    }

    /**
     * GET /api/kixdns/stats/service -> service-level facts shown on the dashboard
     */
    public function serviceAction()
    {
        $version = trim(shell_exec('/usr/local/bin/kixdns --version 2>/dev/null') ?? '');
        $running = false;
        $pid = '';
        $status = trim(shell_exec('/usr/local/etc/rc.d/kixdns status 2>/dev/null') ?? '');
        if (preg_match('/as pid (\d+)/', $status, $m) === 1) {
            $running = true;
            $pid = $m[1];
        }
        $bind = 'n/a';
        $conf = '/usr/local/etc/kixdns/pipeline.json';
        if (is_readable($conf)) {
            $j = json_decode((string)@file_get_contents($conf), true);
            if (is_array($j) && isset($j['settings']['bind_udp'])) {
                $bind = $j['settings']['bind_udp'];
            }
        }
        $ipt = @shell_exec('/sbin/iptables -w 64 -t nat -L KIXDNS_REDIRECT -n 2>/dev/null | grep -c REDIRECT');
        $rules = (int)trim((string)$ipt);

        // takeover mode: binding :53 directly needs no NAT rule, anything else
        // relies on the iptables REDIRECT -> port hop
        $direct = (substr($bind, -3) === ':53');
        $mode = $direct ? 'direct' : ($rules > 0 ? 'redirect' : 'none');

        return [
            'running' => $running,
            'pid' => $pid,
            'version' => $version,
            'bind' => $bind,
            'mode' => $mode,
            'iptables_rules' => $rules,
        ];
    }
}
