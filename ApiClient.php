<?php

namespace futuretek\api;

use futuretek\shared\Tools;
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
    private $_serverHost;
    private $_curl;

    function __construct()
    {
        $this->_curl = curl_init();
        $this->_setCurlOpt();
    }

    /**
     * Set server host
     *
     * @param string $serverHostUrl Server host URL
     *
     * @return void
     */
    public function setServerHostUrl($serverHostUrl)
    {
        $this->_serverHost = $serverHostUrl;
    }

    /**
     * Get API URL part
     *
     * @return string API URL part
     */
    abstract public function getApiUrl();

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
     * @param array  $params Method input parameters
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

        $response = false;
        if (is_array($this->_serverUrl)) {
            foreach ($this->_serverUrl as $url) {
                $response = $this->_innerSend(rtrim($url, '/') . $this->getApiUrl() . $method, $request);
                if ($response) {
                    break;
                }
            }
        } else {
            $response = $this->_innerSend(rtrim($this->_serverUrl, '/') . $this->getApiUrl() . $method, $request);
        }

        if (!$response) {
            return false;
        }

        $response = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return $response;
    }

    private function _innerSend($url, $request)
    {
        curl_setopt($this->_curl, CURLOPT_URL, $url);
        curl_setopt($this->_curl, CURLOPT_POSTFIELDS, $request);

        return curl_exec($this->_curl);
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
        curl_setopt($this->_curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($this->_curl, CURLOPT_SSL_VERIFYPEER, false);
    }

    protected function apiCallEnumerator($class, $function, $arguments)
    {
        $inputParams = [];
        $i = 0;
        foreach ((new \ReflectionMethod($class, $function))->getParameters() as $param) {
            $paramName = $param->getName();
            $inputParams[$paramName] = $arguments[$i];
            $i++;
            if ($i == count($arguments)) {
                break;
            }
        }
        $response = $this->send(Tools::toCommaCase($function), $inputParams);

        if (!$response) {
            return null;
        };

        $namespace = (new \ReflectionClass($class))->getNamespaceName();

        return (new \ReflectionClass($namespace . '\\' . $function . 'Result'))->newInstanceArgs([$response]);
    }

}
