<?php
// +----------------------------------------------------------------------
// 定时器
// +----------------------------------------------------------------------

namespace Cdyun\PhpCrontab;

use Cdyun\PhpCrontab\Core\EnvService;
use Cdyun\PhpCrontab\Core\ToolService;
use Cdyun\PhpHttp\HttpService;
use Workerman\Connection\TcpConnection;
use Workerman\Crontab\Crontab;
use Workerman\MySQL\Connection;
use Workerman\Worker;

/**
 * Class Cron
 * @package Cdyun\PhpCron
 */
class Cron
{
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
        'database' => 'test',
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
    private $table = 'system_crontab';

    /**
     * 定时任务日志表
     * @var string
     */
    private $record = 'system_crontab_log';

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

    public function __construct($env = [])
    {
        $this->env = $env;
        $service = new EnvService($env);
        try {
            $service->checkEnv();
            $this->setDbConfig($env);
            $this->debug = isset($env['CRON_DEBUG']) && $env['CRON_DEBUG'] == true;
            $this->initWorker($env['BASE_URI'], []);
            $this->setSafeKey(isset($env['SAFE_KEY']) && !empty($env['SAFE_KEY']) ? $env['SAFE_KEY'] : null);
        } catch (Core\CronException $e) {
            $eMsg[] = $e->getMessage();
            $this->errorMsg = array_merge($this->errorMsg, $eMsg);

        }
    }

    /**
     * 设置数据库链接信息
     * @param array $env
     * @return $this
     */
    public function setDbConfig(array $env = [])
    {
        $config = [
            'host' => $env['DB_HOST'],
            'port' => $env['DB_PORT'],
            'user' => $env['DB_USER'],
            'password' => $env['DB_PWD'],
            'db_name' => $env['DB_NAME'],
        ];
        $this->table = $env['CRON_TABLE'];
        $this->record = $env['CRON_LOG'];
        $this->dbConfig = array_merge($this->dbConfig, $config);
        if ($this->dbConfig['prefix']) {
            $this->table = $this->dbConfig['prefix'] . $this->table;
            $this->record = $this->dbConfig['prefix'] . $this->record;
        }
        return $this;
    }

    /**
     * 初始化 worker
     * @param string $socketName
     * @param array $contextOption
     */
    private function initWorker($socketName = '', $contextOption = [])
    {
        $socketName = $socketName ?: 'http://127.0.0.1:2345';
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
     * 启用安全模式
     * @param $key
     * @return $this
     */
    public function setSafeKey($key)
    {
        $this->safeKey = $key;

        return $this;
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
     * 检测表是否存在
     */
    public function checkTables()
    {
        $date = date('Ym', time());
        if ($date !== $this->recordSuffix) {
            $this->recordSuffix = $date;
            $this->record .= "_" . $date;
            $allTables = $this->listDbTables($this->dbConfig['db_name']);
            !in_array($this->table, $allTables) && $this->createCrontabTable();
            !in_array($this->record, $allTables) && $this->createCrontabTableLogs();
        }
    }

    /**
     * 获取数据库表名
     * @param $dbname
     * @return array
     */
    private function listDbTables($dbname)
    {
        return $this->dbPool[$this->worker->id]
            ->select('TABLE_NAME')
            ->from('information_schema.TABLES')
            ->where("TABLE_TYPE='BASE TABLE'")
            ->where("TABLE_SCHEMA='" . $dbname . "'")
            ->column();
    }

    /**
     * 创建定时器任务表
     */
    private function createCrontabTable()
    {
        $sql = <<<SQL
 CREATE TABLE IF NOT EXISTS `{$this->table}`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '任务标题',
  `type` tinyint(4) NOT NULL DEFAULT 0 COMMENT '任务类型[1请求url,2执行shell]',
  `frequency` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '任务频率',
  `shell` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '' COMMENT '任务脚本',
  `running_times` int(11) NOT NULL DEFAULT 0 COMMENT '已运行次数',
  `last_running_time` datetime NULL DEFAULT NULL COMMENT '最近运行时间',
  `remark` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT '任务备注',
  `sort` int(11) NOT NULL DEFAULT 0 COMMENT '排序，越大越前',
  `status` tinyint(4) NOT NULL DEFAULT 0 COMMENT '任务状态状态[0:禁用;1启用]',
  `op_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '更新时间',
  `delete_time` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `title`(`title`) USING BTREE,
  INDEX `type`(`type`) USING BTREE,
  INDEX `create_time`(`create_time`) USING BTREE,
  INDEX `status`(`status`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 3 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '定时器任务表' ROW_FORMAT = Dynamic
SQL;

        return $this->dbPool[$this->worker->id]->query($sql);
    }

    /**
     * 创建定时器任务记录表
     */
    private function createCrontabTableLogs()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$this->record}`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sid` int(60) NOT NULL COMMENT '任务id',
  `command` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '执行命令',
  `output` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '执行输出',
  `return_var` tinyint(4) NOT NULL COMMENT '执行返回状态[0成功; 1失败]',
  `running_time` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '执行所用时间',
  `create_time` datetime NULL DEFAULT NULL COMMENT '创建时间',
  `update_time` datetime NULL DEFAULT NULL COMMENT '更新时间',
  `delete_time` datetime NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `create_time`(`create_time`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 519 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci COMMENT = '定时器任务流水表{$this->recordSuffix}' ROW_FORMAT = Dynamic
SQL;

        return $this->dbPool[$this->worker->id]->query($sql);
    }

    /**
     * 当客户端通过连接发来数据时(Workerman收到数据时)触发的回调函数
     * @param TcpConnection $connection
     * @param $request
     */
    public function onMessage(TcpConnection $connection, $request)
    {
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
        $this->dbPool[$worker->id] = new Connection(
            $this->dbConfig['host'],
            $this->dbConfig['port'],
            $this->dbConfig['user'],
            $this->dbConfig['password'],
            $this->dbConfig['db_name']
        );

        $this->checkTables(); //检测表

        $ids = $this->dbPool[$worker->id]
            ->select('id')
            ->from($this->table)
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
        $rs = $this->dbPool[$this->worker->id]
            ->select('*')
            ->from($this->table)
            ->where('id= :id')
            ->where('status= :status')
            ->bindValues(['id' => $item, 'status' => 1])
            ->row();

        if (!empty($rs)) {
            $callable = function () use ($rs) {
                $tool = new ToolService();
                $time = time();
                $shell = trim($rs['shell']);
                $this->debug && $tool->writeln('执行定时器任务#' . $rs['id'] . ' ' . $rs['frequency'] . ' ' . $shell);
                $startTime = microtime(true);
                if ($rs['type'] == 1) {
                    $http = HttpService::getRequest($shell);
                    $code = $http == false ? 0 : 1;
                    $output = json_encode(json_decode($http, true), JSON_UNESCAPED_UNICODE);
                } else {
                    exec($shell, $output, $code);
                    $output = join(PHP_EOL, $output);
                }
                $endTime = microtime(true);
                $fmt = date('Y-m-d H:i:s', $time);
                $this->dbPool[$this->worker->id]
                    ->query("UPDATE {$this->table} SET running_times = running_times + 1, last_running_time = " . json_encode($fmt) . ", update_time = " . json_encode($fmt) . " WHERE id = {$rs['id']}");

                $this->dbPool[$this->worker->id]
                    ->insert($this->record)
                    ->cols([
                        'sid' => $rs['id'],
                        'command' => $shell,
                        'output' => $output,
                        'return_var' => $code,
                        'running_time' => round($endTime - $startTime, 6),
                        'create_time' => date('Y-m-d H:i:s', time()),
                        'update_time' => date('Y-m-d H:i:s', time()),
                    ])
                    ->query();
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


    public function run()
    {
        $tool = new ToolService();
        if (empty($this->errorMsg)) {
            $tool->writeln("启动系统任务");
            Worker::runAll();
        } else {
            foreach ($this->errorMsg as $v) {
                $tool->writeln($v, false);
            }
        }
    }
}
