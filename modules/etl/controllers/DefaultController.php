<?php

namespace console\modules\etl\controllers;

use console\controllers\CommandController;

/**
 * Default controller for the `Etl` module
 */
class DefaultController extends CommandController
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

    public function actionTest()
    {
        $this->addRun('default/test', ['from' => '2016-10-01', 'to' => '2016-12-01']);
        //$this->wait();
        //$this->report();
    }
}
