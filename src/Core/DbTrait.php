<?php

namespace Cdyun\PhpCrontab\Core;

use Workerman\MySQL\Connection;

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
        $this->table = $env['CRON_TABLE'];
        $this->record = $env['CRON_LOG'];
        $this->dbConfig = array_merge($this->dbConfig, $config);
        if ($this->dbConfig['prefix']) {
            $this->table = $this->dbConfig['prefix'] . $this->table;
            $this->record = $this->dbConfig['prefix'] . $this->record;
        }
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
     * 更新定时器
     * @param string $date
     * @param $id
     * @return mixed
     */
    private function cronUpdate(string $date, $id)
    {
        return $this->dbPool[$this->worker->id]
            ->query("
UPDATE {$this->table} 
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
            ->from($this->table)
            ->where('id= :id')
            ->where('status= :status')
            ->bindValues(['id' => $id, 'status' => 1])
            ->row();
    }
}
