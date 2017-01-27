<?php

namespace console\controllers;

use yii\console\Controller;

/**
 * 多进程使用信号机制来进程进程间通信的实例
 * @package console\controllers
 */
class QueueController extends Controller
{
    protected $workerNum = 2;
    protected $workers = [];

    public function init()
    {
        parent::init();
        // 注册信号处理函数，做一些结束的处理工作
        \swoole_process::signal(SIGCHLD, [$this, 'finished']);
    }

    /**
     * 初始化进程池
     */
    public function multi()
    {
        for ($i = 0; $i < $this->workerNum; $i++) {
            // swoole_process 的创建默认是创建的管道，当想用消息队列时，记得把参数设成false(其实我发现不写也行)
            $process = new \swoole_process([$this, 'execute'], false, false);
            // useQueue要在start的之前调用
            $process->useQueue();
            $pid = $process->start();
            $this->workers[$pid] = $process;
        }
    }

    /**
     * 子进程执行
     * @param \swoole_process $worker
     */
    public function execute(\swoole_process $worker)
    {
        // 默认模式下，如果队列中没有数据，pop方法会阻塞等待
        $receive = $worker->pop();
        echo "pid:{$worker->pid} From master: {$receive}\n";
        // 子进程向主进程发送数据
        $worker->push("\nhehe\n");
        // $status是退出进程的状态码，如果为0表示正常结束，会继续执行PHP的shutdown_function，其他扩展的 清理工作
        // 多个子进程使用消息队列通讯一定写上 $process->exit(1)
        // 否则第一个子进程退出时，执行清理工作会把消息队列清除掉，导致执行错误
        $worker->exit(0);
    }

    /**
     * 多进程启动
     */
    public function actionImport()
    {
        $this->multi();

        /**
         * @var $process \swoole_process
         */
        foreach ($this->workers as $pid => $process) {
            $process->push("Hello, worker: {$pid}\n");
            //sleep(2);
            // 如果执行pop从消息队列中取数，会导致子进程execute中被阻塞，一直处于等待状态
            //$receive = $process->pop();
            //echo "From worker: {$receive}\n";
        }
    }

    /**
     * 子进程执行处理工作
     * @param $signo
     */
    public function finished($signo)
    {
        for ($i = 0; $i < $this->workerNum; $i++) {
            // 传入参数false为非阻塞模式
            $result = \swoole_process::wait();
            $pid = $result['pid'];
            unset($this->workers[$pid]);
            echo "Worker Exiting, pid={$pid}\n";
        }
    }
}
