<?php

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Client.php';

$dataId      = 'CalculatorService';
$group       = 'api';
$serviceName = 'father';
$namespace   = 'public';

$config = include __DIR__.'/config.php';
var_dump($config);exit;

$client      = new \Xiaosongshu\Nacos\Client('http://127.0.0.1:8848','nacos','nacos');
var_dump("发布配置");
/** 发布配置 */
$client->publishConfig($dataId, $group, json_encode(['name' => 'fool', 'bar' => 'ha']));
//todo 这个时候你可以手动到nacos服务器上修改一下配置，查看结果
while (true){
    sleep(1);
    $listener = $client->listenerConfig($dataId, $group, json_encode(['name' => 'fool', 'bar' => 'ha']));
    if ($listener['content']){
        var_dump("配置发生了变化",$listener['content']);
        $newConfigContent = $client->getConfig($dataId, $group,'public');
        var_dump($newConfigContent);
        //todo 将配置写入你的文件或者加载到内存即可
    }else{
        var_dump("配置没有变化");
    }
}

