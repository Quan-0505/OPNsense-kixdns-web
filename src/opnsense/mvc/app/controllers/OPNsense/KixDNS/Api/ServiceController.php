<?php

namespace OPNsense\KixDNS\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

/**
 * Service controller for KixDNS.
 *
 * Service actions delegate to ApiMutableServiceControllerBase, with an extra
 * post-start check after reconfigure. The previous custom reconfigureAction()
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
     * daemon(8) can return success before the child binds its sockets. A busy
     * port or invalid pipeline then leaves the API reporting a successful Apply
     * although kixdns has already exited. Check the supervised process across
     * a short startup window, in addition to the framework's configd result.
     */
    public function reconfigureAction()
    {
        $result = parent::reconfigureAction();
        if (!$this->request->isPost() || ($result['status'] ?? '') !== 'ok' || !$this->serviceEnabled()) {
            return $result;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            usleep(200000);
            if (($this->statusAction()['status'] ?? '') !== 'running') {
                $result['status'] = 'failed';
                $result['status_msg'] = gettext(
                    'KixDNS exited after startup. Check Services - KixDNS - Log File for an occupied listening port or invalid configuration.'
                );
                break;
            }
        }

        return $result;
    }

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
