<?php
/**
 * File ApiResult.php
 *
 * @package ext-lms-api
 * @author  Lukas Cerny <lukas.cerny@futuretek.cz>
 * @license http://www.futuretek.cz/license FTSLv1
 * @link    http://www.futuretek.cz
 */

namespace futuretek\lms\api;

/**
 * Class ApiResult
 *
 * @package futuretek\lms\api
 * @author  Lukas Cerny <lukas.cerny@futuretek.cz>
 * @license http://www.futuretek.cz/license FTSLv1
 * @link    http://www.futuretek.cz
 */
class ApiResult
{
    /**
     * @var bool If result has error
     */
    public $hasErrors;
    /**
     * @var array Error list
     */
    public $errors;

    /**
     * @param array $config Configuration array
     */
    function __construct(array $config)
    {
        foreach ((new \ReflectionClass($this))->getProperties() as $prop) {
            $propName = $prop->getName();
            if (array_key_exists($propName, $config)) {
                $this->$propName = $config[$propName];
            }
        }
    }

}