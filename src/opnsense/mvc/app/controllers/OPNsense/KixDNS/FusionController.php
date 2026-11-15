<?php

namespace OPNsense\KixDNS;

/**
 * DNS x DPI fusion page (Services -> KixDNS -> DNS x 应用识别).
 *
 * A page in OPNsense is addressed as /ui/<namespace>/<controller>, which is why
 * this has to be its own controller and not an indexAction: /ui/kixdns/fusion
 * is parsed into namespace=KixDNS, controller=FusionController, so the data API
 * lives next door in OPNsense\KixDNS\Api\FusionController.
 */
class FusionController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->pick('OPNsense/KixDNS/fusion');
    }
}
