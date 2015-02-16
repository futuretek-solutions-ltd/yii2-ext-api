<?php

namespace futuretek\api;

use Yii;
use yii\helpers\Url;

/**
 * Class ApiClient
 *
 * @package futuretek\api
 * @author  Lukas Cerny <lukas.cerny@futuretek.cz>
 * @license http://www.futuretek.cz/license FTSLv1
 * @link    http://www.futuretek.cz
 */
abstract class ApiClient
{
    private $_serverUrl;
    private $_curl;

    function __construct()
    {
        $this->_curl = curl_init();
        $this->_setCurlOpt();
    }

    /**
     * Set server URL without the method name
     *
     * @param string $serverUrl Server URL
     *
     * @return void
     */
    public function setServerUrl($serverUrl)
    {
        $this->_serverUrl = $serverUrl;
    }

    /**
     * Authorize method
     *
     * @return array Array of input variables uses to authorize against the API
     */
    abstract function authorize();

    /**
     * Send API request
     *
     * @param string $method Method name in format (method-name)
     * @param array $params Method input parameters
     *
     * @return bool|mixed Method API response or boolean false on error
     */
    public function send($method, array $params)
    {
        if (!$this->_serverUrl) {
            return false;
        }

        $auth = $this->authorize();

        if (is_array($auth)) {
            $params = array_merge($params, $auth);
        }

        $request = json_encode($params);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        curl_setopt($this->_curl, CURLOPT_POSTFIELDS, $request);
        curl_setopt($this->_curl, CURLOPT_URL, rtrim($this->_serverUrl, '/') . '/' . $method);
        $response = curl_exec($this->_curl);

        if (!$response) {
            return false;
        }

        $response = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return $response;
    }

    /**
     * Test API
     *
     * @return bool If API is OK
     */
    public function ping()
    {
        $response = $this->send('ping', []);

        return ($response && array_key_exists('message', $response) && $response['message'] == 'pong');
    }

    private function _setCurlOpt()
    {
        curl_setopt($this->_curl, CURLOPT_POST, true);
        curl_setopt($this->_curl, CURLOPT_USERAGENT, 'FTS-API-Client');
        curl_setopt($this->_curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->_curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($this->_curl, CURLOPT_MAXREDIRS, 10);
        curl_setopt($this->_curl, CURLOPT_REFERER, Url::base());
        curl_setopt($this->_curl, CURLOPT_HTTPHEADER, ["Content-Type: application/json; charset=utf-8"]);
    }

}
