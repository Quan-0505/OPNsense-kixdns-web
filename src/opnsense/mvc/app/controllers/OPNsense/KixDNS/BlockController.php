<?php

/*
 * KixDNS: application blocking at the DNS layer.
 *
 * Moved out of the fused page on request: blocking is a write action with its own
 * blast radius (every name suffix of an app goes NXDOMAIN), so it gets its own
 * menu entry rather than sitting under a read-only dashboard.
 */

namespace OPNsense\KixDNS;

class BlockController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->pick('OPNsense/KixDNS/block');
    }
}
