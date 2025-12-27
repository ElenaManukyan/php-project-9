<?php

use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();

$renderer = new PhpRenderer(__DIR__ . '/../templates');

$app->get('/', function ($request, $response) use ($renderer) {
    return $renderer->render($response, "home.phtml");
});

$app->run();
