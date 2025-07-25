简单的php版本nacos客户端

### 安装
```bash 
composer require xiaosongshu/nacos
```
#### 项目地址
```text
https://github.com/2723659854/nacos
```
### 客户端提供的方法
```php
<?php

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Client.php';

$dataId      = 'CalculatorService';
$group       = 'api';
$serviceName = 'father';
$namespace   = 'public';

$client      = new \Xiaosongshu\Nacos\Client('http://127.0.0.1:8848','nacos','nacos');
var_dump("发布配置");
/** 发布配置 */
print_r($client->publishConfig($dataId, $group, json_encode(['name' => 'fool', 'bar' => 'ha'])));
var_dump("获取配置需要稍等一秒左右，否则是404");
/** 获取配置 */
print_r($client->getConfig($dataId, $group,'public'));

var_dump("监听配置是否发生了变化，有变化则有返回值，无变化则返回空,同时监听配置会阻塞1秒左右");
/** 监听配置 */
print_r($client->listenerConfig($dataId, $group, json_encode(['name' => 'fool', 'bar' => 'ha'])));

var_dump("创建服务，一个服务可以有多个实例提供，一个实例只可以提供 一个服务，但是我这是服务于fpm所以可以一个实例节点可以提供多个服务");
/** 创建服务 */
print_r($client->createService($serviceName, $namespace, json_encode(['name' => 'tom', 'age' => 15])));

var_dump("给服务分配一个实例，否则服务是空的，相当于餐馆没有厨师提供服务,这里有一个坑，如果是创建临时实例，则不要创建服务，直接创建实例并指定为临时实例，否则会默认为永久实例而发生冲突");
/** 创建实例 */
print_r($client->createInstance($serviceName, "192.168.4.110", '9506', $namespace, ['name' => 'tom', 'age' => 15], 50, 1, true));

var_dump("获取服务列表，查看nacos上已注册了哪些服务");
/** 获取服务列表 */
print_r($client->getServiceList($namespace));

var_dump("查看这个服务的详细信息");
/** 服务详情 */
print_r($client->getServiceDetail($serviceName, $namespace));

var_dump("查看这个服务下有哪些实例可以提供这个服务，就是类似这个餐馆有哪些厨师可以提供炒菜服务");
/** 获取实例列表 */
print_r($client->getInstanceList($serviceName, $namespace));

var_dump("给临时实例发送一次心跳，发送心跳的参数必须和创建实例的参数一致才可以，否则实例不健康");
/** 发送心跳 */
sleep(1);print_r($client->sendBeat($serviceName, '192.168.4.110', '9506', $namespace,['name' => 'tom', 'age' => 15], true,50));
var_dump("发送心跳之后，再次获取实例列表，确认健康状态");
print_r($client->getInstanceList($serviceName, $namespace));

var_dump("获取实例的详情");

/** 获取实例详情 */
print_r($client->getInstanceDetail($serviceName, true, '192.168.4.110', '9506'));

var_dump("移除某一个实例，要删除的实例必须和当初创建的实例的参数一致才可以删除");
/** 移除实例*/
print_r($client->removeInstance($serviceName, '192.168.4.110', 9505, $namespace, true));
```

###  应用举例

####  监听配置

你可以编写一个listen.php文件，内容如下：
```php
<?php

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/src/Client.php';

$dataId      = 'CalculatorService';
$group       = 'api';
$serviceName = 'father';
$namespace   = 'public';
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


```
执行命令可以测试配置变化
```bash
php listen.php
```
####  注册发布服务
你可以编写一个server.php文件，内容如下
```php
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
```
其中配置文件config.php，内容如下：
```php
<?php

return [

    /** 连接服务器的基本配置 */
    'server'=>[
        'host'=>'http://192.168.110.72:8848',
        'username'=>'nacos',
        'password'=>'nacos',
    ],

    /** 服务提供者实例的配置 */
    'instance'=>[
        'ip'=>'192.168.110.72',
        'port'=>'8000',
        'weight'=>99,
    ],

    /** 需要监听的配置 */
    'config'=>[
        'first'=>[
            'enable'=>true,
            'dataId'=>'default',
            'group'=>'default',
            'content'=> [
                'username'=>'tom',
                'password'=>'123456',
                'age'=>25
            ],
        ]
    ],

    /** 需要注册的服务 */
    'service'=>[

        // 服务标识：demo
        'demo'=>[
            'enable'=>true,
            'serviceName'=>\Xiaosongshu\Nacos\Samples\DemoService::class,
            'namespace'=>'public',
        ],

        // 服务标识：login
        'login' => [
            'enable' => true,
            'serviceName' => \Xiaosongshu\Nacos\Samples\LoginService::class, // 实际实现类
            'namespace' => 'public',
            # 契约 也就是功能映射，客户端只关心调用login功能，不关心具体是执行哪个方法
            'contract' => [
                'out' => 'logout'
            ]
        ]
    ]
];
```
而服务提供者DemoService.php内容如下
```php
<?php
namespace Xiaosongshu\Nacos\Samples;

/**
 * @purpose 示例服务提供者
 * @author yanglong
 * @time 2025年7月25日12:16:19
 */
class DemoService
{

    /**
     * 示例方法：添加用户信息
     * @param string $name 姓名
     * @param int $age 年龄
     * @return string
     */
    public function add(string $name, int $age): string
    {
        return "用户添加成功！姓名：{$name}，年龄：{$age}（服务端处理时间：" . date('H:i:s') . "）";
    }

    /**
     * 示例方法：获取用户信息
     * @param string $name 姓名
     * @return array
     */
    public function get(string $name): array
    {
        return [
            'name' => $name,
            'age' => 25,
            'message' => "查询成功（服务端时间：" . date('H:i:s') . "）"
        ];
    }
}
```
服务提供者LoginService.php内容如下：
```php
<?php

namespace Xiaosongshu\Nacos\Samples;

/**
 * @purpose 用户登录服务
 * @author yanglong
 * @time 2025年7月25日17:32:27
 */
class LoginService
{
    /**
     * 用户登录接口
     * @param string $username 用户名（必填，长度≥3）
     * @param string $password 密码（必填，长度≥6）
     * @return array 登录结果，包含token
     */
    public function login(string $username, string $password): array
    {
        // 模拟业务逻辑：验证用户名密码
        if (strlen($username) < 3) {
            return ['success' => false, 'message' => '用户名长度不能少于3位'];
        }
        if (strlen($password) < 6) {
            return ['success' => false, 'message' => '密码长度不能少于6位'];
        }

        // 模拟生成token
        $token = md5($username . time() . 'secret');
        return [
            'success' => true,
            'message' => '登录成功',
            'token' => $token,
            'expire' => 3600 // token有效期（秒）
        ];
    }

    /**
     * 退出登录
     * @param string $token
     * @return array
     */
    public function logout(string $token): array{
        return [
            'success' => true,
            'message' => '退出登录成功',
            'token' => $token,
        ];
    }
}

```
编写完以上文件后，开启服务，命令如下：
```bash
php server.php
```
这个时候我们再编写一个客户端client.php，用来测试调用服务。内容如下：
```php
<?php

// 使用示例
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . "/src/Client.php";
require_once __DIR__ . "/src/JsonRpcClient.php";

use Xiaosongshu\Nacos\JsonRpcClient;

// Nacos配置（与服务端一致）
$nacosConfig = [
    'host' => 'http://192.168.110.72:8848',
    'username' => 'nacos',
    'password' => 'nacos'
];


$client = new JsonRpcClient($nacosConfig);

// 调用login服务（仅需传入服务标识和业务参数）
$loginResult = $client->call('login', [
    'username' => 'zhangsan',
    'password' => '123456'
]);

// 打印结果
if ($loginResult['success']) {
    echo "登录成功：\n";
    echo "Token：{$loginResult['result']['token']}\n";
    echo "有效期：{$loginResult['result']['expire']}秒\n";
    echo "调用实例：{$loginResult['instance']}\n";
} else {
    echo "登录失败：{$loginResult['error']}\n";
}


// 退出登录 使用契约
$logoutRes = $client->call('login', ['token' => $loginResult['result']['token']], 'out');
if ($logoutRes['success']) {
    echo "退出登录成功：\n";
    echo "Token：{$logoutRes['result']['token']}\n";
    echo "调用实例：{$logoutRes['instance']}\n";
} else {
    echo "退出登录失败：{$logoutRes['error']}\n";
}


// 3. 调用DemoService的add方法（添加用户）
$addResult = $client->call(
    'demo', // 服务标识（对应服务端配置中的serviceKey）
    [
        'name' => '张三',  // 对应add方法的$name参数
        'age' => 20        // 对应add方法的$age参数
    ],
    'add' // 要调用的方法名（DemoService的add方法）
);

// 处理add方法结果
if ($addResult['success']) {
    echo "添加用户结果：{$addResult['result']}\n";
    echo "调用的服务实例：{$addResult['instance']}\n\n";
} else {
    echo "添加用户失败：{$addResult['error']}\n\n";
}

// 4. 调用DemoService的get方法（查询用户）
$getResult = $client->call(
    'demo', // 服务标识（不变）
    [
        'name' => '张三'  // 对应get方法的$name参数
    ],
    'get' // 要调用的方法名（DemoService的get方法）
);

// 处理get方法结果
if ($getResult['success']) {
    echo "查询用户结果：\n";
    print_r($getResult['result']); // 打印数组结果
    echo "调用的服务实例：{$getResult['instance']}\n";
} else {
    echo "查询用户失败：{$getResult['error']}\n";
}
```
现在需要启动客户端来测试服务是否可用。命令如下：
```bash
php client.php
```
那么服务端测试结果大概效果是这样子的
```text
PS D:\php\nacosServer> php .\server.php
[初始化] 已加载服务：demo -> Xiaosongshu\Nacos\Samples\DemoService（元数据解析完成）
[初始化] 已加载服务：login -> Xiaosongshu\Nacos\Samples\LoginService（元数据解析完成）
[Nacos] 已注册服务：demo -> SERVICE@@demo（IP：192.168.110.72:8000）
[Nacos] 已注册服务：login -> SERVICE@@login（IP：192.168.110.72:8000）
[TCP服务] 已启动，监听：192.168.110.72:8000（JSON-RPC协议）
[心跳] 成功（demo）=>Xiaosongshu\Nacos\Samples\DemoService（18:52:45）
[心跳] 成功（login）=>Xiaosongshu\Nacos\Samples\LoginService（18:52:45）
[TCP] 新客户端连接：192.168.110.72:50210（clientId：80）
[TCP] 收到请求（192.168.110.72:50210）：{"jsonrpc":"2.0","method":"login.login","params":["zhangsan","123456"],"id":"rpc_688361ffce388"}
[TCP] 发送响应（192.168.110.72:50210）：{"jsonrpc":"2.0","id":"rpc_688361ffce388","result":{"success":true,"message":"登录成功","token":"2d7b6bd23bd38d6051bf06a2d55c6eca","expire":3600}}
[TCP] 新客户端连接：192.168.110.72:50211（clientId：81）
[TCP] 客户端断开（192.168.110.72:50210）
[TCP] 收到请求（192.168.110.72:50211）：{"jsonrpc":"2.0","method":"login.out","params":["2d7b6bd23bd38d6051bf06a2d55c6eca"],"id":"rpc_688361ffd588a"}
[TCP] 发送响应（192.168.110.72:50211）：{"jsonrpc":"2.0","id":"rpc_688361ffd588a","result":{"success":true,"message":"退出登录成功","token":"2d7b6bd23bd38d6051bf06a2d55c6eca"}}
[TCP] 新客户端连接：192.168.110.72:50212（clientId：82）
[TCP] 客户端断开（192.168.110.72:50211）
[TCP] 收到请求（192.168.110.72:50212）：{"jsonrpc":"2.0","method":"demo.add","params":["张三",20],"id":"rpc_688361ffe22fa"}
[TCP] 发送响应（192.168.110.72:50212）：{"jsonrpc":"2.0","id":"rpc_688361ffe22fa","result":"用户添加成功！姓名：张三，年龄：20（服务端处理时间：18:52:47）"}
[TCP] 新客户端连接：192.168.110.72:50213（clientId：83）
[TCP] 客户端断开（192.168.110.72:50212）
[TCP] 收到请求（192.168.110.72:50213）：{"jsonrpc":"2.0","method":"demo.get","params":["张三"],"id":"rpc_688361ffed00a"}
[TCP] 发送响应（192.168.110.72:50213）：{"jsonrpc":"2.0","id":"rpc_688361ffed00a","result":{"name":"张三","age":25,"message":"查询成功（服务端时间：18:52:47）"}}
[TCP] 客户端断开（192.168.110.72:50213）
[心跳] 成功（demo）=>Xiaosongshu\Nacos\Samples\DemoService（18:52:50）
[心跳] 成功（login）=>Xiaosongshu\Nacos\Samples\LoginService（18:52:50）
```

客户端的效果是这个样子的
```text
PS D:\php\nacosServer> php .\client.php
登录成功：
Token：2d7b6bd23bd38d6051bf06a2d55c6eca
有效期：3600秒
调用实例：192.168.110.72:8000
退出登录成功：
Token：2d7b6bd23bd38d6051bf06a2d55c6eca
调用实例：192.168.110.72:8000
添加用户结果：用户添加成功！姓名：张三，年龄：20（服务端处理时间：18:52:47）
调用的服务实例：192.168.110.72:8000

查询用户结果：
Array
(
    [name] => 张三
    [age] => 25
    [message] => 查询成功（服务端时间：18:52:47）
)
调用的服务实例：192.168.110.72:8000

```

以上就是全部用法，你可以将此服务集成到现有的框架中，比如thinkphp，laravel，webman等等。我为什么要这么写呢？因为我们的项目是tp3.2+tp5.1+webman2混合在一起的。
#### 写在最后的话
我是新手，我感觉这么写好像不对。但是又不知道具体哪里不对。对nacos不是很清除。有高手可以帮忙修改一下吗？

