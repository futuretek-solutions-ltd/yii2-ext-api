<?php

namespace futuretek\api;

use futuretek\shared\Tools;
use Yii;
use yii\helpers\Url;
use yii\web\Application;

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
    private $_serverHost;
    private $_curl;
    /**
     * @var bool Exception mode. If set to true, all REMOTE API errors will be thrown as exceptions.
     */
    public $exceptionMode = false;

    /**
     * ApiClient constructor.
     *
     * @param bool $exceptionMode Exception mode. If set to true, all REMOTE API errors will be thrown as exceptions.
     */
    public function __construct($exceptionMode = false)
    {
        $this->exceptionMode = $exceptionMode;
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
    abstract protected function authorize();

    /**
     * Send API request
     *
     * @param string $method Method name in format (method-name)
     * @param array $params Method input parameters
     *
     * @return array Method API response
     * @throws \futuretek\api\ApiException
     */
    public function send($method, array $params)
    {
        if (!$this->_serverHost) {
            throw new ApiException(Yii::t('fts-yii2-api', 'API URL not set.'));
        }

        $auth = $this->authorize();

        if (is_array($auth)) {
            $params = array_merge($params, $auth);
        }

        $request = json_encode($params);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ApiException(Yii::t('fts-yii2-api', 'Error while encoding request to JSON.'));
        }

        $response = false;
        if (is_array($this->_serverHost)) {
            foreach ($this->_serverHost as $url) {
                $response = $this->_innerSend(rtrim($url, '/') . $this->getApiUrl() . $method, $request);
                if ($response) {
                    break;
                }
            }
        } else {
            $response = $this->_innerSend(rtrim($this->_serverHost, '/') . $this->getApiUrl() . $method, $request);
        }

        if (!$response) {
            throw new ApiException(Yii::t('fts-yii2-api', 'Remote API error.'));
        }

        $response = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ApiException(Yii::t('fts-yii2-api', 'Error while decoding response from JSON.'));
        }

        if ($this->exceptionMode && $response['hasErrors'] && 0 !== count($response['errors'])) {
            throw new ApiException($response['errors'][0]['message'], 0, null, $response['errors'][0]['file'], $response['errors'][0]['line']);
        }

        return $response;
    }

    /**
     * Inner method for sending API call
     *
     * @param $url
     * @param $request
     * @return mixed
     */
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
     * @throws \futuretek\api\ApiException
     */
    public function ping()
    {
        $response = $this->send('ping', []);

        return (is_array($response) && array_key_exists('message', $response) && $response['message'] === 'pong');
    }

    /**
     * Set CURL options
     */
    private function _setCurlOpt()
    {
        curl_setopt($this->_curl, CURLOPT_POST, true);
        curl_setopt($this->_curl, CURLOPT_USERAGENT, 'FTS-API-Client');
        curl_setopt($this->_curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->_curl, CURLOPT_MAXREDIRS, 10);
        curl_setopt($this->_curl, CURLOPT_REFERER, (Yii::$app instanceof Application ? Url::base() : ''));
        curl_setopt($this->_curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json; charset=utf-8']);
        curl_setopt($this->_curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($this->_curl, CURLOPT_SSL_VERIFYPEER, false);
    }

    /**
     * Wrap API call
     *
     * @param mixed $class Class name
     * @param string $function Function name
     * @param array $arguments Function arguments
     * @return ApiResult
     * @throws \futuretek\api\ApiException
     */
    protected function apiCallEnumerator($class, $function, $arguments)
    {
        $inputParams = [];
        $i = 0;
        foreach ((new \ReflectionMethod($class, $function))->getParameters() as $param) {
            $paramName = $param->getName();
            $inputParams[$paramName] = $arguments[$i];
            $i++;
            if ($i === count($arguments)) {
                break;
            }
        }
        $response = $this->send(Tools::toCommaCase($function), $inputParams);
        $namespace = (new \ReflectionClass($class))->getNamespaceName();
        /** @var ApiResult $newInstance */
        $newInstance = (new \ReflectionClass($namespace . '\\' . $function . 'Result'))->newInstanceArgs([$response]);

        return $newInstance;
    }
}
