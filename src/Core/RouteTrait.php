<?php

namespace Cdyun\PhpCrontab\Core;

/**
 * Class RouteTrait
 * @package Cdyun\PhpCrontab\Core
 */
trait RouteTrait
{
    /**
     * 获取指定路由
     * @param $name
     * @return string
     */
    public function getPath($name)
    {
        if ($name == 'index') {
            return $this->indexPath;
        } else if ($name == 'create') {
            return $this->createPath;
        } else if ($name == 'modify') {
            return $this->modifyPath;
        } else if ($name == 'reload') {
            return $this->reloadPath;
        } else if ($name == 'delete') {
            return $this->deletePath;
        } else if ($name == 'logs') {
            return $this->logsPath;
        } else if ($name == 'pool') {
            return $this->poolPath;
        } else if ($name == 'ping') {
            return $this->pingPath;
        } else {
            return $this->errorPath;
        }

    }

    /**
     * 注册路由
     * */
    private function registerRoute()
    {
        UseRouter::get($this->indexPath, 'cronIndex');
        UseRouter::post($this->createPath, 'cronCreate');
        UseRouter::post($this->modifyPath, 'cronModify');
        UseRouter::post($this->deletePath, 'cronDelete');
        UseRouter::post($this->reloadPath, 'cronReload');
        UseRouter::get($this->logsPath, 'cronLogs');
        UseRouter::get($this->poolPath, 'cronPool');
        UseRouter::get($this->pingPath, 'cronPing');
    }

    /**
     * 设置环境路由信息
     * @param array $env
     */
    private function setRouterConfig(array $env = [])
    {
        $env = array_merge($this->env, $env);
        if (isset($env['CRON_INDEX']) && !empty($env['CRON_INDEX']) && is_string($env['CRON_INDEX'])) {
            $this->indexPath = $env['CRON_INDEX'];
        }
        if (isset($env['CRON_CREATE']) && !empty($env['CRON_CREATE']) && is_string($env['CRON_CREATE'])) {
            $this->createPath = $env['CRON_CREATE'];
        }
        if (isset($env['CRON_MODIFY']) && !empty($env['CRON_MODIFY']) && is_string($env['CRON_MODIFY'])) {
            $this->modifyPath = $env['CRON_MODIFY'];
        }
        if (isset($env['CRON_DELETE']) && !empty($env['CRON_DELETE']) && is_string($env['CRON_DELETE'])) {
            $this->deletePath = $env['CRON_DELETE'];
        }
        if (isset($env['CRON_RELOAD']) && !empty($env['CRON_RELOAD']) && is_string($env['CRON_RELOAD'])) {
            $this->reloadPath = $env['CRON_RELOAD'];
        }
        if (isset($env['CRON_LOGS']) && !empty($env['CRON_LOGS']) && is_string($env['CRON_LOGS'])) {
            $this->logsPath = $env['CRON_LOGS'];
        }
        if (isset($env['CRON_POOL']) && !empty($env['CRON_POOL']) && is_string($env['CRON_POOL'])) {
            $this->poolPath = $env['CRON_POOL'];
        }
        if (isset($env['CRON_PING']) && !empty($env['CRON_PING']) && is_string($env['CRON_PING'])) {
            $this->pingPath = $env['CRON_PING'];
        }
    }
}
