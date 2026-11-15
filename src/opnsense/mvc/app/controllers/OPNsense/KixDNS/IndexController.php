<?php

namespace OPNsense\KixDNS;

class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->pick('OPNsense/KixDNS/index');
        $this->view->generalForm = $this->getForm('general');
    }
}
