<?php

namespace futuretek\api;

/**
 * Class ErrorHandler
 *
 * @package futuretek\api
 * @author  Lukas Cerny <lukas.cerny@futuretek.cz>
 * @license http://www.futuretek.cz/license FTSLv1
 * @link    http://www.futuretek.cz
 */
class ErrorHandler extends \yii\web\ErrorHandler
{
    /**
     * @inheritdoc
     */
    protected function renderException($exception)
    {
        if ($this->errorAction !== null) {
            \Yii::$app->runAction($this->errorAction);
        } else {
            die('FATAL: Error action not specified. Exception: ' . $exception->getMessage());
        }
    }
}