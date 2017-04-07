<?php

namespace console\controllers;

/**
 * 命令管理
 */
class TaskController extends \yii\console\Controller
{
    /**
     * 结束正在运行的命令
     *
     * @param $commandName
     */
    public function actionKill($commandName)
    {
        $pidList = [];
        $command = "ps aux | grep yii | grep -i '{$commandName}' | grep -v grep | grep -iv 'task/kill' | grep -v 'sh -c'";
        exec($command, $out, $status);
        if (0 === $status) {
            foreach ($out as $run) {
                $pid = preg_split("/[\s,]+/", $run)[1];
                $pidList[] = $pid;
                echo "正在kill任务: {$run}...\n";
                system("kill -s 9 {$pid}", $returnValue);
                if (0 === $returnValue) {
                    echo "kill成功!\n";
                } else {
                    echo "kill失败!\n";
                }
            }

            // 因为命令关联性原因，需要继续kill命令，当前记录最小pid加1为单个命令sh -c命令执行，需要kill具体命令
            $maxPid = max($pidList);
            $count = count($pidList) - 1;
            $currentPid = $maxPid + $count;
            while ($count--) {
                $currentPid += 1;
                echo "正在 kill -s 9 {$currentPid} 任务\n";
                system("kill -s 9 {$currentPid}", $returnValue);
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
     * 打印正在运行的命令
     *
     * @param string $commandName
     */
    public function actionPrint($commandName = '')
    {
        $command = "ps aux | grep yii | grep -v 'task/print' | grep -v grep";
        if (trim($commandName)) {
            $command .= " | grep -i '{$commandName}'";
        }
        exec($command, $out, $status);
        var_export($out);
    }
}
