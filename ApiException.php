<?php

namespace futuretek\api;

use yii\base\Exception;

/**
 * Class ApiException
 *
 * @package futuretek\api
 * @author  Lukas Cerny <lukas.cerny@futuretek.cz>
 * @license http://www.futuretek.cz/license FTSLv1
 * @link    http://www.futuretek.cz
 */
class ApiException extends Exception
{
    /**
     * @inheritdoc
     * @param string $file File in which the exception occurred
     * @param int $line Line in which the exception occurred
     */
    public function __construct($message = '', $code = 0, \Exception $previous, $file = null, $line = null)
    {
        \Exception::__construct($message, $code, $previous);

        $this->code = $code;
        $this->file = $file;
        $this->line = $line;
    }
}