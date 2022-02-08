<?php

namespace Cdyun\PhpCrontab\Core;

/**
 * Class CheckEnv
 * @package Cdyun\PhpCrontab\Core
 */
class EnvService
{
    protected $data = [];
    /**
     * 最低PHP版本
     * @var string
     */
    private $lessPhpVersion = '5.3.3';

    public function __construct($env = [])
    {
        $this->data = $env;
    }

    /**
     *运行环境检测
     * @throws CronException
     */
    public function checkEnv()
    {
        if ($this->functionDisabled('exec')) {
            throw new CronException('exec函数被禁用');
        }

        if ($this->versionCompare($this->lessPhpVersion, '<')) {
            throw new CronException('PHP版本必须≥' . $this->lessPhpVersion);
        }
        if ($this->isLinux()) {
            $checkExt = ["pcntl", "posix"];
            foreach ($checkExt as $ext) {
                if (!$this->extensionLoaded($ext)) {
                    throw new CronException($ext . '扩展没有安装');
                }
            }
            $checkFunc = [
                "stream_socket_server",
                "stream_socket_client",
                "pcntl_signal_dispatch",
                "pcntl_signal",
                "pcntl_alarm",
                "pcntl_fork",
                "posix_getuid",
                "posix_getpwuid",
                "posix_kill",
                "posix_setsid",
                "posix_getpid",
                "posix_getpwnam",
                "posix_getgrnam",
                "posix_getgid",
                "posix_setgid",
                "posix_initgroups",
                "posix_setuid",
                "posix_isatty",
            ];
            foreach ($checkFunc as $func) {
                if ($this->functionDisabled($func)) {
                    throw new CronException($func . '函数被禁用');
                }
            }
        }
        $config = $this->data;
        if (empty($config)) {
            throw new CronException('应用配置文件.env缺少参数：CRONTAB');
        }
        if (!isset($config['DB_HOST']) || empty($config['DB_HOST'])) {
            throw new CronException('应用配置文件中CRONTAB缺少mysql地址：DB_HOST');
        }
        if (!isset($config['DB_NAME']) || empty($config['DB_NAME'])) {
            throw new CronException('应用配置文件中CRONTAB缺少数据库名称：DB_NAME');
        }
        if (!isset($config['DB_USER']) || empty($config['DB_USER'])) {
            throw new CronException('应用配置文件中CRONTAB缺少数据库用户名：DB_USER');
        }
        if (!isset($config['DB_PWD']) || empty($config['DB_PWD'])) {
            throw new CronException('应用配置文件中CRONTAB缺少数据库密码：DB_PWD');
        }
        if (!isset($config['DB_PORT']) || empty($config['DB_PORT'])) {
            throw new CronException('应用配置文件中CRONTAB缺少数据库端口：DB_PORT');
        }
    }

    /**
     * 函数是否被禁用
     * @param $method
     * @return bool
     */
    private function functionDisabled($method)
    {
        return in_array($method, explode(',', ini_get('disable_functions')));
    }

    /**
     * 版本比较
     * @param $version
     * @param string $operator
     * @return bool
     */
    private function versionCompare($version, $operator = ">=")
    {
        return version_compare(phpversion(), $version, $operator);
    }

    /**
     * 是否是Linux操作系统
     * @return bool
     */
    private function isLinux()
    {
        return strpos(PHP_OS, "Linux") !== false;
    }

    /**
     * 扩展是否加载
     * @param $extension
     * @return bool
     */
    private function extensionLoaded($extension)
    {
        return in_array($extension, get_loaded_extensions());
    }

    /**
     * 获取env数据
     * @return array|false
     */
    public function getEnv()
    {
        return $this->data;
    }
}
