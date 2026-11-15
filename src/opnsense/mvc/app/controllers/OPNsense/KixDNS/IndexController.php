<?php

namespace OPNsense\KixDNS;

class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->pick('OPNsense/KixDNS/index');
        $this->view->generalForm = $this->getForm('general');
    }

    /**
     * DNS x DPI fusion page: joins this plugin's query log with the appdpi
     * engine snapshot so one screen shows who asked what *and* what those
     * bytes belonged to. Read-only; see Api/FusionController.php.
     */
    public function fusionAction()
    {
        $this->view->pick('OPNsense/KixDNS/fusion');
    }
}
