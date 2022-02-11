<?php
// +----------------------------------------------------------------------
// 定时器
// +----------------------------------------------------------------------

namespace Cdyun\PhpCrontab;

use Cdyun\PhpCrontab\Core\DbTrait;
use Cdyun\PhpCrontab\Core\EnvTrait;
use Cdyun\PhpCrontab\Core\RouteTrait;
use Cdyun\PhpCrontab\Core\ToolTrait;
use Cdyun\PhpCrontab\Core\UseRouter;
use Cdyun\PhpHttp\HttpService;
use Workerman\Connection\TcpConnection;
use Workerman\Crontab\Crontab;
use Workerman\MySQL\Connection;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

/**
 * Class Cron
 * @package Cdyun\PhpCron
 */
class Cron
{
    use EnvTrait;
    use DbTrait;
    use ToolTrait;
    use RouteTrait;

    /**
     * 路由信息
     * */
    protected $indexPath = '/crontab/index';
    protected $createPath = '/crontab/create';
    protected $modifyPath = '/crontab/modify';
    protected $reloadPath = '/crontab/reload';
    protected $deletePath = '/crontab/delete';
    protected $logsPath = '/crontab/logs';
    protected $poolPath = '/crontab/pool';
    protected $pingPath = '/crontab/ping';
    protected $errorPath = '/crontab/error';
    /**
     *环境配置
     * */
    private $env = [];
    /**
     * 数据库配置
     * @var array
     */
    private $dbConfig = [
        'host' => '127.0.0.1',
        'port' => '3306',
        'user' => 'root',
        'password' => 'root',
        'db_name' => 'test',
        'charset' => 'utf8mb4',
        'prefix' => '',
    ];
    /**
     * 数据库进程池
     * @var Connection[] array
     */
    private $dbPool = [];

    /**
     * 任务进程池
     * @var Crontab[] array
     */
    private $cronPool = [];
    /**
     * 调试模式
     * @var bool
     */
    private $debug = false;
    /**
     * worker 实例
     * @var Worker
     */
    private $worker;
    /**
     * 进程名
     * @var string
     */
    private $workerName = "Workerman Crontab";
    /**
     * 定时任务表
     * @var string
     */
    private $cronTable = 'system_crontab';
    /**
     * 定时任务日志表
     * @var string
     */
    private $cronRecord = 'system_crontab_log';
    /**
     * 定时任务日志表后缀 按月分表
     * @var string|null
     */
    private $recordSuffix;
    /**
     * 错误信息
     * @var
     */
    private $errorMsg = [];
    /**
     * 安全秘钥
     * @var string
     */
    private $safeKey;
    /**
     *socket监听$listen参数
     * */
    private $base_url = 'http://127.0.0.1:2345';

    public function __construct($env = [])
    {
        $this->env = $env;
        try {
            $this->checkEnv();
            $this->setDbConfig($env);
            $this->setRouterConfig($env);
            $this->registerRoute();
            $this->initWorker($this->base_url, []);
        } catch (Core\CronException $e) {
            $eMsg[] = $e->getMessage();
            $this->errorMsg = array_merge($this->errorMsg, $eMsg);
        }
    }


    /**
     * 初始化 worker
     * @param string $socketName
     * @param array $contextOption
     */
    private function initWorker($socketName = '', $contextOption = [])
    {
        $socketName = $socketName ?: $this->base_url;
        $this->worker = new Worker($socketName, $contextOption);
        $this->worker->name = $this->workerName;
        if (isset($contextOption['ssl'])) {
            $this->worker->transport = 'ssl';//设置当前Worker实例所使用的传输层协议，目前只支持3种(tcp、udp、ssl)。默认为tcp。
        }
        $this->registerCallback();
    }

    /**
     * 注册子进程回调函数
     */
    private function registerCallback()
    {
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onWorkerReload = [$this, 'onWorkerReload'];
        $this->worker->onWorkerStop = [$this, 'onWorkerStop'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
        $this->worker->onBufferFull = [$this, 'onBufferFull'];
        $this->worker->onBufferDrain = [$this, 'onBufferDrain'];
        $this->worker->onError = [$this, 'onError'];
    }

    /**
     * 设置Worker收到reload信号后执行的回调
     * @param Worker $worker
     */
    public function onWorkerReload(Worker $worker)
    {
    }

    /**
     * @param Worker $worker
     */
    public function onWorkerStop(Worker $worker)
    {
    }

    /**
     * 当客户端与Workerman建立连接时(TCP三次握手完成后)触发的回调函数
     * @param TcpConnection $connection
     */
    public function onConnect(TcpConnection $connection)
    {
        $this->checkTables();
    }

    /**
     * 当客户端通过连接发来数据时(Workerman收到数据时)触发的回调函数
     * @param TcpConnection $connection
     * @param $request
     */
    public function onMessage(TcpConnection $connection, $request)
    {
        if ($request instanceof Request) {
            if (!is_null($this->safeKey) && $request->header('key') !== $this->safeKey) {
                $connection->send($this->response('', 'Error SafeKey,Connection Not Allowed!', 403));
            } else {
//                var_dump($request->method());
//                var_dump($request->path());
//                var_dump($request->get());
//                var_dump($request->post());
//                var_dump($request->uri());
                $connection->send($this->response(UseRouter::dispatch($request->method(), $request->path())));
            }
        }
    }


    /**
     * 当客户端连接与Workerman断开时触发的回调函数
     * @param TcpConnection $connection
     */
    public function onClose(TcpConnection $connection)
    {
    }

    /**
     * 缓冲区满则会触发onBufferFull回调
     * @param TcpConnection $connection
     */
    public function onBufferFull(TcpConnection $connection)
    {
    }

    /**
     * 在应用层发送缓冲区数据全部发送完毕后触发
     * @param TcpConnection $connection
     */
    public function onBufferDrain(TcpConnection $connection)
    {
    }

    /**
     * 客户端的连接上发生错误时触发
     * @param TcpConnection $connection
     * @param $code
     * @param $msg
     */
    public function onError(TcpConnection $connection, $code, $msg)
    {
    }

    /**
     * 设置Worker子进程启动时的回调函数，每个子进程启动时都会执行
     * @param Worker $worker
     * @return bool
     */
    public function onWorkerStart(Worker $worker)
    {
        $this->dbPool[$worker->id] = $this->dbConnection();

        $this->checkTables(); //检测表

        $ids = $this->dbPool[$worker->id]
            ->select('id')
            ->from($this->cronTable)
            ->orderByASC(['sort'])
            ->where("status = 1")
            ->column();
        if (!empty($ids)) {
            foreach ($ids as $vo) {
                $this->crontabRun($vo);
            }
        }

        return true;
    }

    /**
     * 创建定时器
     * 0   1   2   3   4   5
     * |   |   |   |   |   |
     * |   |   |   |   |   +------ day of week (0 - 6) (Sunday=0)
     * |   |   |   |   +------ month (1 - 12)
     * |   |   |   +-------- day of month (1 - 31)
     * |   |   +---------- hour (0 - 23)
     * |   +------------ min (0 - 59)
     * +-------------- sec (0-59)[可省略，如果没有0位,则最小时间粒度是分钟]
     * @param $item
     */
    private function crontabRun($item)
    {
        $rs = $this->getCron($item);

        if (!empty($rs)) {
            $callable = function () use ($rs) {
                $time = time();
                $shell = trim($rs['shell']);
                $this->debug && $this->writeln('执行定时器任务#' . $rs['id'] . ' ' . $rs['frequency'] . ' ' . $shell);
                $startTime = microtime(true);
                if ($rs['type'] == 1) {
                    $http = HttpService::getRequest($shell);
                    $code = $http['response_code'] == 200 ? 1 : 0;
                    $output = json_encode(json_decode($http['response_data'], true), JSON_UNESCAPED_UNICODE);
                } else {
                    exec($shell, $output, $code);
                    $output = join(PHP_EOL, $output);
                }
                $endTime = microtime(true);
                $this->updateCron(json_encode(date('Y-m-d H:i:s', $time)), $rs['id']);
                $this->create($this->cronRecord, [
                    'sid' => $rs['id'],
                    'command' => $shell,
                    'output' => $output,
                    'return_var' => $code,
                    'running_time' => round($endTime - $startTime, 6),
                    'create_time' => date('Y-m-d H:i:s', time()),
                    'update_time' => date('Y-m-d H:i:s', time()),
                ]);
            };
            $this->cronPool[$rs['id']] = [
                'id' => $rs['id'],
                'shell' => $rs['shell'],
                'frequency' => $rs['frequency'],
                'remark' => $rs['remark'],
                'crontab' => new Crontab($rs['frequency'], $callable)
            ];
        }
    }

    /**
     * 获取base_url
     * @return string
     */
    public function getBaseUrl()
    {
        return $this->base_url;
    }


    public function run()
    {
        if (empty($this->errorMsg)) {
            $this->writeln("启动系统任务");
            Worker::runAll();
        } else {
            foreach ($this->errorMsg as $v) {
                $this->writeln($v, false);
            }
        }
    }
}