<?php

namespace futuretek\api;

use futuretek\shared\Tools;
use futuretek\shared\Validate;
use Yii;
use yii\base\Action;
use yii\base\ErrorException;
use yii\base\InlineAction;
use yii\base\InvalidParamException;
use yii\base\InvalidRouteException;
use yii\base\Module;
use yii\helpers\Json;
use yii\log\Logger;
use yii\web\Controller;

/**
 * Class ApiController
 *
 * @package futuretek\api
 * @author  Lukas Cerny <lukas.cerny@futuretek.cz>
 * @license http://www.futuretek.cz/license FTSLv1
 * @link    http://www.futuretek.cz
 */
abstract class ApiController extends Controller
{
    /**
     * Cache time to live (in seconds)
     */
    const CACHE_TTL = 3600;

    /**
     * @var array Methods that will be ignored by API checking routine (utility methods)
     */
    private $_apiIgnoreMethods = ['handle-error', 'generate-confluence-documentation'];

    /**
     * @var array Errors
     */
    private $_errors = [];

    /**
     * @var array Input variables
     */
    private $_inputVars = [];

    /**
     * @var array Current method reflection info
     */
    private $_methodInfo;

    /**
     * @var bool Force connection over SSL
     */
    public $forceSecureConnection = true;

    /**
     * @var bool CSRF validation - must be always false
     */
    public $enableCsrfValidation = false;

    /**
     * @var bool Use stateless API. With stateless API client must authenticate on each request (default: yes).
     */
    public $statelessApi = true;

    /**
     * @var bool Identity mode.
     *           <ul>
     *           <li>If enabled, you are responsible for login using custom identity.</li>
     *           <li>If disabled, authorization is done using checkAuth method's return value.<br/>
     *           WARNING: Permissions are not available in this mode!</li>
     *           </ul>
     *           Default: yes.
     */
    public $identityMode = true;

    /**
     * Init method
     *
     * @return void
     */
    public function init()
    {
        parent::init();

        //Set user object
        Yii::$app->user->enableSession = !$this->statelessApi;
        Yii::$app->user->loginUrl = null;

        $this->defaultAction = 'generate-definition';

        Yii::$app->errorHandler->errorAction = $this->uniqueId . '/handle-error';
    }

    /**
     * Set error message and stop executing request
     *
     * @param string $message Error message
     * @param string $code    Error code
     *
     * @return void
     */
    public function setError($message, $code = 'UNKNOWN')
    {
        $this->_errors[] = ['message' => $message, 'code' => $code];
        $this->renderResponse();
    }

    /**
     * Set error messages from model and stop executing request
     *
     * @param array $modelErrors Model errors from model's firstErrors property
     *
     * @return void
     */
    public function setModelErrors(array $modelErrors)
    {
        foreach ($modelErrors as $field => $message) {
            $this->_errors[] = ['message' => $message, 'code' => 'MODEL_VALIDATION'];
        }
        $this->renderResponse();
    }

    /**
     * Set error message and continue executing request. Error will be still returned in the response
     *
     * @param string $message Error message
     * @param string $code    Error code
     *
     * @return void
     */
    public function setWarning($message, $code = 'UNKNOWN')
    {
        $this->_errors[] = ['message' => $message, 'code' => $code];
    }

    /**
     * Check if there were some errors while executing API request
     *
     * @return bool Has been errors detected
     */
    public function hasErrors()
    {
        return !empty($this->_errors);
    }

    /**
     * Method is called before API authorization
     *
     * @param bool   $stateLessApi If the API is stateless
     * @param Action $action       Action object
     * @param array  $inputVars    Input variables
     *
     * @return bool Whether API is authorized
     */
    public function checkAuth($stateLessApi, $action, $inputVars)
    {
        return true;
    }

    /**
     * Event ran before every action
     *
     * @param Action $action Action object
     *
     * @return bool Continue with action execution
     */
    public function beforeAction($action)
    {
        if (in_array($action->id, $this->_apiIgnoreMethods)) {
            return true;
        }

        //Force SSL check
        if ($this->forceSecureConnection and !Yii::$app->request->isSecureConnection) {
            $this->setError(Yii::t('api', 'Request is not secure (over HTTPS)'), 'REQUEST_NOT_SECURE');
        }

        //Request method check
        Yii::$app->request->isPost or $this->setError(Yii::t('api', 'Request method must be POST'), 'REQUEST_NOT_POST');

        if ($action instanceof InlineAction) {
            //Inline action
            $className = $this;
            $classMethod = $action->actionMethod;
        } elseif ($action instanceof Action) {
            //External action
            $action->hasMethod('run') or $this->setError(Yii::t('api', 'Run method is not defined for this action'), 'NO_RUN_METHOD');
            $className = $action->className();
            $classMethod = 'run';
        } else {
            //Wrong class parent
            $className = '';
            $classMethod = '';
            $this->setError(Yii::t('api', 'Method is not descendant of \base\Action'), 'METHOD_WRONG_PARENT');
        }

        //Get input from POST body
        $this->_parseInput();

        $reflection = new \ReflectionMethod($className, $classMethod);
        $reflection->isPublic() or $this->setError(Yii::t('api', 'Method is not public'), 'METHOD_NOT_PUBLIC');

        //Examine method
        $this->_methodInfo = $this->_examineMethod($reflection);
        if (!$this->_methodInfo) {
            $this->setError(Yii::t('api', 'Method has no phpDoc comment'), 'NO_PHPDOC');
        }

        //API tag
        if (!$this->_methodInfo['api']) {
            $this->setError(Yii::t('api', 'Method is not intended for use via API'), 'NOT_API_ENABLED');
        }

        //Auth
        if (!$this->_methodInfo['no-auth']) {
            $authorized = $this->checkAuth($this->statelessApi, $action, $this->_inputVars);

            //Login
            if ((Yii::$app->user->isGuest && $this->identityMode) || (!$this->identityMode && !$authorized)) {
                $this->setError(Yii::t('api', 'User is not logged in'), 'NOT_LOGGED_IN');
            }

            //Permission
            if ($this->_methodInfo['permission'] && !$this->identityMode) {
                if (!Yii::$app->user->can($this->_methodInfo['permission'])) {
                    $this->setError(Yii::t('api', 'You have not permission to run this action'), 'ACCESS_DENIED');
                }
            }
        }

        //Validate input params
        foreach ($this->_methodInfo['params'] as $paramName => $param) {
            if (isset($this->_inputVars[$paramName])) { //Input param exists
                if (isset($param['validate']) && $param['validate']) { //Validator is set
                    if (method_exists('\futuretek\shared\Validate', $param['validate'])) { // Validator method exists
                        $validator = $param['validate'];
                        if (Validate::$validator($this->_inputVars[$paramName])) { //Input param valid
                            $this->actionParams[$paramName] = $this->_inputVars[$paramName];
                        } else {
                            $this->setError(
                                Yii::t(
                                    'api',
                                    'Parameter {param} is not valid (validator {validator})',
                                    ['param' => $paramName, 'validator' => $param['validate']]
                                ),
                                'PARAM_NOT_VALID'
                            );
                        }
                    } else {
                        $this->setError(
                            Yii::t('api', 'Validator {validator} method not found', ['validator' => $param['validate']]),
                            'VALIDATOR_NOT_FOUND'
                        );
                    }
                } else {
                    $this->actionParams[$paramName] = $this->_inputVars[$paramName];
                }
            } else {
                if ($param['required']) {
                    $this->setError(Yii::t('api', 'Input parameter {param} not found', ['param' => $paramName]), 'PARAM_NOT_FOUND');
                }
            }
        }

        //Transaction
        if ($this->_methodInfo['transaction'] && Yii::$app->db->transaction === null) {
            Yii::$app->db->beginTransaction();
        }

        return true;
    }

    /**
     * Runs an action within this controller with the specified action ID and parameters.
     * If the action ID is empty, the method will use [[defaultAction]].
     *
     * @param string $id     the ID of the action to be executed.
     * @param array  $params the parameters (name-value pairs) to be passed to the action.
     *
     * @return mixed the result of the action.
     * @throws InvalidRouteException if the requested action ID cannot be resolved into an action successfully.
     * @see createAction()
     */
    public function runAction($id, $params = [])
    {
        $action = $this->createAction($id);
        if ($action === null) {
            throw new InvalidRouteException('Unable to resolve the request: ' . $this->getUniqueId() . '/' . $id);
        }

        Yii::trace("Route to run: " . $action->getUniqueId(), __METHOD__);

        if (Yii::$app->requestedAction === null) {
            Yii::$app->requestedAction = $action;
        }

        $oldAction = $this->action;
        $this->action = $action;

        $modules = [];
        $runAction = true;

        // call beforeAction on modules
        foreach ($this->getModules() as $module) {
            if ($module->beforeAction($action)) {
                array_unshift($modules, $module);
            } else {
                $runAction = false;
                break;
            }
        }

        $result = null;

        if ($runAction && $this->beforeAction($action)) {
            // run the action
            $result = $action->runWithParams(array_merge($params, $this->actionParams));

            $result = $this->afterAction($action, $result);

            // call afterAction on modules
            foreach ($modules as $module) {
                /* @var $module Module */
                $result = $module->afterAction($action, $result);
            }
        }

        $this->action = $oldAction;

        return $result;
    }

    /**
     * Event ran after every action
     *
     * @param Action $action Action object
     * @param mixed  $result Action result
     *
     * @return mixed
     */
    public function afterAction($action, $result)
    {
        if (in_array($action->id, $this->_apiIgnoreMethods)) {
            return;
        }

        $type = gettype($result);

        //Replace uncommon types
        switch ($this->_methodInfo['return']['type']) {
        case 'bool':
            $this->_methodInfo['return']['type'] = 'boolean';
            break;
        case 'int':
            $this->_methodInfo['return']['type'] = 'integer';
            break;
        }

        //Method return type vs. phpDoc return type
        if (isset($this->_methodInfo['return']['type']) and $this->_methodInfo['return']['type'] != $type) {
            $this->setError(
                Yii::t(
                    'api',
                    'Method return type ({t1}) differs from API return type ({t2})',
                    ['t1' => $type, 't2' => $this->_methodInfo['return']['type']]
                ),
                'RETURN_TYPE_MISMATCH'
            );
        }

        //Method return type check
        switch ($type) {
        case 'integer':
        case 'boolean':
            $result = boolval($result);
            break;
        case 'double':
            $result = boolval(floor($result));
            break;
        case 'string':
            $result = boolval(intval($result));
            break;
        case 'array':
            break;
        default:
            $this->setError(Yii::t('api', 'Method return type ({type}) is not supported', ['type' => $type]), 'RETURN_TYPE_NOT_SUPPORTED');
        }

        //Return value check
        if (!$result) {
            $this->setError(Yii::t('api', 'Method returned boolean false'), 'RETURN_VALUE_FALSE');
        }

        //If not array, do not return anything
        if ($type != 'array') {
            $result = [];
        }

        //Transaction
        if ($this->_methodInfo['transaction'] && Yii::$app->db->transaction != null) {
            Yii::$app->db->transaction->commit();
        }

        $this->renderResponse($result);
    }

    /**
     * Reflect API method
     *
     * @param \ReflectionMethod $method Reflection method object
     *
     * @return array|bool Reflection info
     */
    private function _examineMethod($method)
    {
        $cache = Yii::$app->cache->get([$method->class, $method->getName(), 'info']);
        if ($cache && !YII_DEBUG) {
            return $cache;
        }

        $comment = $method->getDocComment();
        if (!$comment) {
            return false;
        }

        $result = [];
        $result['api'] = !(strpos($comment, '@api') === false);
        $result['no-auth'] = !(strpos($comment, '@no-auth') === false);
        $result['transaction'] = !(strpos($comment, '@transaction') === false);

        $comment = strtr($comment, ["\r\n" => "\n", "\r" => "\n"]);
        $comment = preg_replace('/^\s*\**(\s*?$|\s*)/m', '', $comment);

        $result['name'] = $method->getName();

        //Input params
        $result['params'] = [];
        $params = $method->getParameters();
        $n = preg_match_all('/^@param\s+([\w\.\\\]+(\[\s*\])?)\s*?(\$[\w\.\\\]+)\s*?(\S+.*?\S+)\s*?(\{.+\})?$/im', $comment, $matches);
        if ($n != count($params)) {
            $this->setError(
                Yii::t(
                    'api',
                    'Params count of the method {method} differs from phpDoc params count',
                    ['method' => $method->getShortName()]
                ),
                'PARAM_COUNT_MISMATCH'
            );
        }

        for ($i = 0; $i < $n; ++$i) {
            $type = preg_replace('/\\\\+/', '\\', $matches[1][$i]);

            if (!in_array('$' . $params[$i]->getName(), $matches[3])) {
                $this->setError(
                    Yii::t(
                        'api',
                        'Documented param {param} not found in the method {method} definition',
                        ['param' => $params[$i]->getName(), 'method' => $method->getShortName()]
                    ),
                    'PARAM_COUNT_MISMATCH'
                );
            }

            $result['params'][$params[$i]->getName()] = [
                'type' => $type,
                'description' => $matches[4][$i],
                'required' => !$params[$i]->isOptional(),
                'default' => ($params[$i]->isDefaultValueAvailable() ? $params[$i]->getDefaultValue() : null),
            ];

            if (isset($matches[5][$i])) {
                foreach (explode(',', trim($matches[5][$i], '{} ')) as $item) {
                    $tmp = explode('=', trim($item));
                    if (count($tmp) == 2) {
                        if (trim($tmp[0]) == 'element') {
                            $eTmp = explode('|', trim($tmp[1]));
                            if (isset($eTmp[1])) {
                                $result['params'][$params[$i]->getName()]['elements'][trim($eTmp[0])]['type'] = trim($eTmp[1]);
                            }
                            if (isset($eTmp[2])) {
                                $result['params'][$params[$i]->getName()]['elements'][trim($eTmp[0])]['description'] = trim($eTmp[2]);
                            }
                        } else {
                            $result['params'][$params[$i]->getName()][$tmp[0]] = $tmp[1];
                        }
                    }
                }
            }
        }

        //Return
        if (preg_match('/^@return\s+([\w\.\\\]+(\[\s*\])?)\s*?(\S.*)$/im', $comment, $matches)) {
            $type = preg_replace('/\\\\+/', '\\', $matches[1]);
            $result['return']['type'] = $type;
            $result['return']['description'] = $matches[3];
        }

        //Return-param
        $result['return-params'] = [];
        $n = preg_match_all('/^@return-param\s+([\w\.\\\]+(\[\s*\])?)\s*?([\w\.\\\]+)\s*?(\S+.*?\S+)\s*?$/im', $comment, $matches);
        for ($i = 0; $i < $n; ++$i) {
            $type = preg_replace('/\\\\+/', '\\', $matches[1][$i]);
            $result['return-params'][$matches[3][$i]] = [
                'type' => $type,
                'description' => $matches[4][$i],
            ];
        }

        //Permission
        if (preg_match('/^@permission\s+([\w]+)$/im', $comment, $matches)) {
            $result['permission'] = $matches[1];
        } else {
            $result['permission'] = false;
        }

        //Description
        if (preg_match('/^\/\*+\s*([^@]*?)\n@/s', $comment, $matches)) {
            $result['description'] = trim($matches[1]);
        } else {
            $result['description'] = false;
        }

        //Save to cache
        Yii::$app->cache->set([$method->class, $method->getName(), 'info'], $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Generate API methods definition list
     *
     * @return array
     * @api
     * @no-auth
     */
    public function actionGenerateDefinition()
    {
        $this->renderResponse(['methods' => $this->_innerGenerateAvailableActions()]);
    }

    /**
     * Generate available API actions list
     *
     * @return array Available API actions
     */
    private function _innerGenerateAvailableActions()
    {
        $methods = [];

        //External actions
        foreach ($this->actions() as $name => $action) {
            $reflection = new \ReflectionMethod($action, 'run');
            if ($reflection->isPublic()) {
                $info = $this->_examineMethod($reflection);
                if ($info['api']) {
                    $methods[$name] = $info;
                }
            }
        }

        //Inline actions
        $reflection = new \ReflectionClass($this);
        foreach ($reflection->getMethods() as $method) {
            if ($method->isPublic() and substr($method->getName(), 0, 6) == 'action') {
                $info = $this->_examineMethod($method);
                if ($info['api']) {
                    $name = Tools::toCommaCase(substr($method->getName(), 6));
                    $methods[$name] = $info;
                }
            }
        }

        ksort($methods);

        $result['methods'] = $methods;

        $reflection = new \ReflectionClass($this);
        $result['name'] = $reflection->getName();
        $result['shortname'] = strtr($reflection->getShortName(), ['Controller' => '']);
        $result['namespace'] = $reflection->getNamespaceName();
        $result['filename'] = $reflection->getFileName();
        $result['url'] = $this->getUniqueId();
        $comment = $reflection->getDocComment();
        $comment = strtr($comment, ["\r\n" => "\n", "\r" => "\n"]);
        $comment = preg_replace('/^\s*\**(\s*?$|\s*)/m', '', $comment);

        //Description
        if (preg_match('/^\/\*+\s*([^@]*?)\n@/s', $comment, $matches)) {
            $result['description'] = trim($matches[1]);
        } else {
            $result['description'] = false;
        }

        //Properties
        foreach ($reflection->getProperties() as $prop) {
            if (($prop->isPublic() or $prop->isStatic()) and (strpos($prop->class, 'yii\\') === false)) {
                $comment = $prop->getDocComment();
                $comment = strtr($comment, ["\r\n" => "\n", "\r" => "\n"]);
                $comment = preg_replace('/^\s*\**(\s*?$|\s*)/m', '', $comment);

                //Var
                if (preg_match('/^@var\s+([\w\.\\\]+(\[\s*\])?)\s*?(\S.*)$/im', $comment, $matches)) {
                    $type = preg_replace('/\\\\+/', '\\', $matches[1]);
                    $result['properties'][$prop->getName()]['type'] = $type;
                    $result['properties'][$prop->getName()]['description'] = $matches[3];
                    if (!$prop->isStatic()) {
                        $result['properties'][$prop->getName()]['value'] = $prop->getValue($this);
                    }
                }
            }
        }

        ksort($result['properties']);

        //Constants
        $result['constants'] = $reflection->getConstants();

        return $result;
    }

    /**
     * Ping action intended mainly to test the API interface availability
     *
     * @return array Test output
     * @return-element String message Reply message
     * @api
     * @no-auth
     */
    public function actionPing()
    {
        return ['message' => 'pong'];
    }

    /**
     * Parse input request from JSON to variables
     *
     * @return void
     */
    private function _parseInput()
    {
        $postBody = file_get_contents('php://input');

        if ($postBody) {
            try {
                $json = Json::decode($postBody);
                $this->_inputVars = $json;
            } catch (InvalidParamException $e) {
                $this->setError($e->getMessage(), 'JSON_ERROR');
            }
        }
    }

    /**
     * Render response
     *
     * @param array $response Response to render
     *
     * @return void
     */
    public function renderResponse($response = [])
    {
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header("Content-Type: application/json; charset=UTF-8");

        if ($this->hasErrors()) {
            $response = [];

            //Transaction rollback
            if (Yii::$app->db->transaction != null) {
                Yii::$app->db->transaction->rollBack();
            }
        }

        $response['hasErrors'] = $this->hasErrors();
        $response['errors'] = $this->_errors;

        echo Json::encode($response);

        Yii::$app->end();
    }

    /**
     * Handle all errors in this controller
     *
     * @return void
     */
    public function actionHandleError()
    {
        $exception = Yii::$app->errorHandler->exception;

        //Transaction
        if (Yii::$app->db->transaction != null) {
            Yii::$app->db->transaction->rollBack();
        }

        $category = Logger::LEVEL_ERROR;

        if ($exception instanceof ErrorException) {
            switch ($exception->getCode()) {
            case E_WARNING:
                $type = 'PHP warning';
                $category = Logger::LEVEL_WARNING;
                break;
            case E_NOTICE:
                $type = 'PHP notice';
                $category = Logger::LEVEL_WARNING;
                break;
            case E_USER_ERROR:
                $type = 'User error';
                break;
            case E_USER_WARNING:
                $type = 'User warning';
                $category = Logger::LEVEL_WARNING;
                break;
            case E_USER_NOTICE:
                $type = 'User notice';
                $category = Logger::LEVEL_WARNING;
                break;
            case E_RECOVERABLE_ERROR:
                $type = 'Recoverable error';
                break;
            default:
                $type = 'PHP error';
            }
            $this->_errors[] = [
                'code' => $type,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        } else {
            $this->_errors[] = [
                'code' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        Yii::getLogger()->log($this->_errors, $category, 'api.' . $this->uniqueId);
        $this->renderResponse();
    }

    private function _parseErrorCodes($filename)
    {
        $content = file_get_contents($filename);
        if (!$content) {
            return [];
        }

        $result = [];
        $n = preg_match_all('/,\s*\'((?>[A-Z]+|_)+)\'\s*\)\;/m', $content, $matches);
        for ($i = 0; $i < $n; ++$i) {
            $result[] = trim($matches[1][$i], "'");
        }

        return $result;
    }

    /**
     * Generate API documentation in Confluence markup
     *
     * @return void
     */
    public function actionGenerateConfluenceDocumentation()
    {
        $api = $this->_innerGenerateAvailableActions();

        if (isset($api['properties']['statelessApi']['value']) and $api['properties']['statelessApi']['value']) {
            $stateless = '*bezestavové*, tzn. je potřeba se při každém požadavku ověřit platnými přístupovými údaji.';
        } else {
            $stateless =
                '*stavové*, tzn. uživatel je po přihlášení na předem stanovenou dobu vůči serveru ověřen. Pro zrušení ověření je potřeba provést odhlášení.';
        }

        $errorCodesGlobal = array_unique($this->_parseErrorCodes(__FILE__));
        $errorCodesLocal = array_unique($this->_parseErrorCodes($api['filename']));

        $generated = Tools::formatDate(time(), Tools::DATETIME_LONG);

        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header("Content-Type: text/plain; charset=UTF-8");

        echo <<<END
{info}Vygenerováno {$generated} z API {$api['shortname']}{info}

h1. Obsah
{toc:printable=true|style=square|maxLevel=3|type=list|exclude=Obsah}

h1. Volání metod a předávání parametrů

Základní volací adresa je:
{code}
https://<server>/{$api['url']}/<nazev-metody>
{code}

Parametry musí být na aplikační server předávány ve formátu JSON v těle HTTP POST požadavku.
Názvy jednotlivých proměnných jsou uvedeny v camelCase (první písmeno malé, každé další písmeno nového slova velké).
Nezáleží na pořadí proměnných.
Příklad požadavku na server:
{code}
{"userName":"pepik","passWord":"12345","imei":"128048-70648-724987"}
{code}

h1. Výstup metod
Výstup jednotlivých metod je vždy ve formě JSON řetězce.
Názvy jednotlivých proměnných jsou uvedeny v camelCase (první písmeno malé, každé další písmeno nového slova velké).
Pořadí proměnných v odpovědi může být oproti definici jiné.
Odpověd serveru vždy obsahuje dvě proměnné - hasErrors a errors. Více o těchto proměnných níže v kapitole *Ošetření chybových stavů*.
Příklad odpovědi serveru:
{code}
{"message":"pong","hasErrors:false,"errors":[]}
{code}

h1. Ověřování
{$api['shortname']} je {$stateless}.
Ověření probíhá pomocí identity {color:red}DOPLNIT{color},

h1. Ošetření chybových stavů
API kontroluje téměř všechny chybové stavy, ke kterým by mohlo za normálních okolností dojít. Tyto chyby jsou protistraně předávány pomocí dvou atributů, které jsou součástí odpovědi každého požadavku na API.

* Atribut hasErrors (boolean) nabývá hdnoty true v případě, že byly zjištěny chyby ve zpracování požadavku na API.
* Atribut errors (pole) pak obsahuje seznam chybových kódů (code) a zpráv (message)

Příklad odpovědi obsahující chybu:

{code}
{"hasErrors:true,"errors":[["code":"NOT_SECURE","message":"Komunikace neprobíhá přes SSL"],["code":"NOT_POST","message":"Požadavek není tpu POST"]]}
{code}

h2. Seznam obecných chybových kódů
Následující seznam chybových kódů vychází z implementace API a je společný pro všechna API.

END;

        foreach ($errorCodesGlobal as $code) {
            echo "* *{$code}*\r\n";
        }

        echo <<< END

h2. Seznam chybových kódů {$api['shortname']}
Následující seznam chybových kódů je platný pouze v API {$api['shortname']}.

END;

        foreach ($errorCodesLocal as $code) {
            echo "* *{$code}*\r\n";
        }

        //Constants
        echo "\r\n";
        echo "h1. Konstanty\r\n";
        echo "\r\n";

        foreach ($api['constants'] as $name => $value) {
            echo "* *{$name}* - {$value}\r\n";
        }

        //Properties
        echo "\r\n";
        echo "h1. Vlastnosti\r\n";
        echo "\r\n";

        foreach ($api['properties'] as $name => $prop) {
            echo "+*{$name}*+ ({$prop['type']})\r\n";
            echo "_{$prop['description']}_\r\n";
            if (isset($prop['value']) && $prop['value']) {
                echo "Výchozí hodnota: {$prop['value']}\r\n";
            }
            echo "\r\n";
        }

        //Methods
        echo "\r\n";
        echo "h1. Metody\r\n";
        echo "\r\n";

        foreach ($api['methods'] as $methodName => $method) {
            echo "h2. {$methodName}()\r\n";
            if ($method['description']) {
                echo "_{$method['description']}_\r\n";
                echo "\r\n";
            }

            if ($method['no-auth']) {
                echo "(i) Není vyžadována autorizace\r\n";
            } else {
                if ($method['permission']) {
                    echo "(!) Pro přístup je nutné oprávnění {$method['permission']}\r\n";
                }
            }

            echo "\r\n";
            echo "+*Vstupní parametry*+\r\n";

            if (count($method['params']) > 0) {
                foreach ($method['params'] as $name => $param) {
                    echo "*{$name}* : _{$param['type']}_ - {$param['description']}" .
                        (!$param['required'] ? ' _(nepovinný - výchozí hodnota: ' . $param['default'] . ')_' : '') . "\r\n";
                    if (isset($param['elements'])) {
                        foreach ($param['elements'] as $eName => $element) {
                            echo "* *{$eName}*";
                            if (isset($element['type'])) {
                                echo " : _{$element['type']}_";
                            }
                            if (isset($element['description'])) {
                                echo " - {$element['description']}";
                            }
                            echo "\r\n";
                        }
                        echo "\r\n";
                    }
                }
            } else {
                echo "-\r\n";
            }

            echo "\r\n";
            echo "+*Návratové hodnoty*+\r\n";

            if ($method['return']['type'] == 'array' and !empty($method['return-params'])) {
                foreach ($method['return-params'] as $name => $param) {
                    echo "*{$name}* : _{$param['type']}_ - {$param['description']}\r\n";
                }
            } else {
                echo "-\r\n";
            }

            echo "\r\n";
        }

        Yii::$app->end();
    }
}
