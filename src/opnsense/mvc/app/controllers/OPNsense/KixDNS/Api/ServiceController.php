<?php

namespace OPNsense\KixDNS\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

/**
 * Service controller for KixDNS.
 *
 * start / stop / restart / status / reconfigure are fully implemented by
 * ApiMutableServiceControllerBase. The previous custom reconfigureAction()
 * called $this->sessionClose() (a method that does not exist in OPNsense
 * 24.7 - 26.x, so every Apply ended in a HTTP 500) and tried to write
 * /etc/rc.conf.d/kixdns as user www (never permitted). Both jobs are now
 * done by the base class plus the configd template.
 */
class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass = '\OPNsense\KixDNS\KixDNS';
    protected static $internalServiceTemplate = 'OPNsense/KixDNS';
    protected static $internalServiceEnabled = 'general.enabled';
    protected static $internalServiceName = 'kixdns';

    /**
     * Report the version of the installed kixdns binary.
     * Useful to tell "binary missing / wrong architecture" apart from
     * "binary fine but configuration rejected" without shell access.
     */
    public function versionAction()
    {
        $backend = new Backend();
        $response = trim($backend->configdRun('kixdns version'));

        return ['version' => $response];
    }
}
