<?php

namespace Cdyun\PhpCrontab\Core;

/**
 * Class ToolTrait
 * @package Cdyun\PhpCrontab\Core
 */
trait ToolTrait
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
