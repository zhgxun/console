<?php

namespace console\controllers;

class ElasticController extends \yii\console\Controller
{
    /**
     * 创建索引
     */
    public function actionCreate()
    {
        \common\base\log\RequestLog::getInstance()->create();
        echo "index" . \common\models\log\RequestLog::index() . " 创建成功\n";
    }

    /**
     * 删除索引
     */
    public function actionDelete()
    {
        \common\base\log\RequestLog::getInstance()->delete();
        echo "删除索引成功\n";
    }
}