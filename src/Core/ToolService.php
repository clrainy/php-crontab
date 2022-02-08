<?php

namespace Cdyun\PhpCrontab\Core;

/**
 * Class ToolService
 * @package Cdyun\PhpCrontab\Core
 */
class ToolService
{
    /**
     * 输出日志
     * @param $msg
     * @param bool $ok
     */
    public function writeln($msg, $ok = true)
    {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . ($ok ? " [Ok] " : " [Fail] ") . PHP_EOL;
    }
}
