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
        $this->addRun('task/kill', ['commandName' => 'etl/default/test']);
    }

    public function actionT()
    {
        $this->addRun('task/print');
    }

    public function actionTest()
    {
        $this->addRun('default/test', ['from' => '2017-01-01', 'to' => '2017-02-01']);
        $this->addRun('default/test1', ['from' => '2017-01-01', 'to' => '2017-02-01']);
    }
}
