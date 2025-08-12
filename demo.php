<?php

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Client.php';

$dataId      = 'CalculatorService';
$group       = 'api';
$serviceName = 'father1';
$namespace   = 'public';

$client      = new \Xiaosongshu\Nacos\Client('http://127.0.0.1:8848','nacos','nacos@123456');

//var_dump("发布配置");
///** 发布配置 */
//print_r($client->publishConfig($dataId, $group, json_encode(['name' => 'fool', 'bar' => 'ha'])));
//var_dump("获取配置需要稍等一秒左右，否则是404");
///** 获取配置 */
//print_r($client->getConfig($dataId, $group,'public'));
//
//var_dump("监听配置是否发生了变化，有变化则有返回值，无变化则返回空,同时监听配置会阻塞1秒左右");
//while(true){
//    var_dump(date('Y-m-d H:i:s'));
//    /** 监听配置 */
//    print_r($client->listenerConfig($dataId, $group, json_encode(['name' => 'fool', 'bar' => 'ha'])));
//    sleep(1);
//    var_dump(date('Y-m-d H:i:s'));
//}


//var_dump("创建服务，一个服务可以有多个实例提供，一个实例只可以提供 一个服务，但是我这是服务于fpm所以可以一个实例节点可以提供多个服务");
///** 创建服务 */
//print_r($client->createService($serviceName, $namespace, ['name' => 'tom', 'age' => 15]));

var_dump("给服务分配一个实例，否则服务是空的，相当于餐馆没有厨师提供服务,这里有一个坑，如果是创建临时实例，则不要创建服务，直接创建实例并指定为临时实例，否则会默认为永久实例而发生冲突");
/** 创建实例 */
print_r($client->createInstance($serviceName, "127.0.0.1", '9506', $namespace, ['name' => 'tom', 'age' => 15], 50, 1, true));
$weight = 50;
while(true){
    print_r($client->updateWeight($serviceName,"127.0.0.1", '9506',$weight--,$namespace,true,['name' => 'tom', 'age' => 15]));
    sleep(5);
}

