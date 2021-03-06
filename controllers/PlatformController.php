<?php

namespace console\controllers;

use common\base\PidStatus;
use yii\console\Controller;
use yii\console\Exception;
use yii\helpers\Json;

/**
 * 跑数平台
 *
 * error_log()默认在命令重定向中会将输出定位到错误日志输出文件中
 *
 * swoole process 文档
 * @link https://wiki.swoole.com/wiki/page/p-process.html
 *
 * @notice 当前操作无法捕捉kill和ctrl+c造成的进程中断信号，但实际工作中，该操作还是希望收到进程被停止的通知
 *
 * @package console\controllers
 */
class PlatformController extends Controller
{
    /**
     * 输出日志目录
     * @var null
     */
    protected $outputDirectory = null;

    /**
     * 当前php进程ID
     * @var null
     */
    protected $myPid = null;

    /**
     * 设置每一个启动进程的PID文件，kill时使用
     * @var null
     */
    protected $myPidStatusFile = null;

    /**
     * 设置可执行文件名称，默认为框架入口脚本yii
     * @var null
     */
    protected $execFile = './yii';

    /**
     * 设置当前php进程总报告文件
     * @var null
     */
    protected $myPidReportFile = null;

    /**
     * 当前引导执行的命令名称
     * @var null
     */
    protected $currentCommand = null;

    /**
     * 保存当前正在运行的工作
     * @var array
     */
    protected $works = [];

    /**
     * 是否需要发送邮件通知
     * @var bool
     */
    protected $needSendEmail = false;

    /**
     * 初始化日志相关信息
     */
    public function init()
    {
        parent::init();
        $this->myPid = getmypid();

        // 输出目录
        $this->setOutputDirectory();
        array_map('unlink', glob($this->outputDirectory . '/*'));

        // 设置当前运行的命令名称
        $this->setCurrentCommand();

        // 设置进程文件
        $this->setPidFile();

        // 设置当前进程日志保存文件
        $this->setMyPidReportFile();

        // 当前进程总报告
        $this->initMyPidReport();

        // 17) SIGCHLD 子进程结束时, 父进程会收到这个信号
        \swoole_process::signal(SIGCHLD, [$this, 'finished']);
        // 2) SIGINT 程序终止(interrupt)信号, 在用户键入INTR字符(通常是Ctrl-C)时发出，用于通知前台进程组终止进程。
//        \swoole_process::signal(SIGINT, [$this, 'finished']);
        // 9) SIGKILL 用来立即结束程序的运行. 本信号不能被阻塞、处理和忽略。如果管理员发现某个进程终止不了，可尝试发送这个信号
//        \swoole_process::signal(SIGKILL, [$this, 'finished']);
        // 15) SIGTERM 程序结束(terminate)信号, 与SIGKILL不同的是该信号可以被阻塞和处理。通常用来要求程序自己正常退出，shell命令kill缺省产生这个信号。如果进程终止不了，我们才会尝试SIGKILL
//        \swoole_process::signal(SIGTERM, [$this, 'finished']);
    }

    /**
     * 获取输入日志目录
     *
     * @return null|string
     */
    protected function setOutputDirectory()
    {
        if ($this->outputDirectory === null) {
            $_outputDirectory = \Yii::$app->params['logPath'];
            // 配置文件未设置日志目录时，使用系统临时目录
            if (!$_outputDirectory || !is_dir($_outputDirectory)) {
                $_outputDirectory = '/tmp';
            }
            $this->outputDirectory = sprintf('%s/%s/%s', $_outputDirectory, date('Ymd'), $this->myPid);
            if (!is_dir($this->outputDirectory) && !mkdir($this->outputDirectory, 0777, true)) {
                exit('Failed to create folders...');
            }
            unset($_outputDirectory);
        }
        return $this->outputDirectory;
    }

    /**
     * 传递给该脚本的参数的数组。当脚本以命令行方式运行时，argv 变量传递给程序 C 语言样式的命令行参数。
     */
    protected function setCurrentCommand()
    {
        if ($this->currentCommand === null) {
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
            if (!count($params)) {
                throw new Exception(__METHOD__ . ", Line: " . __LINE__ . " Params is empty");
            }
            $this->currentCommand = $this->execFile . ' ' . $params[0];
            unset($rawParams, $params);
        }
    }

    /**
     * 保存进程记录
     */
    protected function setPidFile()
    {
        $path = dirname($this->outputDirectory);
        $this->myPidStatusFile = "{$path}/_pid_status.txt";
        PidStatus::write($this->myPid, $this->currentCommand, $this->myPidStatusFile);
    }

    /**
     * 设置当前php进程总报告文件
     */
    protected function setMyPidReportFile()
    {
        if ($this->myPidReportFile === null) {
            $this->myPidReportFile = sprintf('%s/%s_report.txt', $this->outputDirectory, $this->myPid);
        }
    }

    /**
     * 初始化当前php进程总报告日志
     */
    protected function initMyPidReport()
    {
        $ip = \common\base\Helper::getInstance()->getLocalIp();
        $whoami = exec("whoami");
        $branch = exec("git branch");
        $version = exec("git branch -v");
        file_put_contents($this->myPidReportFile, sprintf("当前机器: %s    当前用户: %s    当前代码分支: %s@%s\n当前引导命令: %s\n运行日志目录: %s\n\n",
            $ip, $whoami, $branch, $version, $this->currentCommand, $this->outputDirectory));
    }

    /**
     * 将命令拼接成shell可执行的格式
     *
     * @param $commandName
     * @param $params
     * @return string
     * @throws Exception
     */
    protected function getCmd($commandName, $params)
    {
        $str = sprintf("%s %s", $this->execFile, $commandName);
        foreach ($params as $key => $value) {
            if (!is_string($value) && !is_numeric($value)) {
                throw new Exception(sprintf("Parameter only allows string or number:\n%s -> %s\n%s\nLine:%s",
                    $key, var_export($value, true), __METHOD__, __LINE__));
            }
            $str .= sprintf(' %s', $value);
        }
        return $str;
    }

    /**
     * 调用system执行外部命令
     *
     * @param $command
     * @param $outFile
     * @param $errorFile
     * @return mixed
     */
    protected function runCmd($command, $outFile, $errorFile)
    {
        $cmd = sprintf("%s >>%s 2>>%s", $command, $outFile, $errorFile);
        $lastLine = exec($cmd, $output, $returnValue);
        return $returnValue;
    }

    /**
     * 执行命令
     *
     * @param $commandName
     * @param array $params
     * @throws Exception
     */
    public function addRun($commandName, $params = [])
    {
        $cmd = $this->getCmd($commandName, $params);
        $process = new \swoole_process([$this, 'execute'], false);
        if (($pid = $process->start()) === false) {
            throw new Exception(sprintf('ErrNo:%s, Error: %s', swoole_errno(), swoole_strerror(swoole_errno())));
        }

        // 保存准备的工作
        $this->works[$pid] = [
            'cmd' => $cmd,
            //'process' => $process
        ];
        // Master写入当前工作信息到管道
        $process->write(Json::encode($this->works));
    }

    /**
     * 所有输出日志文件名
     *
     * @param $pid
     * @return array
     */
    protected function getOutputFiles($pid = null)
    {
        if ($pid === null) {
            $pid = getmypid();
        }
        return [
            $this->outputDirectory . "/{$pid}_out.txt",
            $this->outputDirectory . "/{$pid}_error.txt",
            $this->outputDirectory . "/{$pid}_status.txt",
            $this->outputDirectory . "/{$pid}_report.txt",
        ];
    }

    /**
     * 往总报告中写入命令启动日志
     *
     * @param $content
     */
    protected function pushMyPidReport($content)
    {
        file_put_contents($this->myPidReportFile, sprintf("%s\n", $content), FILE_APPEND);
    }

    /**
     * 注册的回调函数
     *
     * @param \swoole_process $work
     * @throws Exception
     */
    public function execute(\swoole_process $work)
    {
        $pid = $work->pid;
        // Worker读取Master管道信息
        $receive = $work->read();
        $_works = Json::decode($receive, true);
        if (!isset($_works[$pid])) {
            throw new Exception("empty work->pid({$pid})");
        }
        $command = $_works[$pid]['cmd'];

        // 记录命令启动信息到日志文件中
        list($outFile, $errorFile, $statusFile, $reportFile) = $this->getOutputFiles();
        $titleInfo = sprintf("%s : 启动 \"%s\"\n", getmypid(), $command);
        file_put_contents($outFile, $titleInfo);
        file_put_contents($reportFile, $titleInfo);
        file_put_contents($statusFile, date("Y-m-d H:i:s"));

        // 命令启动记录到总报告中
        $this->pushMyPidReport($titleInfo);

        $exitStatus = $this->runCmd($command, $outFile, $errorFile);
        file_put_contents($outFile, "\nexitStatus:$exitStatus\n", FILE_APPEND);

        $work->close();
    }

    /**
     * 信号处理回调函数
     *
     * @param $signo
     */
    public function finished($signo = null)
    {
        // $blocking 参数可以指定是否阻塞等待，默认为阻塞
        while ($result = \swoole_process::wait(false)) {
            $pid = $result['pid'];
            $exitCode = $result['signal'];
            // 信号函数能直接共享主进程的内容
            $cmd = $this->works[$pid]['cmd'];
            list($outFile, $errorFile, $statusFile, $reportFile) = $this->getOutputFiles($pid);

            // 处理错误信息
            $interrupt = '';
            switch ($exitCode) {
                case 2:
                    $interrupt .= "该进程通常是Ctrl-C结束的\n";
                    break;
                case 9:
                    $interrupt .= "该进程是被kill掉的\n";
                    break;
                case 15:
                    $interrupt .= "程序结束(terminate)信号\n";
                    break;
                // 子进程结束时, 父进程会收到这个信号
                case 17:
                default:
            }
            if ($interrupt) {
                file_put_contents($errorFile, $interrupt, FILE_APPEND);
            }
            unset($interrupt);

            // 输出日志记录
            $beginDate = file_get_contents($statusFile);
            $endDate = date("Y-m-d H:i:s");
            $beginTime = strtotime($beginDate);
            $endMessage = sprintf("%s : 结束: \"%8s\" \n[begin:%s end:%s] 历时:%s", $pid, $cmd, $beginDate, $endDate, $this->getDiffTimeString($beginTime));
            file_put_contents($outFile, "{$endMessage}", FILE_APPEND);

            // 总报告日志记录
            $content = file_get_contents($errorFile);
            $message = '';
            if (!empty($content)) {
                $message .= "---------------error---------------\n";
                $message .= sprintf("pid: %s \n%s\n", $pid, $content);
                $message .= "---------------error---------------\n";
            }
            $this->pushMyPidReport(sprintf("%s\n%s", $message, $endMessage));

            // 标识命令执行完毕
            file_put_contents($statusFile, 'done');

            // 释放工作表
            unset($this->works[$pid]);

            // 发送邮件
            if ($this->needSendEmail) {
                $this->mail($pid);
            }
        }

        // 终止主进程
        if (!count($this->works)) {
            if ($this->needSendEmail) {
                // 总报告文件邮件
                $myPidContent = $this->getFileContent($this->myPidReportFile);
                if ($myPidContent) {
                    \common\mail\Admin::getInstance()->send('layouts/html', [
                        'content' => $myPidContent
                    ], '978771018@qq.com', [], $this->myPidReportFile, '跑数总报告');
                }
            }
            $this->kill($this->myPid);
        }
    }

    /**
     * 结束进程
     *
     * @param $pid
     */
    protected function kill($pid)
    {
        PidStatus::delete($pid, $this->myPidStatusFile);
        \swoole_process::kill($pid);
    }

    /**
     * 时间段描述
     *
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

    /**
     * 最小脚本跑数报告邮件
     *
     * @param $pid
     */
    protected function mail($pid)
    {
        // 对同一次执行的命令来说,除总报告外，共有4个文件
        list($outFile, $errorFile, $statusFile, $reportFile) = $this->getOutputFiles($pid);
        $outContent = $this->getFileContent($outFile);
        $errorContent = $this->getFileContent($errorFile);

        if ($outContent) {
            \common\mail\Admin::getInstance()->send('layouts/html', [
                'content' => $outContent
            ], '978771018@qq.com', [], $outFile, '跑数输出');
        }
        if ($errorContent) {
            \common\mail\Admin::getInstance()->send('layouts/html', [
                'content' => $errorContent
            ], '978771018@qq.com', [], $errorFile, '跑数报错');
        }
    }

    /**
     * 根据文件名获得文件内容，如果文件过大，会被截断
     *
     * @param $fileName
     * @return bool|string
     */
    protected function getFileContent($fileName)
    {
        $message = '';
        if (shell_exec("wc $fileName | awk '{print $1}'") - 300 < 0) {
            $message = file_get_contents($fileName);
        } else {
            $message .= shell_exec("head -n 100 $fileName");
            $message .= "\n...\n";
            $message .= shell_exec("tail -n 100 $fileName");
        }
        return $message;
    }

    /**
     * 等待当前工作全部执行完毕
     */
    public function wait()
    {
        $this->finished();
    }
}
