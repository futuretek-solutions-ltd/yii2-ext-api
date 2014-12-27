<?php

namespace futuretek\api;

use Yii;
use yii\base\Action;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\InlineAction;
use yii\base\InvalidParamException;
use yii\base\InvalidRouteException;
use yii\base\Module;
use yii\helpers\Json;
use yii\log\Logger;
use yii\web\Controller;
use yii\web\IdentityInterface;

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
     * @var IdentityInterface Identity provider
     */
//    private $_identity;

    private $_errors = [];

    private $_inputVars = [];

    public $forceSecureConnection = true;

    /**
     * @var bool CSRF validation - changed to protected so CSRF validation will be always disabled
     */
    protected $enableCsrfValidation = false;

    /**
     * Init method
     *
     * @return void
     */
    public function init()
    {
        parent::init();

        //Force SSL check
        if ($this->forceSecureConnection and !Yii::$app->request->isSecureConnection) {
            $this->setError(Yii::t('Api', 'Request is not secure (over HTTPS)'), 'REQUEST_NOT_SECURE');
        }

        //Request method check
        if (!Yii::$app->request->isPost) {
            $this->setError(Yii::t('Api', 'Request method must be POST'), 'REQUEST_NOT_POST');
        }

        //Parse input variables from request
        $this->_parseInput();

        Yii::$app->errorHandler->errorAction = $this->uniqueId . '/handle-error';
        //Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;
    }

    public function setError($message, $code = 'UNKNOWN')
    {
        $this->_errors[] = ['message' => $message, 'code' => $code];
        $this->renderResponse();
    }

    public function setWarning($message, $code = 'UNKNOWN')
    {
        $this->_errors[] = ['message' => $message, 'code' => $code];
    }

    public function hasErrors()
    {
        return !empty($this->_errors);
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
            $result = $action->runWithParams($params);
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
     * Creates an action based on the given action ID.
     * The method first checks if the action ID has been declared in [[actions()]]. If so,
     * it will use the configuration declared there to create the action object.
     * If not, it will look for a controller method whose name is in the format of `actionXyz`
     * where `Xyz` stands for the action ID. If found, an [[InlineAction]] representing that
     * method will be created and returned.
     *
     * @param string $id the action ID.
     *
     * @return Action the newly created action instance. Null if the ID doesn't resolve into any action.
     */
    public function createAction($id)
    {
        if ($id === '') {
            $id = 'generateContent';
        }
        $actionMap = $this->actions();
        if (isset($actionMap[$id])) {
            return Yii::createObject($actionMap[$id], [$id, $this]);
        } elseif (preg_match('/^[a-z0-9\\-_]+$/', $id) && strpos($id, '--') === false && trim($id, '-') === $id) {
            $methodName = 'action' . str_replace(' ', '', ucwords(implode(' ', explode('-', $id))));
            if (method_exists($this, $methodName)) {
                $method = new \ReflectionMethod($this, $methodName);
                if ($method->isPublic() && $method->getName() === $methodName) {
                    return new InlineAction($id, $this, $methodName);
                }
            }
        }

        return null;
    }

    public function actionGenerateContent()
    {
//        foreach ($this->actions() as $action) {
//
//        }
    }

    /**
     * Ping action intended mainly to test the API interface availability
     *
     * @return array
     * @return-element String message Reply message
     * @api
     * @no-auth
     */
    public function actionPing()
    {
        return ['message' => 'pong'];
    }

//    private function _checkAuthToken($token)
//    {
//        $identityClass = $this->authParams['identity'];
//        $identity = new $identityClass();
//        if ($identity->validateAuthKey($token)) {
//            Yii::$app->user->login($identity);
//
//        } else {
//            throw new ApiException('E_TOKEN_WRONG', Yii::t('ApiCommon', 'Authentication token is wrong or expired'));
//        }
//    }

    private function _parseInput()
    {
        $postBody = file_get_contents('php://input');

        if (!$postBody) {
            $this->setError(Yii::t('Api', 'Request body is empty'), 'REQUEST_EMPTY');
        }

        try {
            $json = Json::decode($postBody);
            $this->_inputVars = $json;
        } catch (InvalidParamException $e) {
            $this->setError($e->getMessage(), 'JSON_ERROR');
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
        //var_dump($exception);
        if (Yii::$app->db->transaction != null) {
            Yii::$app->db->transaction->rollback();
        }

        $response = array();
        $category = Logger::LEVEL_ERROR;

        if ($exception instanceof Exception) {
            $response = array(
                'type' => get_class($exception),
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            );
            if (YII_DEBUG) {
                $response['trace'] = $exception->getTraceAsString();
                $response['request'] = htmlentities(file_get_contents('php://input'));
                $response['post'] = serialize($_POST);
                $response['get'] = serialize($_GET);
                $response['cookie'] = serialize($_COOKIE);
                $response['session'] = serialize($_SESSION);
            }
        } elseif ($exception instanceof ErrorException) {
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
            $response = array(
                'response' => array(
                    'status' => 'E_UNKNOWN',
                    'type' => $type,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                ),
            );
            if (YII_DEBUG) {
                $trace = Yii::getLogger()->messages;
                if (count($trace) > 3) {
                    $trace = array_slice($trace, 3);
                }

                $response['trace'] = $trace;
                $response['request'] = htmlentities(file_get_contents('php://input'));
                $response['post'] = serialize($_POST);
                $response['get'] = serialize($_GET);
                $response['cookie'] = serialize($_COOKIE);
                $response['session'] = serialize(Yii::$app->session);
            }
        }
        if (YII_DEBUG) {
            $logMessage = print_r($response, true);
        } else {
            $logMessage =
                'Status: ' . $response['status'] . ' - ' . 'Msg: ' . $response['message'] . ' - ' . 'File: ' . $response['file'] . ' - ' .
                'Line: ' . $response['line'];
        }
        Yii::trace($logMessage, $category, 'api.' . $this->id);
        $this->renderResponse($response);
        Yii::$app->end();
    }

}
