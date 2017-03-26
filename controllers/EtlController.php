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
}
