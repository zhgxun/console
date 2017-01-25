<?php

namespace console\controllers;

use yii\console\Controller;
use yii\console\Exception;

/**
 * 后台跑数脚本管理
 * @package console\controllers
 */
class TaskController extends Controller
{
    // 日志文件目录
    private $logDir = null;
    // 当前PHP进程ID
    private $myPid;
    // 唯一标识符
    private static $currentUniqueId;
    // 当前运行命令
    private $currentCmd;
    // 当前脚本引导名称
    private $scriptName;

    public function init()
    {
        parent::init();

        if (!function_exists('pcntl_signal')) {
            exit('pcntl_signal functions are not available.');
        }

        $this->myPid = getmypid();
        $this->setLogDir();
        // 清空目录下所有的文件
        array_map('unlink', glob($this->logDir . '/*'));

        $this->setScriptName('./yii');
        $this->currentCmd = $this->getScriptName() . ' ' .$this->getParams()[0];

        // 初始化当前PHP进程ID信息
        $this->initLog();

        // SIGCHLD 子进程结束时, 父进程会收到这个信号
        declare(ticks=1);
        pcntl_signal(SIGCHLD, [&$this, 'signal'], true);
    }

    /**
     * 设置日志输出目录
     */
    protected function setLogDir()
    {
        if ($this->logDir == null) {
            $path = \Yii::$app->params['logPath'];
            if (!is_dir($path)) {
                $path = '/tmp';
            }
            $this->logDir = $path . "/command_" . date("Ymd") . "_" . $this->myPid;
            if (!is_dir($this->logDir) && !mkdir($this->logDir)) {
                exit('Failed create ' . $this->logDir);
            }
        }
    }

    /**
     * 设置当前脚本名称
     * @param $scriptName
     */
    protected function setScriptName($scriptName)
    {
        $this->scriptName = $scriptName;
    }

    /**
     * 获得当前脚本名称
     * @return string
     */
    protected function getScriptName()
    {
        return $this->scriptName;
    }

    /**
     * 获取参数名称
     * @return array
     */
    protected function getParams()
    {
        $rawParams = [];
        if (isset($_SERVER['argv'])) {
            $rawParams = $_SERVER['argv'];
            array_shift($rawParams);
        }

        $params = [];
        foreach ($rawParams as $param) {
            if (preg_match('/^--(\w+)(=(.*))?$/', $param, $matches)) {
                $name = $matches[1];
                $params[$name] = isset($matches[3]) ? $matches[3] : true;
            } else {
                $params[] = $param;
            }
        }
        return $params;
    }

    /**
     * 初始化当前PHP进程ID的日志内容，当前机器，用户，命令，日志文件路径等信息
     */
    protected function initLog()
    {
        $localIp = \common\base\Helper::getInstance()->getLocalIp();
        $whoami = exec("whoami");
        $content = "当前机器: {$localIp}, 当前用户: {$whoami}\n当前命令: {$this->currentCmd}\n日志文件路径: {$this->logDir}\n";
        file_put_contents($this->getMyPidFile()[0], $content);
    }

    /**
     * 获取PHP进程的ID日志保存文件
     * @return string
     */
    protected function getMyPidFile()
    {
        return [
            $this->logDir . "/{$this->myPid}_mypid.txt",
            $this->logDir . "/{$this->myPid}_myjobs.txt"
        ];
    }

    /**
     * 信号处理函数
     * @param int $signo
     * @param int $pid
     * @param null $status
     */
    protected function signal($signo = 0, $pid = 0, $status = null)
    {
        if (!$pid) {
            $pid = getmypid();
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        $exitCode = pcntl_wexitstatus($status);
        $this->process($pid, $exitCode);
    }

    /**
     * 处理任务执行完毕后的日志信息写入
     * @param $pid
     * @param $exitCode
     */
    protected function process($pid, $exitCode)
    {
        $jobs = $this->getJobList();
        $title = $jobs[$pid]['cmd'];
        $id = $jobs[$pid]['id'];
        list($outFile, $errorFile, $statusFile, $reportFile) = $this->getOutFiles($pid, $id);
        // kill掉任务时，写入异常日志到错误日志文件中
        if (15 == $exitCode || 143 == $exitCode) {
            $tailMessage = "\n\n$pid : 当前进程是被kill掉的\n";
            $tailMessage .= "--------------tail out-------------\n";
            $tailMessage .= shell_exec("tail $outFile");
            $tailMessage .= "--------------tail out-------------\n";
            $tailMessage .= "\n";
            file_put_contents($errorFile, $tailMessage, FILE_APPEND);
        }

        // 写入命令执行完毕的信息到输出文件结尾
        $beginDate = file_get_contents($statusFile);
        $endDate = date("Y-m-d H:i:s");
        $beginTime = strtotime($beginDate);
        $endMessage = sprintf("%s : 结束 \"%s\"\n%8s[begin:%s end:%s] %20s 历时:%10s", $pid, $title, '', $beginDate, $endDate, '', $this->getTimeDesc($beginTime));
        file_put_contents($outFile, "\n{$endMessage}\n", FILE_APPEND);
        file_put_contents($reportFile, "\n{$endMessage}\n", FILE_APPEND);

        // 将执行完毕的任务信息写入主PHP进程脚本中
        $content = file_get_contents($errorFile);
        $message = '';
        if (!empty($content)) {
            $message .= "---------------error---------------\n";
            $message .= sprintf("%s : %s\n\n%s\n", $pid, $title, $content);
            $message .= "---------------error---------------\n";
        }
        file_put_contents($this->getMyPidFile()[0], sprintf("%s\n%s\n\n", $message, $endMessage), FILE_APPEND);

        // 写入执行完毕的信息到日志状态status文件
        file_put_contents($statusFile, 'done');
    }

    /**
     * 记录一个标识符
     * @return mixed
     */
    protected static function makeId()
    {
        return ++self::$currentUniqueId;
    }

    /**
     * 得到唯一标识
     * @return mixed
     */
    protected static function getId()
    {
        return self::$currentUniqueId;
    }

    /**
     * 执行命令
     * @param $commandName
     * @param array $params
     */
    public function addRun($commandName, $params = [])
    {
        // 获得类似请求路劲 ./yii default/test 2016-10-01 2016-12-01
        $cmd = $this->getCmd($commandName, $params);
        // 生成一个唯一标识符
        $uniqueId = self::makeId();

        // pcntl_fork — 在当前进程当前位置产生分支（子进程）
        // 译注：fork是创建了一个子进程，父进程和子进程都从fork的位置开始
        // 向下继续执行，不同的是父进程执行过程中，得到的fork返回值为子进程号，而子进程得到的是0。

        // 父进程和子进程都会执行下面代码
        $pid = pcntl_fork();
        if ($pid == -1) {
            error_log('Could not fork new process');
            //return false;
        } elseif ($pid) {
            // 父进程会得到子进程号，所以这里是父进程执行的逻辑
            // 将当前启动的进程工作信息保存到启动进程的PHP脚本文件中
            $job = [
                'pid' => $pid,
                'cmd' => $cmd,
                'command' => $commandName,
                'params' => $params,
                'begin_time' => time(),
                'need_mail_out_file' => false,
                'group_name' => '',
                'id' => $uniqueId,
                'task_detail_id' => 0
            ];
            file_put_contents($this->getMyPidFile()[1], serialize($job) . "\n", FILE_APPEND);
        } else {
            list($outFile, $errorFile, $statusFile, $reportFile) = $this->getOutFiles(getmypid(), $uniqueId);
            $titleInfo = sprintf("%s : 启动 \"%s\"\n", getmypid(), $cmd);
            file_put_contents($outFile, $titleInfo);
            file_put_contents($reportFile, $titleInfo);
            file_put_contents($statusFile, date("Y-m-d H:i:s"));
            // 同时向启动主脚本的PHP进程写入当前内容
            file_put_contents($this->getMyPidFile()[0], sprintf("%s\n", $titleInfo), FILE_APPEND);

            $exitStatus = $this->runCmd($commandName, $params, $outFile, $errorFile);
            file_put_contents($outFile, "\nexit status: {$exitStatus}\n", FILE_APPEND);
            exit($exitStatus);
        }
    }

    /**
     * 根据参数获取完整的命令列表
     *
     * @warning
     * 参数严格依赖顺序赋值
     *
     * @param $commandName
     * @param array $params
     * @return string
     * @throws Exception
     */
    protected function getCmd($commandName, $params = [])
    {
        $str = sprintf("%s %s", $this->getScriptName(), $commandName);
        foreach ($params as $key => $value) {
            if (!is_string($value) && !is_numeric($value)) {
                throw new Exception(error_log(sprintf("Parameter only allows string or number:\n%s -> %s\n%s\nLine:%s", $key, var_export($value, true), __METHOD__, __LINE__)));
            }
            $str .= sprintf(' %s', $value);
        }
        return $str;
    }

    /**
     * 得到输出日志文件路径
     * @param $pid
     * @param int $id
     * @return array
     */
    protected function getOutFiles($pid, $id = 0)
    {
        if (!$id) {
            $id = self::getId();
        };
        return [
            $this->logDir . "/{$pid}_{$id}_out.txt",
            $this->logDir . "/{$pid}_{$id}_error.txt",
            $this->logDir . "/{$pid}_{$id}_status.txt",
            $this->logDir . "/{$pid}_{$id}_report.txt",
        ];
    }

    /**
     * 运行命令, 输出正确和错误日志到对应的文件中
     *
     * @param $commandName
     * @param array $params
     * @param null $outFile
     * @param null $errorFile
     * @return mixed
     */
    protected function runCmd($commandName, $params = [], $outFile = null, $errorFile = null)
    {
        $cmd = sprintf("%s >>%s 2>>%s", $this->getCmd($commandName, $params), $outFile, $errorFile);
        $lastLine = system($cmd, $returnValue);
        return $returnValue;
    }

    /**
     * 时间段描述
     * @param $beginTime
     * @param null $endTime
     * @return string
     */
    protected function getTimeDesc($beginTime, $endTime = null)
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

    /**
     * 获取当前主进程所有工作列表
     * @return array
     */
    protected function getJobList()
    {
        $jobsFile = $this->getMyPidFile()[1];
        if (!file_exists($jobsFile)) {
            exit("file not found");
        }
        $jobList = [];
        $jobs = file($jobsFile);
        foreach ($jobs as $job) {
            $target = unserialize($job);
            $jobList[$target['pid']] = $target;
        }
        return $jobList;
    }

    /**
     * 等待当前所有的任务执行完毕
     */
    public function wait()
    {
        $jobs = $this->getJobList();
        while (count($jobs)) {
            sleep(1);
        }
    }
}
