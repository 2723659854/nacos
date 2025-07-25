<?php

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__."/samples/DemoService.php";
require_once __DIR__."/samples/LoginService.php";

use Xiaosongshu\Nacos\Server;

// 加载配置文件
$config = require 'config.php';

// 启动服务

try{
    $server = new Server($config);
    $server->run();
}catch (Throwable $exception){
    var_dump($exception->getMessage());
}
