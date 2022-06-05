#!/usr/bin/env php
<?php
require_once '../vendor/autoload.php';
require_once '../library/index.php';
session_start();

$config = bsFactory::get('config');

$sock = new SocketserverModel();
$sock->start($config->ws_port);
