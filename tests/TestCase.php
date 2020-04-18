<?php

namespace InfinityNext\LaravelCaptcha\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Config;

class TestCase extends BaseTestCase
{
    public function setup() : void
    {
        parent::setup();

        $config = require(__DIR__ . "/../config/captcha.php");
        Config::set([ 'captcha' => $config, ]);
    }
}
