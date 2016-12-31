<?php
declare(ticks = 1);

namespace console\controllers;

use yii\console\Controller;
use yii\console\Exception;

/**
 * 命令输出日志等管理
 * @package console\controllers
 */
class CommandController extends Controller
{
    // 当前脚本引导名称
    protected $scriptName;
    // 当前运行命令
    protected $currentCmd;
    // 任务数据年份环境变量字段
    protected $tmTaskDataYearField = 'tm_task_data_year';
    // 任务ID环境变量字段
    protected $tmTaskIdField = 'tm_task_id';
    // 总报告文件环境变量字段
    protected $tmTaskTotalReportField = 'tm_task_total_report';
    // 总报告文件环境变量字段
    protected $tmParentReportFileField = 'tm_parent_report_file';
    // 任务详情ID环境变量字段
    protected $tmTaskDetailIdField = 'tm_task_detail_id';
    // 唯一标识符
    private static $uniqueId;
    // 保存当前工作列表
    protected $currentJobs;
    // 当前最后一个子进程命令信息
    protected $lastSyncPid;
    // 日志文件输出目录
    protected $outDir;
    // 任务队列
    protected $signalQueue;
    protected $parentPid;
    protected $beginTime;
    protected $tmParentReportFile;
    protected $mailError;
    protected $mailOut;

    /**
     * 初始化环境参数
     */
    public function init()
    {
        parent::init();
        umask(0);

        $this->beginTime = time();
        $this->parentPid = getmygid();
        $this->tmParentReportFile = getenv($this->tmParentReportFileField);
        $outDir = $this->getOutDir();
        // 清空目录下所有的文件
        array_map('unlink', glob($outDir . '/*'));

        $this->setScriptName('./yii');
        $this->currentCmd = 'default/test';
        $this->initReport($this->currentCmd);

        if (!function_exists('pcntl_signal')) {
            error_log('pcntl_signal functions are not available.');
            return;
        }
        pcntl_signal(SIGCHLD, [&$this, 'childSignalHandler']);
    }

    /**
     * 记录一个标识符
     * @return mixed
     */
    public static function makeId()
    {
        return ++self::$uniqueId;
    }

    /**
     * 得到唯一标识
     * @return mixed
     */
    public static function getId()
    {
        return self::$uniqueId;
    }

    /**
     * 获得日志输出目录
     * @return string
     */
    public function getOutDir()
    {
        if (!$this->outDir) {
            $outDir = null;

            if (!$outDir || !is_dir($outDir)) {
                $outDir = \Yii::$app->params['logPath'];
            }
            if (!$outDir || !is_dir($outDir)) {
                $outDir = '/tmp';
            }
            $this->outDir = $outDir . "/command_" . date("Ymd") . "_" . $this->parentPid;
            if (!is_dir($this->outDir) && !mkdir($this->outDir, 0777, true)) {
                die('Failed to create folders...');
            }
        }
        return $this->outDir;
    }

    /**
     * 初始化任务报告,信息类似
     * 当前机器：127.0.0.1    当前用户：root    当前代码分支：default@64764:5aac759e5a17
     * currentCmd:./protected/yiic2014 popETL run
     * 运行日志目录：/home/www/flogs/financerunner_20161231003003_14257
     *
     * @param string $currentCmd
     */
    protected function initReport($currentCmd = '')
    {
        $reportFile = $this->getReportFile();
        $ip = \common\base\Helper::getInstance()->getLocalIp();
        $whoami = exec("whoami");
        $branch = exec("git branch");
        //$version = exec("git var -l | grep $branch | awk '{print $2}'");
        $version = exec("git branch -v");
        file_put_contents($reportFile, sprintf("当前机器：%s    当前用户：%s    当前代码分支：%s@%s\ncurrentCmd:%s\n运行日志目录：%s\n\n", $ip, $whoami, $branch, $version, $currentCmd, $this->outDir));
        $this->pushParentReport($reportFile);
    }

    /**
     * 同时向父报告写入路径
     * @param $reportFilePath
     */
    public function pushParentReport($reportFilePath)
    {
        if (!empty($this->tmParentReportFile)) {
            file_put_contents($this->tmParentReportFile, $reportFilePath);
        }
    }

    /**
     * 信号处理函数,命令执行完毕后的一些操作
     * @param $signo
     * @param null $pid
     * @param null $status
     * @return bool
     * @throws Exception
     */
    public function childSignalHandler($signo, $pid = null, $status = null)
    {
        if (!$pid) {
            // 返回退出的子进程进程号, 发生错误时返回-1, 如果提供了WNOHANG作为option(wait3可用的系统)并且没有可用子进程时返回0
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }
        if ($pid == -1) {
            throw new Exception("\nReturn the child process {$pid},\nMethod: " . __METHOD__ . "\nLine:" . __LINE__ . "\n");
        }

        while ($pid > 0) {
            if ($pid && isset($this->currentJobs[$pid])) {
                $exitCode = pcntl_wexitstatus($status);
                if ($exitCode != 0) {
                    $this->pushReport("$pid exited with status " . $exitCode . "\n");
                }
                $this->processExitedJob($pid, $exitCode);
                unset($this->currentJobs[$pid]);
            } else if ($pid) {
                echo "..... Adding $pid to the signal queue ..... status: $status \n";
                $this->signalQueue[$pid] = $status;
            }
            $pid = pcntl_waitpid(-1, $status, WNOHANG);
        }
        return true;
    }

    /**
     * 处理运行完成的工作状态,保存工作信息
     * @param $pid
     * @param $exitCode
     */
    protected function processExitedJob($pid, $exitCode)
    {
        $title = $this->currentJobs[$pid]['cmd'];
        $id = $this->currentJobs[$pid]['id'];
        $taskDetailId = $this->currentJobs[$pid]['task_detail_id'];
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
        if (!empty($content)) {
            $message .= "---------------error---------------\n";
            $message .= sprintf("%s : %s\n\n%s\n", $pid, $title, $content);
            $message .= "---------------error---------------\n";
        }
        $this->pushReport(sprintf("%s\n%s\n\n", $message, $endMessage));

        if (!empty($message)) {
            $mailContent = sprintf("当前机器：%s    当前用户：%s\n%s : %s\n\ncurrentCmd:%s\n\n异常文件:%s\n", \common\base\Helper::getInstance()->getLocalIp(), exec("whoami"), $pid, $title, $this->currentCmd, $errorFile);
            \common\mail\Admin::getInstance()->send('layouts/text', ['content' => $mailContent], '978771018@qq.com', [], $errorFile, '异常邮件');
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
                $attachments = $outFile;
            }
            \common\mail\Admin::getInstance()->send('layouts/text', ['content' => $message], '978771018@qq.com', [], $attachments, '邮件');
        }

        if (empty($this->tmParentReportFile)) {
            $reportMessage = $this->getFileContent(getenv($this->tmTaskTotalReportField));
            \common\models\task\Tasks::updateAll([
                'task_status' => 'over',
                'end_date' => date('YmdHis'),
                'report' => addslashes($reportMessage)
            ], ['id' => getenv($this->tmTaskIdField)]);
        }

        file_put_contents($statusFile, 'done');
    }

    /**
     * 时间段描述
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
     * 同步执行命令
     * @param $commandName
     * @param array $params
     * @param array $preJobs
     * @param bool $needMailOutFile
     */
    public function addSyncRun($commandName, $params = [], $preJobs = [], $needMailOutFile = false)
    {
        $options = array('async' => false, 'need_mail_out_file' => $needMailOutFile);
        $this->addRun($commandName, $params, $preJobs, $options);
        $this->wait();
    }

    /**
     * 添加待执行异步命令
     *
     * @param $commandName
     * @param array $params
     * @param array $preJobs
     * @param array $options
     * @throws Exception
     */
    public function addRun($commandName, $params = [], $preJobs = [], $options = [])
    {
        // 是否同步运行
        $async = isset($options['async']) ? $options['async'] : true;
        // 需要发送输出文件到邮件
        $needMailOutFile = isset($options['need_mail_out_file']) ? $options['need_mail_out_file'] : false;
        $groupName = isset($options['group_name']) ? $options['group_name'] : '';
        $taskDetailId = '';
        $cmd = $this->getCmd($commandName, $params);

        // 当前环境中存在待运行的任务ID,任务明细记录在一张任务表中,通过该记录查找命令详细
        if (getenv($this->tmTaskIdField)) {
            // 带重试功能
            $lastException = null;
            $success = false;
            $try = 0;
            while (!$success && $try++ < 10) {
                try {
                    // 将任务ID,命令等保存到task_detail表中
                    $taskDetailInfo = [
                        'parent_id' => getenv($this->tmTaskDetailIdField) ? getenv($this->tmTaskDetailIdField) : 0,
                        'task_id' => getenv($this->tmTaskIdField) ? getenv($this->tmTaskIdField) : 0,
                        'command' => $commandName,
                        'parameters' => \yii\helpers\Json::encode($params),
                        'data_year' => getenv($this->tmTaskDataYearField)
                    ];
                    if (($taskDetailId = \common\base\task\TaskDetail::getInstance()->add($taskDetailInfo))) {
                        $success = true;
                    }
                } catch (Exception $ex) {
                    $lastException = $ex;
                    if (strpos($ex->getMessage(), 'SQLSTATE[HY000] [2002] Operation now in progress') !== false) {
                        sleep(1);
                    } else {
                        error_log($try . "--" . date(DATE_ATOM) . "--\t" . $ex->getMessage() . "\n");
                    }
                }
            }
            // 重试失败,抛异常
            if (!$success) {
                throw $lastException;
            }
        }

        // 生成一个唯一标识符
        $id = self::makeId();

        // pcntl_fork — 在当前进程当前位置产生分支（子进程）
        // 译注：fork是创建了一个子进程，父进程和子进程都从fork的位置开始
        // 向下继续执行，不同的是父进程执行过程中，得到的fork返回值为子进程 号，而子进程得到的是0。

        // 父进程和子进程都会执行下面代码
        $pid = pcntl_fork();
        if ($pid == -1) {
            error_log('Could not launch new job, exiting');
            exit(3);
        } else if ($pid) {
            // 父进程会得到子进程号，所以这里是父进程执行的逻辑
            // 将当前启动的进程工作信息保存到全局数组
            $currentJobInfo = [
                'cmd' => $cmd,
                'command' => $commandName,
                'params' => $params,
                'begin_time' => time(),
                'need_mail_out_file' => $needMailOutFile,
                'group_name' => $groupName,
                'id' => $id,
                'task_detail_id' => $taskDetailId
            ];
            $this->currentJobs[$pid] = $currentJobInfo;
            if (!$async) {
                $this->lastSyncPid = sprintf("%s_%s", $pid, $id);
            }

            // 保存任务日志路径等到tasks表中
            if (getenv($this->tmTaskIdField)) {
                $outFile   = $this->outDir . "/" . $pid . "_" . $id . "_out.txt";
                $errorFile = $this->outDir . "/" . $pid . "_" . $id . "_error.txt";
                // 任务日志和运行状态等信息更新
                $tasksInfo = [
                    'out_file_path' => $outFile,
                    'error_file_path' => $errorFile,
                    'report_file_path' => getenv($this->tmTaskTotalReportField),
                    'task_status' => 'running',
                    'start_date' => date('YmdHis')
                ];
                \common\models\task\Tasks::updateAll($tasksInfo, ['id' => getenv($this->tmTaskIdField)]);
            }

            if (isset($this->signalQueue[$pid])) {
                echo "found $pid in the signal queue, processing it now \n";
                $this->childSignalHandler(SIGCHLD, $pid, $this->signalQueue[$pid]);
                unset($this->signalQueue[$pid]);
            }
            return sprintf("%s_%s", $pid, $id);
        } else {
            // 子进程得到的$pid为0, 所以这里是子进程执行的逻辑
            if (!$async && !$this->lastSyncPid) {
                // 如果是同步脚本，当前程序只用等待上一个同步程序就可以了
                $preJobs[] = $this->lastSyncPid;
            }
            // 等待同步执行脚本完成
            $this->waitPreJobs($preJobs);

            list($outFile, $errorFile, $statusFile, $parentReportFile) = $this->getOutFiles(getmypid(), $id);
            file_put_contents($outFile, sprintf("%s : %s\n", getmypid(), $cmd));
            file_put_contents($errorFile, '');
            file_put_contents($parentReportFile, '');
            file_put_contents($statusFile, date("Y-m-d H:i:s"));
            $o = sprintf("%s : 启动 \"%s\"\n", getmypid(), $cmd);
            if (!empty($preJobs)) {
                $o .= sprintf("%8s需要等待的任务有[%s]\n", '', implode(',', $preJobs));
            }
            $this->pushReport($o);

            if (getenv($this->tmTaskIdField)) {
                // 任务详情运行状态更新
                \common\models\task\TaskDetail::updateAll([
                    'task_status' => 'running',
                    'start_date' => date('YmdHis')
                ], ['id' => $taskDetailId]);
            }

            // 设置任务详情ID和父报告文件环境变量
            putenv("{$this->tmTaskDetailIdField}=" . $taskDetailId);
            putenv("{$this->tmParentReportFileField}=" . $parentReportFile);

            $exitStatus = $this->runCmd($commandName, $params, $outFile, $errorFile);
            file_put_contents($outFile, "\nexitStatus:$exitStatus\n", FILE_APPEND);

            // 任务执行完毕, 获取日志内容写入到数据库中
            if (getenv($this->tmTaskIdField)) {
                $outMessage = $this->getFileContent($outFile);
                $errorMessage = $this->getFileContent($errorFile);
                $reportMessage = $this->getFileContent($parentReportFile);
                $endDate = date("Y-m-d H:i:s");
                \common\models\task\TaskDetail::updateAll([
                    'task_status' => 'over',
                    'end_date' => $endDate,
                    'out_log' => $outMessage,
                    'error_log' => $errorMessage,
                    'report_log' => $reportMessage
                ], ['id' => $taskDetailId]);
            }
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
     * 设置当前脚本名称
     * @param $scriptName
     */
    public function setScriptName($scriptName)
    {
        $this->scriptName = $scriptName;
    }

    /**
     * 得到替换后的当前入口脚本名称
     *
     * @warning
     * 仅仅是项目入口文件yii等,非其它文件
     *
     * @notice
     * 该方式是因为可能存在一些分库等设置,将入口的yii脚本做一些临时变更,
     * 但又不想改变以往的环境变量等设置,因此拷贝了一份yii重命名为yii2016等
     *
     * @example
     * 比如传入脚本名称为: ./yii
     * 设置了环境变量数据年为: 2016
     * 替换后的脚本名称为: ./yii2016
     *
     * @return mixed
     */
    public function getScriptName()
    {
        // 如果存在环境变量，需要注意入口控制台脚本文件名
        if (getenv($this->tmTaskDataYearField)) {
            $dataYear = getenv($this->tmTaskDataYearField);
            $pattern = '/yii(\w){0,4}/';
            preg_match($pattern, $this->scriptName, $matches);
            $scriptName = preg_replace($pattern, 'yii' . $dataYear, $this->scriptName);
            return $scriptName ? $scriptName : $this->scriptName;
        }
        return $this->scriptName;
    }

    /**
     * 等待上一个工作完成
     * 根据状态文件判断
     * @param $preJobs
     */
    protected function waitPreJobs($preJobs)
    {
        foreach ($preJobs as $job) {
            list($pid, $id) = explode('_', $job);
            list($outFile, $errorFile, $statusFile) = $this->getOutFiles($pid, $id);
            while (!file_exists($statusFile)) {
                sleep(1);
            }
            while ('done' != ($status = file_get_contents($statusFile))) {
                // printf("%s 还没有跑完，现在状态是: %s\n", $statusFile, $status);
                sleep(1);
            }
        }
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
            $this->outDir . "/{$pid}_{$id}_out.txt",
            $this->outDir . "/{$pid}_{$id}_error.txt",
            $this->outDir . "/{$pid}_{$id}.status.txt",
            $this->outDir . "/{$pid}_{$id}.report.txt",
        ];
    }

    /**
     * 记录日志
     * @param $content
     */
    protected function pushReport($content)
    {
        $reportFile = $this->getReportFile();
        file_put_contents($reportFile, sprintf("%s\n", $content), FILE_APPEND);
        //同时向总报告里写入
        $this->pushAncestorTotalReport($content);
    }

    /**
     * 得到父报告日志文件路径
     * @return string
     */
    protected function getReportFile()
    {
        return $this->outDir . "/{$this->parentPid}.report.txt";
    }

    /**
     * 记录日志到总报告文件
     * @param $content
     */
    protected function pushAncestorTotalReport($content)
    {
        if (getenv($this->tmTaskTotalReportField)) {
            file_put_contents(getenv($this->tmTaskTotalReportField), sprintf("%s\n", $content), FILE_APPEND);
        }
    }

    /**
     * 运行命令, 输出正确和错误日志到对应的报告文件中
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
        system($cmd, $returnValue);
        return $returnValue;
    }

    /**
     * 得到精简文件内容
     * @param $fileName
     * @return string
     */
    protected function getFileContent($fileName)
    {
        $message = '';
        if (shell_exec("wc $fileName | awk '{print $1}'") - 300 < 0) {
            $message = file_get_contents($fileName);
        } else {
            $message .= shell_exec("head -n 100 $fileName");
            $message .= "\n\n...\n\n\n";
            $message .= shell_exec("tail -n 100 $fileName");
        }
        return $message;
    }

    /**
     * 等待当前所有的任务执行完毕
     */
    public function wait()
    {
        while (count($this->currentJobs)) {
            sleep(1);
        }
    }

    public function actionTest()
    {
//        putenv("{$this->tmTaskDataYearField}=2016");
//        \common\base\Helper::echoLn($this->getScriptName());
//        $params = [
//            'from' => '2016-10-01',
//            'to' => '2016-11-01',
//        ];
//        \common\base\Helper::echoLn($this->getCmd('default/test', $params));
        $s = \common\mail\Admin::getInstance()->send('layouts/text', ['content' => '测试邮件'], '978771018@qq.com', [], '', '邮件标题');
        var_export($s);
    }
}
