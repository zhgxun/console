<?php

namespace console\controllers;

use common\base\swoole\Swoole;
use yii\console\Controller;

/**
 * swoole_server服务器实例
 * @package console\controllers
 */
class SwooleController extends Controller
{
    public function actionStart()
    {
        $serv = Swoole::getInstance()->connect();
    }
}
