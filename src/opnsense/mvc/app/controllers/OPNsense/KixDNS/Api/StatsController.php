<?php

/*
 * KixDNS statistics / query-log API.
 *
 * Two data sources are handled per log line, selected automatically:
 *
 * 1. Upstream kixdns >= 0.2.0 with --debug installs the built-in
 *    TracingObserver, which emits structured engine events:
 *
 *      event="request_started"  request_id=N listener="default" client=IP:PORT qname="host" qtype=A
 *      event="cache_hit"        request_id=N kind=Fresh remaining_ttl_s=10
 *      event="cache_miss"       request_id=N
 *      event="request_finished" request_id=N status=Completed latency_us=13220
 *      event="upstream_result"  request_id=N upstream="1.1.1.1:53" outcome=Success latency_us=13004
 *
 *    Those give the true client query rate, the true cache hit ratio and the
 *    real end-to-end latency (cache hits never reach an upstream).
 *
 * 2. Without --debug only info-level lines exist:
 *
 *      forwarded event="dns_response" upstream=udp:...:53 qname=host qtype=A
 *      rcode=NoError latency_ms=8 client_ip=... pipeline=... cache=true
 *
 *    Those are *forwarded* (upstream-bound) responses only — cache hits are
 *    invisible, so the total is then an upstream volume, not the client rate.
 *
 * Aggregation is incremental: the parse cursor (byte offset) plus counters are
 * kept in a small JSON cache file, so a steady-state refresh only parses lines
 * appended since the previous call.
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
     * Split "key=value" tokens out of a log line. Values may be quoted and may
     * contain spaces (rcode=No Error, detail=Forward { ... }), so a plain
     * explode(' ') is not sufficient.
     */
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

    private function hourOf(string $line): ?string
    {
        if (preg_match('/\d{4}-\d{2}-\d{2}T(\d{2}):/', $line, $m)) {
            return $m[1];
        }
        return null;
    }

    private function bump(array &$hash, string $key): void
    {
        if ($key === '') {
            return;
        }
        if (count($hash) >= self::MAX_KEYS && !isset($hash[$key])) {
            return;
        }
        $hash[$key] = ($hash[$key] ?? 0) + 1;
    }

    private function blankState(): array
    {
        return [
            'file' => null, 'offset' => 0,
            // info-level forwarded (upstream) view
            'total' => 0, 'cache_hits' => 0,
            'rcode' => [], 'qtype' => [], 'upstream' => [], 'client' => [],
            'qname' => [], 'hourly' => [],
            'latency_sum' => 0, 'latency_count' => 0, 'slow' => 0,
            // native observer view (kixdns >= 0.2.0 --debug)
            'observer' => 0,
            'requests' => 0, 'obs_cache_hit' => 0, 'obs_cache_miss' => 0,
            'e2e_sum_us' => 0, 'e2e_count' => 0, 'e2e_slow' => 0,
            'up_ok' => 0, 'up_fail' => 0, 'up_sum_us' => 0,
            'req_hourly' => [],
            'last_ts' => 0,
        ];
    }

    /**
     * Incremental aggregation over the newest log file.
     */
    private function aggregate(): array
    {
        $file = $this->newestLog();
        if ($file === null) {
            return $this->blankState();
        }

        $state = null;
        if (is_readable(self::CACHE)) {
            $raw = @file_get_contents(self::CACHE);
            if ($raw !== false) {
                $state = json_decode($raw, true);
            }
        }
        // restart parsing when the log rotated, the file shrank, or the cache
        // predates this schema
        if (!is_array($state) || ($state['file'] ?? null) !== $file
            || ($state['offset'] ?? 0) > filesize($file)
            || !array_key_exists('observer', $state)) {
            $state = $this->blankState();
        }
        $state['file'] = $file;

        $fh = @fopen($file, 'r');
        if ($fh === false) {
            return $state;
        }
        fseek($fh, (int)$state['offset']);
        $partial = '';
        while (($line = fgets($fh)) !== false) {
            $partial .= $line;
            if (substr($partial, -1) !== "\n") {
                continue;   // torn trailing line: wait for the rest
            }
            $line = $partial;
            $partial = '';

            if (strpos($line, 'event=') === false) {
                continue;
            }
            $t = $this->tokens($line);
            $event = $t['event'] ?? '';

            switch ($event) {
                case 'request_started':
                    $state['observer']++;
                    $state['requests']++;
                    if (isset($t['qname'])) {
                        $this->bump($state['qname'], $t['qname']);
                    }
                    if (isset($t['qtype'])) {
                        $this->bump($state['qtype'], $t['qtype']);
                    }
                    if (isset($t['client'])) {
                        $client = preg_replace('/:\d+$/', '', ltrim($t['client'], '['));
                        $this->bump($state['client'], (string)$client);
                    }
                    $hour = $this->hourOf($line);
                    if ($hour !== null) {
                        $state['req_hourly'][$hour] = ($state['req_hourly'][$hour] ?? 0) + 1;
                    }
                    break;

                case 'cache_hit':
                    $state['observer']++;
                    $state['obs_cache_hit']++;
                    break;

                case 'cache_miss':
                    $state['observer']++;
                    $state['obs_cache_miss']++;
                    break;

                case 'request_finished':
                    $state['observer']++;
                    if (isset($t['latency_us']) && ctype_digit($t['latency_us'])) {
                        $us = (int)$t['latency_us'];
                        $state['e2e_sum_us'] += $us;
                        $state['e2e_count']++;
                        if ($us >= 500000) {
                            $state['e2e_slow']++;
                        }
                    }
                    break;

                case 'upstream_result':
                    $state['observer']++;
                    $outcome = strtolower($t['outcome'] ?? '');
                    if ($outcome === 'success') {
                        $state['up_ok']++;
                    } else {
                        $state['up_fail']++;
                    }
                    if (isset($t['latency_us']) && ctype_digit($t['latency_us'])) {
                        $state['up_sum_us'] += (int)$t['latency_us'];
                    }
                    if (isset($t['upstream'])) {
                        $this->bump($state['upstream'], $t['upstream']);
                    }
                    break;

                case 'dns_response':
                    $state['total']++;
                    if (($t['cache'] ?? '') === 'true') {
                        $state['cache_hits']++;
                    }
                    if (isset($t['latency_ms']) && ctype_digit($t['latency_ms'])) {
                        $ms = (int)$t['latency_ms'];
                        $state['latency_sum'] += $ms;
                        $state['latency_count']++;
                        if ($ms >= 500) {
                            $state['slow']++;
                        }
                    }
                    if (isset($t['rcode'])) {
                        $this->bump($state['rcode'], $t['rcode']);
                    }
                    if (isset($t['qname'])) {
                        $this->bump($state['qname'], $t['qname']);
                    }
                    if (isset($t['qtype'])) {
                        $this->bump($state['qtype'], $t['qtype']);
                    }
                    if (isset($t['client_ip'])) {
                        $this->bump($state['client'], $t['client_ip']);
                    }
                    if (isset($t['upstream'])) {
                        $this->bump($state['upstream'], $t['upstream']);
                    }
                    $hour = $this->hourOf($line);
                    if ($hour !== null) {
                        $state['hourly'][$hour] = ($state['hourly'][$hour] ?? 0) + 1;
                    }
                    break;

                default:
                    break;
            }
        }
        $state['offset'] = ftell($fh);
        fclose($fh);
        $state['last_ts'] = time();

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
                    if (($state['file'] ?? null) === $this->newestLog()) {
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
        $requests = (int)$s['requests'];
        $hits = (int)$s['obs_cache_hit'];
        $misses = (int)$s['obs_cache_miss'];
        $lookups = $hits + $misses;
        $observer = ($requests > 0 || $lookups > 0);

        $hourSrc = $observer ? $s['req_hourly'] : $s['hourly'];
        $hist = [];
        for ($h = 0; $h < 24; $h++) {
            $key = sprintf('%02d', $h);
            $hist[$key] = (int)($hourSrc[$key] ?? 0);
        }
        $rcodes = $s['rcode'];
        arsort($rcodes);

        return [
            // native observer view (kixdns >= 0.2.0 with --debug)
            'observer' => $observer,
            'requests' => $requests,
            'cache_hits' => $hits,
            'cache_misses' => $misses,
            'cache_hit_ratio' => $lookups > 0 ? round(100.0 * $hits / $lookups, 1) : null,
            'e2e_avg_latency' => $s['e2e_count'] > 0
                ? round($s['e2e_sum_us'] / $s['e2e_count'] / 1000.0, 2) : null,
            'e2e_slow' => (int)$s['e2e_slow'],
            'upstream_ok' => (int)$s['up_ok'],
            'upstream_fail' => (int)$s['up_fail'],
            'upstream_avg_latency' => ($s['up_ok'] + $s['up_fail']) > 0
                ? round($s['up_sum_us'] / ($s['up_ok'] + $s['up_fail']) / 1000.0, 2) : null,

            // forwarded (upstream) view, available without --debug
            'forwarded_total' => $total,
            'forwarded_cached' => (int)$s['cache_hits'],
            'avg_latency' => $s['latency_count'] > 0
                ? round($s['latency_sum'] / $s['latency_count'], 1) : 0.0,
            'slow_queries' => (int)$s['slow'],

            // shared
            'unique_domains' => count($s['qname']),
            'unique_domains_limit' => self::MAX_KEYS,
            'rcode' => $rcodes,
            'hourly' => $hist,
            'qtype' => $this->top($s['qtype']),
            'upstreams' => $this->top($s['upstream']),
            'top_domains' => $this->top($s['qname']),
            'top_clients' => $this->top($s['client']),
            'log_file' => $s['file'],
            'log_scope' => $observer ? 'observer' : 'forwarded',
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
            $nl = strpos($data, "\n");
            $data = $nl === false ? '' : substr($data, $nl + 1);
        }

        $rows = [];
        foreach (array_reverse(explode("\n", $data)) as $line) {
            if ($limit <= 0) {
                break;
            }
            if (strpos($line, 'event="dns_response"') === false) {
                continue;
            }
            $t = $this->tokens($line);
            if (!isset($t['qname'])) {
                continue;
            }
            if ($fq !== '' && stripos($t['qname'], $fq) === false) {
                continue;
            }
            $client = $t['client_ip'] ?? '';
            if ($fc !== '' && stripos($client, $fc) === false) {
                continue;
            }
            $rows[] = [
                'qname' => $t['qname'],
                'qtype' => $t['qtype'] ?? '',
                'rcode' => $t['rcode'] ?? '',
                'latency_ms' => $t['latency_ms'] ?? '',
                'client_ip' => $client,
                'upstream' => $t['upstream'] ?? '',
                'pipeline' => $t['pipeline'] ?? '',
                'cache' => ($t['cache'] ?? '') === 'true' ? 'true' : 'false',
                'time' => preg_match('/T(\d{2}:\d{2}:\d{2})/', $line, $m) ? $m[1] : '',
            ];
            $limit--;
        }

        return [
            'rows' => $rows,
            'log_file' => $file,
            'count' => count($rows),
        ];
    }

    /**
     * GET /api/kixdns/stats/service
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
        $debug = false;
        $rc = @file_get_contents('/etc/rc.conf.d/kixdns');
        if (is_string($rc) && preg_match('/kixdns_debug="([^"]*)"/', $rc, $m)) {
            $debug = in_array(strtoupper($m[1]), ['YES', '1', 'TRUE', 'ON'], true);
        }
        $ipt = @shell_exec('/sbin/iptables -w 64 -t nat -L KIXDNS_REDIRECT -n 2>/dev/null | grep -c REDIRECT');
        $rules = (int)trim((string)$ipt);

        // takeover mode: binding :53 directly needs no NAT rule
        $direct = (substr($bind, -3) === ':53');
        $mode = $direct ? 'direct' : ($rules > 0 ? 'redirect' : 'none');

        return [
            'running' => $running,
            'pid' => $pid,
            'version' => $version,
            'bind' => $bind,
            'mode' => $mode,
            'debug' => $debug,
            'iptables_rules' => $rules,
        ];
    }
}
