<?php

// 使用示例
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__."/src/Client.php";
require_once __DIR__."/src/JsonRpcClient.php";

use Xiaosongshu\Nacos\JsonRpcClient;

// Nacos配置（与服务端一致）
$nacosConfig = [
    'host' => 'http://192.168.110.72:8848',
    'username' => 'nacos',
    'password' => 'nacos'
];


// 实例化客户端：只需指定服务标识"demo"
$client = new JsonRpcClient($nacosConfig, 'demo');

// 调用add方法
$addResult = $client->request('add', ['tom', 18]);
if ($addResult['success']) {
    echo "添加结果：{$addResult['result']}（调用实例：{$addResult['instance']}）\n";
} else {
    echo "添加失败：{$addResult['error']}\n";
}

// 调用get方法
$getResult = $client->request('get', ['tom']);
if ($getResult['success']) {
    echo "查询结果：" . json_encode($getResult['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "查询失败：{$getResult['error']}\n";
}