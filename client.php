<?php

// 使用示例
require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__."/src/Client.php";
require_once __DIR__."/src/JsonRpcClient.php";

use Xiaosongshu\Nacos\JsonRpcClient;

// 1. 配置Nacos连接信息（和服务端配置一致）
$nacosConfig = [
    'host' => 'http://192.168.4.110:8848',
    'username' => 'nacos',
    'password' => 'nacos'
];

// 2. 实例化客户端（指定要调用的服务名）
$client = new JsonRpcClient(
    $nacosConfig,
    \Xiaosongshu\Nacos\Samples\DemoService::class, // 服务名称（和服务端注册的一致）
    'public', // 命名空间
    5 // 超时时间
);

// 3. 调用服务方法（用户只需关心这一步）
$addResult = $client->request('add', ['tom', 18]);
if ($addResult['success']) {
    echo "添加结果：{$addResult['result']}（调用实例：{$addResult['instance']}）\n";
} else {
    echo "添加失败：{$addResult['error']}\n";
}

// 调用另一个方法
$getResult = $client->request('get', ['tom']);
if ($getResult['success']) {
    echo "查询结果：" . json_encode($getResult['result'], JSON_PRETTY_PRINT) . "\n";
} else {
    echo "查询失败：{$getResult['error']}\n";
}