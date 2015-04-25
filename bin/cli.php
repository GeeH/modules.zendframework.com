<?php
use Zend\Console\Console;
use ZF\Console\Application;
use ZF\Console\Dispatcher;
use ZfModule\Service\Update;

chdir(__DIR__ . '/../');
require 'vendor/autoload.php'; // grabs the Composer autoloader
error_reporting(E_ALL ^ E_WARNING);
set_time_limit(0);

$application = Zend\Mvc\Application::init(require 'config/application.config.php');

$services = $application->getServiceManager();

$updateModel = $services->get(Update::class);

$dispatcher = new Dispatcher();
$dispatcher->map('update', $updateModel);

$application = new Application(
    'Module Update',
    0.1,
    [
        [
            'name'              => 'update',
            'route'             => 'update',
            'description'       => 'Updates all modules ratings',
            'short_description' => 'Updates ratings',
        ],
    ],
    Console::getInstance(),
    $dispatcher
);
$exit        = $application->run();
exit($exit);