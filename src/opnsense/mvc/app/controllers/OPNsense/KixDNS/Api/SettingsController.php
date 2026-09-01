<?php

namespace OPNsense\KixDNS\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Base\UserException;
use OPNsense\Core\Config;

/**
 * Settings controller for KixDNS.
 *
 * getAction()/setAction() are inherited from ApiMutableModelControllerBase:
 * they expose the whole model under the "kixdns" key, which is why every form
 * field id in forms/general.xml is prefixed with "kixdns.".
 *
 * The previous overrides called getBase('general', 'general') and
 * setBase('general', 'general'):
 *   - getBase() with $uuid = null ends in $model->general->Add(), but Add()
 *     only exists on ArrayField, so GET /api/kixdns/settings/get raised
 *     "Call to undefined method ...ContainerField::Add()" (HTTP 500) and the
 *     form stayed empty.
 *   - setBase($post_field, $path, $uuid) requires three arguments, so
 *     POST /api/kixdns/settings/set raised an ArgumentCountError (HTTP 500).
 *
 * Only the Pipeline Editor blob needs custom endpoints, they are kept below.
 */
class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'kixdns';
    protected static $internalModelClass = 'OPNsense\KixDNS\KixDNS';

    /**
     * Return the raw pipeline JSON for the Pipeline Editor.
     */
    public function getConfigJsonAction()
    {
        $result = ['config_json' => ''];

        if ($this->request->isGet()) {
            $result['config_json'] = (string)$this->getModel()->general->config_json;
        }

        return $result;
    }

    /**
     * Store the raw pipeline JSON coming from the Pipeline Editor.
     */
    public function saveConfigJsonAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => gettext('POST request required.')];
        }

        $jsonStr = $this->request->getPost('config_json');
        if (!is_string($jsonStr) || trim($jsonStr) === '') {
            return ['result' => 'failed', 'message' => gettext('Missing or empty config_json parameter.')];
        }

        $decoded = json_decode($jsonStr, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['result' => 'failed', 'message' => sprintf(
                gettext('Invalid JSON: %s'),
                json_last_error_msg()
            )];
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            return ['result' => 'failed', 'message' => gettext('The configuration must be a JSON object.')];
        }
        foreach (['settings', 'pipelines'] as $required) {
            if (!array_key_exists($required, $decoded)) {
                return ['result' => 'failed', 'message' => sprintf(
                    gettext('Missing required top level key "%s".'),
                    $required
                )];
            }
        }

        try {
            Config::getInstance()->lock();
            $mdl = $this->getModel();
            $mdl->general->config_json = $jsonStr;
            /* validation is field based, the JSON blob is validated above */
            return $this->save(false, true);
        } catch (UserException $e) {
            return ['result' => 'failed', 'message' => $e->getMessage()];
        }
    }
}
