<?php

namespace console\controllers;

use yii\console\Controller;

/**
 * swoole_process多进程使用管道机制来进程进程间通信的实例，比较容易的代替pcntl扩展
 * pcntl扩展的子进程之间的通信机制比较难以控制，而且经常有未知的处理问题
 * @package console\controllers
 */
class ProcessController extends Controller
{
    protected $redirectStdout = false;
    protected $workerNum = 2;
    protected $workers = [];

    public function init()
    {
        parent::init();
        // 注册信号回收机制，子进程执行完毕，执行一些补充工作，比如通知任务执行完毕，发送邮件等操作
        \swoole_process::signal(SIGCHLD, [$this, 'finished']);
    }

    /**
     * 创建多进程保存池
     */
    public function multi()
    {
        for ($i = 0; $i < $this->workerNum; $i++) {
            // 使用 new swoole_process 创建进程，这里需要一个参数，也就是回调函数，函数脚本直接传入字符串函数名，对象时需要连同对象一起传递
            $process = new \swoole_process([$this, 'execute'], $this->redirectStdout);
            // 当我们使用 $process->start()执行后，返回这个进程的pid ，也就是 $pid
            $pid = $process->start();
            $this->workers[$pid] = $process;
        }
    }

    /**
     * 子进程启动，调用回调函数，并传一个参数 也就是 swoole_process 类型的 $worker
     * pipe 进程的管道id
     * pid 就是当前子进程的 pid
     *
     * @param \swoole_process $worker
     */
    public function execute(\swoole_process $worker)
    {
        // 子进程尝试读取数据
        $receive = $worker->read();
        echo "\nFrom Master: {$receive}\n";
        $worker->write("hello master, this pipe is " . $worker->pipe . "; this pid is " . $worker->pid . "\n");
        $worker->exit();
    }

    /**
     * 主进程执行
     */
    public function actionImport()
    {
        $this->multi();

        /**
         * @var $process \swoole_process 子进程的句柄
         */
        foreach ($this->workers as $pid => $process) {
            // 子进程句柄向自己管道里写内容
            $process->write('Hello, worker pid: ' . $pid);
            // 子进程句柄从自己的管道里面读取信息
            echo "\nFrom worker: " . $process->read();
            echo "\n";
        }
    }

    /**
     * 子进程结束必须要执行wait进行回收，否则子进程会变成僵尸进程
     *
     * 信号发生时可能同时有多个子进程退出,必须循环执行wait直到返回false
     * @param $signo
     */
    public function finished($signo)
    {
        // $blocking 参数可以指定是否阻塞等待，默认为阻塞
        // 操作成功会返回返回一个数组包含子进程的PID、退出状态码、被哪种信号KILL
        // $result = array('code' => 0, 'pid' => 15001, 'signal' => 15);
        while (($result = \swoole_process::wait())) {
            echo "\npid: {$result['pid']}, code: {$result['code']}, signal: {$result['signal']} execting\n";
        }
    }
}
