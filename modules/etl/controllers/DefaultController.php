<?php

namespace console\modules\etl\controllers;

use console\controllers\PlatformController;

/**
 * Default controller for the `Etl` module
 */
class DefaultController extends PlatformController
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
        $this->addRun('default/test', ['from' => '2017-01-01', 'to' => '2030-02-01']);
        $this->addRun('default/a', ['from' => '2016-10-01', 'to' => '2016-12-01']);
        $this->addRun('default/b', ['from' => '2016-10-01', 'to' => '2016-12-01']);
        $this->addRun('default/c', ['from' => '2016-10-01', 'to' => '2016-12-01']);
        //$this->wait();
        //$this->report();
    }
}
