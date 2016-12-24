<?php

namespace console\modules\etl\controllers;

use yii\console\Controller;

/**
 * Default controller for the `Etl` module
 */
class DefaultController extends Controller
{
    /**
     * Renders the index view for the module
     */
    public function actionIndex()
    {
        echo __METHOD__ . PHP_EOL;
    }

    public function actionT()
    {
        echo __CLASS__ . PHP_EOL;
    }
}
