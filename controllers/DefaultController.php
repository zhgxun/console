<?php

namespace console\controllers;

use yii\console\Controller;

class DefaultController extends Controller
{
    public function actionTest()
    {
        $i = 100;
        while ($i--) {
            echo "Test\n";
        }
    }
}
