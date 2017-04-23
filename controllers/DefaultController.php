<?php

namespace console\controllers;

use yii\console\Controller;

class DefaultController extends Controller
{
    public function actionTest()
    {
        while (true) {
            echo "Test\n";
            sleep(30);
        }
    }
}
