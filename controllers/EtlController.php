<?php
/**
 * ETL基类
 */
namespace console\controllers;

use yii\console\Controller;

abstract class EtlController extends Controller
{
    /**
     * 原始数据抓取层
     * @param $from
     * @param $to
     * @return mixed
     */
    abstract public function actionExtract($from, $to);

    /**
     * 处理计算逻辑层
     * @param $from
     * @param $to
     * @return mixed
     */
    abstract public function actionTransform($from, $to);

    /**
     * 数据展现层
     * @param $from
     * @param $to
     * @return mixed
     */
    abstract public function actionLoad($from, $to);

    /**
     * 任务执行入口
     */
    public function actionRun()
    {

    }

    /**
     * kill当前正在运行的进程
     * @param string $command 类名
     * @param string $action 方法名
     */
    public function actionKillTask($command, $action = '')
    {
        $command = trim($command);
        $action = trim($action);
        $str = empty($action) ? '' : " | grep -i '$action'";
        $command = "ps aux | grep yii | grep -i '$command' {$str} | grep -v grep | grep -iv KillTask | grep -v 'sh -c'";
        exec($command, $out, $status);
        if (0 === $status) {
            foreach ($out as $run) {
                $l = preg_split("/[\s,]+/", $run);
                echo "正在kill任务: {$run}...\n";
                system("kill -s 9 {$l[1]}", $returnValue);
                if (0 === $returnValue) {
                    echo "kill成功!\n";
                } else {
                    echo "kill失败!\n";
                }
            }
        } else {
            echo "执行任务失败==>{$command}\n";
        }
    }

    /**
     * 打印系统中正在运行的进程
     * @param string $command 进程名
     */
    public function actionPrintRunningTask($command = '')
    {
        $command = trim($command);
        if (empty($command)) {
            $command = "ps aux | grep yii | grep -v PrintRunningTask | grep -v grep";
        } else {
            $command = "ps aux | grep yii | grep -v PrintRunningTask | grep -i {$command} | grep -v grep";
        }
        exec($command, $out, $status);
        var_export($out);
    }
}
