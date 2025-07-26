<?php

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Client.php';

$dataId      = 'CalculatorService';
$group       = 'api';
$serviceName = 'father';
$namespace   = 'public';

$client      = new \Xiaosongshu\Nacos\Client('http://127.0.0.1:8848','nacos','nacos');
//var_dump("发布配置");
///** 发布配置 */
//print_r($client->publishConfig($dataId, $group, json_encode(['name' => 'fool', 'bar' => 'ha'])));
//var_dump("获取配置需要稍等一秒左右，否则是404");
///** 获取配置 */
//print_r($client->getConfig($dataId, $group,'public'));
//
//var_dump("监听配置是否发生了变化，有变化则有返回值，无变化则返回空,同时监听配置会阻塞1秒左右");
///** 监听配置 */
//print_r($client->listenerConfig($dataId, $group, json_encode(['name' => 'fool', 'bar' => 'ha'])));
//
//var_dump("创建服务，一个服务可以有多个实例提供，一个实例只可以提供 一个服务，但是我这是服务于fpm所以可以一个实例节点可以提供多个服务");
///** 创建服务 */
//print_r($client->createService($serviceName, $namespace, json_encode(['name' => 'tom', 'age' => 15])));

var_dump("给服务分配一个实例，否则服务是空的，相当于餐馆没有厨师提供服务,这里有一个坑，如果是创建临时实例，则不要创建服务，直接创建实例并指定为临时实例，否则会默认为永久实例而发生冲突");
/** 创建实例 */
print_r($client->createInstance($serviceName, "192.168.4.110", '9506', $namespace, ['name' => 'tom', 'age' => 15], 50, 1, true));

//var_dump("获取服务列表，查看nacos上已注册了哪些服务");
///** 获取服务列表 */
//print_r($client->getServiceList($namespace));
//
//var_dump("查看这个服务的详细信息");
///** 服务详情 */
//print_r($client->getServiceDetail($serviceName, $namespace));

var_dump("查看这个服务下有哪些实例可以提供这个服务，就是类似这个餐馆有哪些厨师可以提供炒菜服务");
/** 获取实例列表 */
print_r($client->getInstanceList($serviceName, $namespace));

var_dump("给临时实例发送一次心跳，发送心跳的参数必须和创建实例的参数一致才可以，否则实例不健康");
/** 发送心跳 */
//sleep(1);print_r($client->sendBeat($serviceName, '192.168.4.110', '9506', $namespace,['name' => 'tom', 'age' => 15], true,50));
var_dump("发送心跳之后，再次获取实例列表，确认健康状态");
print_r($client->getInstanceList($serviceName, $namespace));


//var_dump("获取实例的详情");
//
///** 获取实例详情 */
//print_r($client->getInstanceDetail($serviceName, true, '192.168.4.110', '9506'));
//
//
//
//var_dump("更新实例健康,对临时实例不可手动修改健康");
//print_r($client->updateInstanceHealthy($serviceName,$namespace,'192.168.4.110','9506',false));
//
//var_dump("移除某一个实例，要删除的实例必须和当初创建的实例的参数一致才可以删除");
///** 移除实例*/
//print_r($client->removeInstance($serviceName, '192.168.4.110', 9506, $namespace, true));