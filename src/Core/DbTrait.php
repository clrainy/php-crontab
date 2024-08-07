<?php

namespace Cdyun\PhpCrontab\Core;

use Workerman\MySQL\Connection;
use Workerman\Protocols\Http\Request;

/**
 *
 * Class DbTrait
 * @package Cdyun\PhpCrontab\Core
 */
trait DbTrait
{
    /**
     * 设置数据库链接信息
     * @param array $env
     * @throws CronException
     */
    public function setDbConfig(array $env = [])
    {
        $env = array_merge($this->env, $env);
        if (!isset($env['DB_HOST']) || empty($env['DB_HOST'])) {
            throw new CronException('应用配置文件中CRONTAB缺少mysql地址：DB_HOST');
        }
        if (!isset($env['DB_NAME']) || empty($env['DB_NAME'])) {
            throw new CronException('应用配置文件中CRONTAB缺少数据库名称：DB_NAME');
        }
        if (!isset($env['DB_USER']) || empty($env['DB_USER'])) {
            throw new CronException('应用配置文件中CRONTAB缺少数据库用户名：DB_USER');
        }
        if (!isset($env['DB_PWD']) || empty($env['DB_PWD'])) {
            throw new CronException('应用配置文件中CRONTAB缺少数据库密码：DB_PWD');
        }
        if (!isset($env['DB_PORT']) || empty($env['DB_PORT'])) {
            throw new CronException('应用配置文件中CRONTAB缺少数据库端口：DB_PORT');
        }

        $config = [
            'host' => $env['DB_HOST'],
            'port' => $env['DB_PORT'],
            'user' => $env['DB_USER'],
            'password' => $env['DB_PWD'],
            'db_name' => $env['DB_NAME'],
        ];
        $this->cronTable = $env['CRON_TABLE'];
        $this->cronRecord = $env['CRON_LOG'];
        $this->dbConfig = array_merge($this->dbConfig, $config);
        if ($this->dbConfig['prefix']) {
            $this->cronTable = $this->dbConfig['prefix'] . $this->cronTable;
            $this->cronRecord = $this->dbConfig['prefix'] . $this->cronRecord;
        }
        if (isset($env['BASE_URL']) && !empty($env['BASE_URL'])) {
            $this->base_url = $env['BASE_URL'];
        }

        $this->debug = isset($env['CRON_DEBUG']) && $env['CRON_DEBUG'] == true;
        $this->safeKey = isset($env['SAFE_KEY']) && !empty($env['SAFE_KEY']) ? $env['SAFE_KEY'] : null;
    }

    /**
     * 检测表是否存在
     */
    public function checkTables()
    {
        $date = date('Ym', time());
        if ($date !== $this->recordSuffix) {
            $this->recordSuffix = $date;
            $this->cronRecord .= "_" . $date;
            $allTables = $this->listDbTables($this->dbConfig['db_name']);
            !in_array($this->cronTable, $allTables) && $this->createCronTable();
            !in_array($this->cronRecord, $allTables) && $this->createCronLogs();
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
    private function createCronTable()
    {
        $sql = <<<SQL
 CREATE TABLE IF NOT EXISTS `{$this->cronTable}`  (
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
    private function createCronLogs()
    {
        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$this->cronRecord}`  (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `sid` int(60) NOT NULL COMMENT '任务id',
  `command` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '执行命令',
  `output` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT '执行输出',
  `return_var` tinyint(4) NOT NULL COMMENT '执行返回状态[0：成功; 非0：失败]',
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
     * 定时器列表
     * @param Request $request
     * @return array
     */
    public function cronIndex(Request $request)
    {
        list($page, $limit, $where) = $this->buildTableParames($request->get());
        list($whereStr, $bindValues) = $this->parseWhere($where);

        $data = $this->dbPool[$this->worker->id]
            ->select('*')
            ->from($this->cronTable)
            ->where($whereStr)
            ->orderByDESC(['Id'])
            ->limit($limit)
            ->offset(($page - 1) * $limit)
            ->bindValues($bindValues)
            ->query();

        $count = $this->dbPool[$this->worker->id]
            ->select('count(id)')
            ->from($this->cronTable)
            ->where($whereStr)
            ->bindValues($bindValues)
            ->single();

        return ['list' => $data, 'count' => $count];
    }

    /**
     * 构建请求参数
     * @param array $get
     * @param array $excludeFields 忽略构建搜索的字段
     * @return array
     */
    private function buildTableParames($get, $excludeFields = [])
    {
        $page = isset($get['page']) && !empty($get['page']) ? (int)$get['page'] : 1;
        $limit = isset($get['limit']) && !empty($get['limit']) ? (int)$get['limit'] : 15;
        $filters = isset($get['filter']) && !empty($get['filter']) ? $get['filter'] : '{}';
        $ops = isset($get['op']) && !empty($get['op']) ? $get['op'] : '{}';
        // json转数组
        $filters = json_decode($filters, true);
        $ops = json_decode($ops, true);
        $where = [];
        $excludes = [];

        foreach ($filters as $key => $val) {
            if (in_array($key, $excludeFields)) {
                $excludes[$key] = $val;
                continue;
            }
            $op = isset($ops[$key]) && !empty($ops[$key]) ? $ops[$key] : '%*%';

            switch (strtolower($op)) {
                case '=':
                    $where[] = [$key, '=', $val];
                    break;
                case '%*%':
                    $where[] = [$key, 'LIKE', "%{$val}%"];
                    break;
                case '*%':
                    $where[] = [$key, 'LIKE', "{$val}%"];
                    break;
                case '%*':
                    $where[] = [$key, 'LIKE', "%{$val}"];
                    break;
                case 'range':
                    list($beginTime, $endTime) = explode(' - ', $val);
                    $where[] = [$key, '>=', strtotime($beginTime)];
                    $where[] = [$key, '<=', strtotime($endTime)];
                    break;
                default:
                    $where[] = [$key, $op, "%{$val}"];
            }
        }

        return [$page, $limit, $where, $excludes];
    }

    /**
     * 解析列表where条件
     * @param $where
     * @return array
     */
    private function parseWhere($where)
    {
        if (!empty($where)) {
            $whereStr = '';
            $bindValues = [];
            $whereCount = count($where);
            foreach ($where as $index => $item) {
                if ($item[0] === 'create_time') {
                    $whereStr .= $item[0] . ' ' . $item[1] . ' :' . $item[0] . $index . (($index == $whereCount - 1) ? ' ' : ' AND ');
                    $bindValues[$item[0] . $index] = $item[2];
                } else {
                    $whereStr .= $item[0] . ' ' . $item[1] . ' :' . $item[0] . (($index == $whereCount - 1) ? ' ' : ' AND ');
                    $bindValues[$item[0]] = $item[2];
                }
            }
        } else {
            $whereStr = '1 = 1';
            $bindValues = [];
        }

        return [$whereStr, $bindValues];
    }

    /**
     * 新增
     * @param Request $request
     * @return bool
     */
    public function cronCreate(Request $request)
    {
        $params = $this->allowField($request->post());
        if(!$params || empty($params)) return false;
        $id = $this->create($this->cronTable, $params);
        $id && $this->cronRun($id);
        return $id ? true : false;
    }

    /**
     * 过滤数据表字段
     * @param $params
     * @return false
     */
    public function allowField($params)
    {
        if ($this->cronField) {
            $field = explode(',', $this->cronField);
            foreach ($params as $key => $vo) {
                if (!in_array($key, $field)) unset($params[$key]);
            }
            return $params;
        }
        return false;

    }

    /**
     * 数据新增
     * @param $table
     * @param array $data
     * @return mixed
     */
    private function create($table, array $data)
    {
        return $this->dbPool[$this->worker->id]->insert($table)->cols($data)->query();
    }

    /**
     * 编辑定时器
     * @param Request $request
     * @return bool
     */
    public function cronModify(Request $request)
    {
        $params = $this->allowField($request->post());
        if(!$params || empty($params)) return false;
        $row = $this->update($this->cronTable, $params, 'id=' . $params['id']);
        if (isset($this->cronPool[$params['id']])) {
            $this->cronPool[$params['id']]['crontab']->destroy();
            unset($this->cronPool[$params['id']]);
        }
        if (isset($params['status']) && $params['status'] == 1) {
            $this->cronRun($params['id']);
        }
        return $row ? true : false;
    }

    /**
     * 数据更新
     * @param $table
     * @param array $data
     * @param $where
     * @return mixed
     */
    private function update($table, array $data, string $where)
    {
        return $this->dbPool[$this->worker->id]->update($table)->cols($data)->where($where)->query();
    }

    /**
     * 删除定时器
     * @param Request $request
     * @return bool
     */
    public function cronDelete(Request $request)
    {
        if ($id = $request->post('id')) {
            $ids = explode(',', $id);
            foreach ($ids as $item) {
                if (isset($this->cronPool[$item])) {
                    $this->cronPool[$item]['crontab']->destroy();
                    unset($this->cronPool[$item]);
                }
            }
            $rows = $this->dbPool[$this->worker->id]->delete($this->cronTable)->where('id in (' . $id . ')')->query();
            return $rows ? true : false;
        }
        return true;
    }

    /**
     * 重启定时任务
     * @param Request $request
     * @return bool
     */
    public function cronReload(Request $request)
    {
        $ids = explode(',', $request->post('id'));
        foreach ($ids as $id) {
            if (isset($this->cronPool[$id])) {
                $this->cronPool[$id]['crontab']->destroy();
                unset($this->cronPool[$id]);
            }
            $this->update($this->cronTable, ['status' => 1], 'id=' . $id);
            $this->cronRun($id);
        }

        return true;
    }

    /**
     * 日志列表
     * @param Request $request
     * @return array
     */
    public function cronLogs(Request $request)
    {
        list($page, $limit, $where, $excludeFields) = $this->buildTableParames($request->get(), ['month']);
        $request->get('sid') && $where[] = ['sid', '=', $request->get('sid')];
        list($whereStr, $bindValues) = $this->parseWhere($where);

        $allTables = $this->listDbTables($this->dbConfig['database']);
        $tableName = isset($excludeFields['month']) && !empty($excludeFields['month']) ?
            preg_replace('/_\d+/', '_' . date('Ym', strtotime($excludeFields['month'])), $this->cronRecord) :
            $this->cronRecord;
        $data = [];
        $count = 0;
        if (in_array($tableName, $allTables)) {
            $data = $this->dbPool[$this->worker->id]
                ->select('*')
                ->from($tableName)
                ->where($whereStr)
                ->orderByDESC(['Id'])
                ->limit($limit)
                ->offset(($page - 1) * $limit)
                ->bindValues($bindValues)
                ->query();

            $count = $this->dbPool[$this->worker->id]
                ->select('count(id)')
                ->from($tableName)
                ->where($whereStr)
                ->bindValues($bindValues)
                ->single();
        }

        return ['list' => $data, 'count' => $count];
    }

    /**定时器池
     * @return array
     */
    public function cronPool()
    {
        $data = [];
        foreach ($this->cronPool as $row) {
            unset($row['crontab']);
            $data[] = $row;
        }
        return $data;
    }

    /**
     * 连接
     * @param Request $request
     * @return string
     */
    public function cronPing(Request $request)
    {
        return '连接成功';
    }

    /**
     * 更新定时器
     * @param string $date
     * @param $id
     * @return mixed
     */
    private function updateCron(string $date, $id)
    {
        return $this->dbPool[$this->worker->id]
            ->query("
UPDATE {$this->cronTable} 
SET running_times = running_times + 1, 
    last_running_time = " . $date . ",
    update_time = " . $date . " 
WHERE id = {$id}
     ");
    }

    /**
     * 连接数据库
     * @return Connection
     */
    private function dbConnection()
    {
        return new Connection(
            $this->dbConfig['host'],
            $this->dbConfig['port'],
            $this->dbConfig['user'],
            $this->dbConfig['password'],
            $this->dbConfig['db_name']
        );
    }

    /**
     * 获取指定定时器信息
     * @param $id
     * @return mixed
     */
    private function getCron($id)
    {
        return $this->dbPool[$this->worker->id]
            ->select('*')
            ->from($this->cronTable)
            ->where('id= :id')
            ->where('status= :status')
            ->bindValues(['id' => $id, 'status' => 1])
            ->row();
    }
}
