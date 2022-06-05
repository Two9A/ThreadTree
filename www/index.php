<?php
require_once '../vendor/autoload.php';
require_once '../library/index.php';
session_start();

$config = bsFactory::get('config');
if ($config->debug) {
    error_reporting(E_ALL);
    ini_set('display_errors', 'On');
}

try {
    $dispatcher = bsFactory::get('dispatch');
    $dispatcher->set_path(isset($_GET['path']) ? $_GET['path'] : null);
    $view = $dispatcher->route();
}
catch (Exception $e) {
    $ec = new ErrorController();
    $view = $ec->get_view();
    $view->set_file($ec->indexAction($e));
}

$view->render();
