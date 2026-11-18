<?php

use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;

define('LARAVEL_VERSION', Application::VERSION);

$app = new Application(dirname(__DIR__));
$app->instance('config', new Repository);
Facade::setFacadeApplication($app);
