<?php

namespace console\modules\etl\controllers;

use console\controllers\PlatformController;

/**
 * Default controller for the `Etl` module
 */
class DefaultController extends PlatformController
{
    public function actionTest()
    {
        $this->addRun('default/test', []);
    }
}
