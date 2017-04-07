<?php

namespace console\controllers;

use yii\console\Controller;

class DefaultController extends Controller
{
    public function actionTest($from, $to)
    {
        while (true) {
            $dates = \common\base\Helper::getInstance()->datesBetween($from, $to);
            print_r($dates);
            echo "\n";
        }
    }

    public function actionTest1($from, $to)
    {
        while (true) {
            $dates = \common\base\Helper::getInstance()->datesBetween($from, $to);
            print_r($dates);
            echo "\n";
        }
    }
}
