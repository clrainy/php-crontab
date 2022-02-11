<?php

namespace Cdyun\PhpCrontab\Core;

use Workerman\Protocols\Http\Response;

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


    /**
     * 响应
     * @param string $data
     * @param string $msg
     * @param int $code
     * @return Response
     */
    private function response($data = '', $msg = '信息调用成功！', $code = 200)
    {
        return new Response($code, [
            'Content-Type' => 'application/json; charset=utf-8',
        ], json_encode(['code' => $code, 'data' => $data, 'msg' => $msg]));
    }
}
