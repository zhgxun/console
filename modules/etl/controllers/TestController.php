<?php

namespace console\modules\etl\controllers;

class TestController extends \console\controllers\EtlController
{
    public function actionExtract($from, $to)
    {
        // TODO: Implement actionExtract() method.
    }

    public function actionTransform($from, $to)
    {
        // TODO: Implement actionTransform() method.
    }

    public function actionLoad($from, $to)
    {
        // TODO: Implement actionLoad() method.
    }

    public function actionK()
    {
        $this->actionKill('default/c');
    }

    public function actionP()
    {
        $this->actionPrint();
    }
}
