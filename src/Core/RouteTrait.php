<?php

namespace Cdyun\PhpCrontab\Core;

use Cdyun\PhpRouter\Route;

/**
 * Class RouteTrait
 * @package Cdyun\PhpCrontab\Core
 */
trait RouteTrait
{
    //请求接口地址
    public static $indexPath = '/crontab/index';
    public static $createPath = '/crontab/add';
    public static $modifyPath = '/crontab/modify';
    public static $reloadPath = '/crontab/reload';
    public static $deletePath = '/crontab/delete';
    public static $logsPath = '/crontab/logs';
    public static $poolPath = '/crontab/pool';
    public static $pingPath = '/crontab/ping';

    /**
     * 注册路由
     * */
    private function registerRoute()
    {
        Route::get(self::$indexPath, [$this, 'crontabIndex']);
        Route::post(self::$createPath, [$this, 'crontabCreate']);
        Route::post(self::$modifyPath, [$this, 'crontabModify']);
        Route::post(self::$deletePath, [$this, 'crontabDelete']);
        Route::post(self::$reloadPath, [$this, 'crontabReload']);
        Route::get(self::$logsPath, [$this, 'crontabLogs']);
        Route::get(self::$poolPath, [$this, 'crontabPool']);
        Route::get(self::$pingPath, [$this, 'crontabPing']);
    }
}
