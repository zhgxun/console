<?php

namespace console\controllers;

use yii\console\Controller;

class RunnerController extends Controller
{
    protected $beginTime;
    protected $endTime;
    protected $parentPid;
    protected $parentReportFile;
    protected $outDir;
    protected $scriptName;
    protected $currentJobs;
    protected static $uniqueId;
    protected $currentCommand;

    public function init()
    {
        parent::init();
        $this->beginTime = time();
        $this->parentPid = getmygid();
        $this->parentReportFile = '';
        $this->outDir = $this->getOutDir();
        // 删除日志输出目录下所有的已有日志文件
        array_map('unlink', glob($this->outDir . '/*'));
    }

    /**
     * 允许的日志输出目录
     * @return string
     */
    protected function getOutDir()
    {
        return '';
    }

    public static function makeId()
    {
        return ++self::$uniqueId;
    }

    public static function getId()
    {
        return self::$uniqueId;
    }

    /**
     * 设置脚本名
     * @param $scriptName
     */
    public function setScriptName($scriptName)
    {
        $this->scriptName = $scriptName;
    }

    /**
     * 获取脚本名
     * @return mixed
     */
    public function getScriptName()
    {
        return $this->scriptName;
    }

    /**
     * 信号处理函数
     * @param $signo
     * @param null $pid
     * @param null $status
     * @return bool
     */
    public function childSignalHandler($signo, $pid = null, $status = null)
    {
        if (!$pid) {
            // int pcntl_waitpid ( int $pid , int &$status [, int $options = 0 ] )
            // 等待或返回fork的子进程状态
            // 挂起当前进程的执行直到参数pid指定的进程号的进程退出， 或接收到一个信号要求中断当前进程或调用一个信号处理函数。
            // 如果pid指定的子进程在此函数调用时已经退出（俗称僵尸进程），此函数将立刻返回。
            // 参数pid的值可以是以下之一：
            // < -1	等待任意进程组ID等于参数pid给定值的绝对值的进程。
            // -1	等待任意子进程;与pcntl_wait函数行为一致。
            // 0	等待任意与调用进程组ID相同的子进程。
            // > 0	等待进程号等于参数pid值的子进程。
            //
            // pcntl_waitpid()将会存储状态信息到status参数上，这个通过status参数返回的状态信息可以用以下函数
            // pcntl_wifexited(),
            // pcntl_wifstopped(),
            // pcntl_wifsignaled(),
            // pcntl_wexitstatus(),
            // pcntl_wtermsig()以及
            // pcntl_wstopsig() 获取其具体的值。
            //
            // 如果您的操作系统（多数BSD类系统）允许使用wait3，您可以提供可选的options 参数。
            // 如果这个参数没有提供，wait将会被用作系统调用。如果wait3不可用，提供参数 options不会有任何效果。
            // options的值可以是0 或者以下两个常量或两个常量“或运算”结果（即两个常量代表意义都有效）。
            // WNOHANG	如果没有子进程退出立刻返回。
            // WUNTRACED 子进程已经退出并且其状态未报告时返回。
            //
            // pcntl_waitpid()返回退出的子进程进程号，发生错误时返回-1,如果提供了 WNOHANG作为option（wait3可用的系统）
            // 并且没有可用子进程时返回0。
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }

        // 有返回退出的子进程号
        while ($pid > 0) {
            if ($pid && isset($this->currentJobs[$pid])) {
                // 返回一个中断的子进程的返回代码
                $exitCode = pcntl_wexitstatus($status);
                if ($exitCode != 0) {
                    $this->pushReport("$pid exited with status " . $exitCode . "\n");
                }
                $this->processExitedJob($pid, $exitCode);
                unset($this->currentJobs[$pid]);
            } else if ($pid) {
                echo "..... Adding $pid to the signal queue ..... status: $status \n";
                // 加入队列
            }
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }
        return true;
    }

    /**
     * 进程执行完毕后报告生成
     * @param $pid
     * @param $exitCode
     */
    protected function processExitedJob($pid, $exitCode)
    {
        $title = $this->currentJobs[$pid]['cmd'];
        $id = $this->currentJobs[$pid]['id'];
        $task_detail_id = $this->currentJobs[$pid]['task_detail_id'];
        $needMailOutFile = $this->currentJobs[$pid]['need_mail_out_file'];
        list($outFile, $errorFile, $statusFile, $parentReportFile) = $this->getOutFiles($pid, $id);
        if (15 == $exitCode || 143 == $exitCode) {
            $tailMessage = "\n\n$pid : 当前进程是被kill掉的\n";
            $tailMessage .= "--------------tail out-------------\n";
            $tailMessage .= shell_exec("tail $outFile");
            $tailMessage .= "--------------tail out-------------\n";
            $tailMessage .= "\n";
            file_put_contents($errorFile, $tailMessage, FILE_APPEND);
        }

        $beginDate = file_get_contents($statusFile);
        $endDate = date("Y-m-d H:i:s");
        $beginTime = strtotime($beginDate);
        $endMessage = sprintf("%s : 结束 \"%s\"\n%8s[begin:%s end:%s] %20s 历时:%10s", $pid, $title, '', $beginDate, $endDate, '', $this->getDiffTimeString($beginTime));
        file_put_contents($outFile, "\n{$endMessage}\n", FILE_APPEND);

        $content = file_get_contents($errorFile);
        $message = '';
        if (!$content) {
            $message .= "---------------error---------------\n";
            $message .= sprintf("%s : %s\n\n%s\n", $pid, $title, $content);
            $message .= "---------------error---------------\n";
        }

        $this->pushReport(sprintf("%s\n%s\n\n", $message, $endMessage));

        if (!empty($message)) {
            $error = sprintf("当前机器：%s    当前用户：%s\n%s : %s\n\ncurrentCommand:%s\n\n异常文件:%s\n", \common\base\Helper::getLocalIp(), exec("whoami"), $pid, $title, $this->currentCommand, $errorFile);
        }

        if ($needMailOutFile) {
            $message = '';
            $attachments = array();
            if (shell_exec("wc $outFile | awk '{print $1}'") - 10 < 0) {
                $message = file_get_contents($outFile);
            } else {
                $message .= shell_exec("head -n 1 $outFile");
                $message .= "\n\n...\n\n\n";
                $message .= shell_exec("tail -n 4 $outFile");
                $attachments[] = $outFile;
            }
            // 如果需要发送邮件，该处为发邮件部分
        }

        file_put_contents($statusFile, 'done');
    }

    /**
     * 初始化当前命令开启报告
     * @param string $currentCommand
     */
    protected function initReport($currentCommand = '')
    {
        $reportFile = $this->getReportFile();
        $ip = \common\base\Helper::getLocalIp();
        // 版本控制器信息
        $whoAmi = exec("whoami");
        $branch = exec("hg branch");
        $version = exec("hg branches | grep $branch | awk '{print $2}'");
        file_put_contents($reportFile, sprintf("当前机器：%s    当前用户：%s    当前代码分支：%s@%s\ncurrentCmd:%s\n运行日志目录：%s\n\n", $ip, $whoAmi, $branch, $version, $currentCommand, $this->_out_dir));
        $this->pushParentReport($reportFile);
    }

    /**
     * 开启命令记录报告
     * @param $reportFile
     */
    public function pushParentReport($reportFile)
    {
        if (!$this->parentReportFile) {
            file_put_contents($this->parentReportFile, $reportFile);
        }
    }

    /**
     * 父进程的输出文件名
     * @return string
     */
    public function getReportFile()
    {
        return $this->outDir . "/{$this->parentPid}.report.txt";
    }

    public function pushReport($content)
    {
        $reportFile = $this->getReportFile();
        file_put_contents($reportFile, sprintf("%s\n", $content), FILE_APPEND);
    }

    /**
     * 当前进程ID的所有输出文件名
     * @param $pid
     * @param int $id
     * @return array
     */
    protected function getOutFiles($pid, $id = 0)
    {
        if (0 == $id) {
            $id = self::getId();
        }
        return [
            $this->outDir . "/{$pid}_{$id}_out.txt",
            $this->outDir . "/{$pid}_{$id}_error.txt",
            $this->outDir . "/{$pid}_{$id}.status",
            $this->outDir . "/{$pid}_{$id}.report"
        ];
    }

    /**
     * 返回时间区间描述
     * @param $beginTime
     * @param null $endTime
     * @return string
     */
    protected function getDiffTimeString($beginTime, $endTime = null)
    {
        if ($endTime === null) {
            $endTime = time();
        }
        $diff = $endTime - $beginTime;
        $totalLeft = '';
        if ($diff >= 3600) {
            $totalLeft .= sprintf("%10d小时", $diff / 3600);
            $diff %= 3600;
        }
        if ($diff >= 60) {
            $totalLeft .= sprintf("%02d分钟", $diff / 60);
            $diff %= 60;
        }
        if ($diff < 60) {
            $totalLeft .= sprintf("%02d秒", $diff);
        }
        return $totalLeft;
    }
}
