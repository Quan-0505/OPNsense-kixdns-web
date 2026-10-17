<?php

/* No OPNsense bootstrap, config, backend commands or service mutations. */
namespace OPNsense\Base {
    class ApiMutableServiceControllerBase
    {
        public static $result = ['status' => 'ok'];
        public static $enabled = true;
        public $request;

        public function reconfigureAction()
        {
            return static::$result;
        }

        protected function serviceEnabled()
        {
            return static::$enabled;
        }
    }
}

namespace OPNsense\KixDNS\Api {
    // Keep the standalone regressions fast; real API tests use actual delays.
    function usleep($delay)
    {
    }

    function gettext($text)
    {
        return $text;
    }
}

namespace {
    require __DIR__ . '/../src/opnsense/mvc/app/controllers/OPNsense/KixDNS/Api/ServiceController.php';

    class TestService extends \OPNsense\KixDNS\Api\ServiceController
    {
        public $statuses = [];
        public $checks = 0;

        public function statusAction()
        {
            $this->checks++;
            return ['status' => array_shift($this->statuses) ?? 'running'];
        }
    }

    $cases = [
        ['healthy startup', true, true, ['status' => 'ok'], [], 'ok', 5],
        ['child exits before first poll', true, true, ['status' => 'ok'], ['stopped'], 'failed', 1],
        ['child exits during startup', true, true, ['status' => 'ok'], ['running', 'running', 'stopped'], 'failed', 3],
        ['disabled service stays disabled', true, false, ['status' => 'ok'], ['disabled'], 'ok', 0],
        ['framework failure preserved', true, true, ['status' => 'failed', 'status_msg' => 'original backend error'], [], 'failed', 0],
        ['GET never polls a service', false, true, ['status' => 'failed'], [], 'failed', 0],
    ];
    foreach ($cases as [$name, $post, $enabled, $parent, $states, $expected, $checks]) {
        TestService::$result = $parent;
        TestService::$enabled = $enabled;
        $service = new TestService();
        $service->request = new class ($post) {
            private $post;
            public function __construct($post) { $this->post = $post; }
            public function isPost() { return $this->post; }
        };
        $service->statuses = $states;
        $result = $service->reconfigureAction();
        if (($result['status'] ?? null) !== $expected || $service->checks !== $checks) {
            throw new \RuntimeException($name . ': unexpected result/check count');
        }
        if ($checks > 0 && $expected === 'failed' && empty($result['status_msg'])) {
            throw new \RuntimeException($name . ': missing failure detail');
        }
        if (isset($parent['status_msg']) && $result['status_msg'] !== $parent['status_msg']) {
            throw new \RuntimeException($name . ': original failure detail lost');
        }
        echo 'PASS ' . $name . "\n";
    }
}
