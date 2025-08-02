简单的php版本nacos客户端

### 安装
```bash 
composer require xiaosongshu/nacos
```
#### 项目地址
```text
https://github.com/2723659854/nacos
```
### 客户端示例
```php
<?php

require_once __DIR__.'/vendor/autoload.php';


$dataId      = 'CalculatorService';
$group       = 'api';
$serviceName = 'father';
$namespace   = 'public';

$client      = new \Xiaosongshu\Nacos\Client('http://127.0.0.1:8848','nacos','nacos');

/** 发布配置 */
$client->publishConfig($dataId, $group, json_encode(['name' => 'fool', 'bar' => 'ha']));
/** 创建实例 */
$client->createInstance($serviceName, "192.168.4.110", '9506', $namespace, ['name' => 'tom', 'age' => 15], 50, 1, true)
```

###  应用举例

####  注册发布服务，监听配置示例
本项目提供已编写好的服务端`Xiaosongshu\Nacos\Server`和客户端`Xiaosongshu\Nacos\JsonRpcClient`。<br>

##### 服务端配置文件
其中配置文件config.php，内容如下：
```php
<?php

return [

    /** 连接服务器的基本配置 */
    'server' => [
        'host' => 'http://127.0.0.1:8848',
        'username' => 'nacos',
        'password' => 'nacos',
        'heartbeat_interval' => 5, // 心跳间隔（秒，默认5秒）
    ],

    /** 服务提供者实例的配置 */
    'instance' => [
        'ip' => '127.0.0.1',
        'port' => '8000',
        'weight' => 99, // 初始权重（降级时会动态调整）
        'timeout_threshold' => 1000, // 超时阈值（毫秒，超过此时间视为超时，用于计算超时率）
    ],

    /** 健康检查与熔断降级配置 */
    'health' => [
        'stat_window_size' => 10, // 统计窗口大小（最近100个请求用于计算超时率/错误率）
        'adjust_cool_down' => 10, // 调整冷却时间（秒，避免频繁调整，默认30秒）
    ],

    /** 需要监听的配置 作为配置，你可能需要尽可能将配置合并到比较少的文件，一个项目一个配置文件最多两个配置文件就足够了。 */
    'config' => [
        'app' => [
            # 是否开启监听
            'enable' => true,
            'dataId' => 'default',
            'group' => 'default',
            # 需要配监听的配置文件
            'file' => __DIR__ . '/application.yaml',
            # 监听到配置发生变化的回调
            'callback' => function ($content) {
                // todo 根据你的应用场景，将配置刷新到内存，或者重启应用，此处仅做示例演示将配置写入文件
                file_put_contents(__DIR__ . "/application.yaml", $content);
                
            }
        ],
    ],

    /** 需要注册的服务 */
    'service' => [
        // 服务标识：demo
        'demo' => [
            'enable' => true,
            'serviceName' => \Xiaosongshu\Nacos\Samples\DemoService::class,
            'namespace' => 'public',
        ],

        // 服务标识：login
        'login' => [
            'enable' => true,
            'serviceName' => \Xiaosongshu\Nacos\Samples\LoginService::class,
            'namespace' => 'public',
            'contract' => [
                'out' => 'logout'
            ]
        ]
    ]
];
```
如果需要监听配置，如上面文件的配置`application.yaml`，假设文件内容如下：
```text
app=demo
debug=true

DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=root
DB_PASS=root
DB_DATABASE=demo

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASS=123456
```
#####  服务端代码
你可以编写一个server.php文件，内容如下

```php
<?php

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__."/DemoService.php";
require_once __DIR__."/LoginService.php";

use Xiaosongshu\Nacos\Server;

// 加载配置文件
$config = require 'config.php';

// 启动服务

try{
    $server = new Server($config);
    $server->run();
}catch (\Throwable $exception){
    var_dump($exception->getMessage(),$exception->getLine(),$exception->getFile());
}

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
#####  客户端代码
这个时候我们再编写一个客户端client.php，用来测试调用服务。内容如下：
```php
<?php

// 使用示例
require_once __DIR__ . '/vendor/autoload.php';

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


/** 测试请求超时，是否会触发服务降级 */
for ($i=0;$i<=10;$i++) {
// 3. 调用DemoService的add方法（添加用户）
    $addResult = $client->call(
        'demo', // 服务标识（对应服务端配置中的serviceKey）
        [
            'name' => '张三'.$i,  // 对应add方法的$name参数
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

#####  服务端运行效果

那么服务端测试结果大概效果是这样子的
```text
PS D:\php\nacosServer> php .\server.php
2025-07-31 15:57:30 [warn]系统已启动debug模式，你可以设置isDebug=false关闭调试模式
2025-07-31 15:57:30 [init] 已加载服务：demo -> Xiaosongshu\Nacos\Samples\DemoService（元数据解析完成）
2025-07-31 15:57:30 [init] 已加载服务：login -> Xiaosongshu\Nacos\Samples\LoginService（元数据解析完成）
2025-07-31 15:57:30 [init] 已加载配置：app -> D:\php\nacosServer/application.yaml
2025-07-31 15:57:30 [config] 启动监听流：app（ID: 18）
2025-07-31 15:57:30 [service] 已注册服务：demo -> SERVICE@@demo（IP：127.0.0.1:8000）
2025-07-31 15:57:30 [service] 已注册服务：login -> SERVICE@@login（IP：127.0.0.1:8000）
2025-07-31 15:57:30 [init] 已发布配置：app（本地配置发布完毕）
2025-07-31 15:57:30 [init] 已启动，监听：127.0.0.1:8000（JSON-RPC协议）
2025-07-31 15:57:30 [heartbeat] 成功（demo）->Xiaosongshu\Nacos\Samples\DemoService
2025-07-31 15:57:30 [heartbeat] 成功（login）->Xiaosongshu\Nacos\Samples\LoginService
2025-07-31 15:57:30 [debug] 原始响应: default%02default%01 | 解码后: defaultdefault | 监听配置: dataId=default, group=default
2025-07-31 15:57:30 [config] app 配置发生变化（dataId: default, group: default）
2025-07-31 15:57:32 [config] 启动监听流：app（ID: 27）
2025-07-31 15:57:35 [heartbeat] 成功（demo）->Xiaosongshu\Nacos\Samples\DemoService
2025-07-31 15:57:35 [heartbeat] 成功（login）->Xiaosongshu\Nacos\Samples\LoginService
2025-07-31 15:57:37 [tcp] 新客户端连接：127.0.0.1:56684（clientId：30）
2025-07-31 15:57:37 [tcp] 收到请求（127.0.0.1:56684）：{"jsonrpc":"2.0","method":"login.login","params":["zhangsan","123456"],"id":"rpc_688b21f0dc968"}
2025-07-31 15:57:37 [tcp] 发送响应（127.0.0.1:56684）：{"jsonrpc":"2.0","id":"rpc_688b21f0dc968","result":{"success":true,"message":"登录成功","token":"68fe7ea8cbec945cec906ce3b48172dc","expire":3600}}
2025-07-31 15:57:37 [tcp] 新客户端连接：127.0.0.1:56685（clientId：31）
2025-07-31 15:57:37 [tcp] 客户端断开（127.0.0.1:56684）
2025-07-31 15:57:37 [tcp] 收到请求（127.0.0.1:56685）：{"jsonrpc":"2.0","method":"login.out","params":["68fe7ea8cbec945cec906ce3b48172dc"],"id":"rpc_688b21f15a126"}
2025-07-31 15:57:37 [tcp] 发送响应（127.0.0.1:56685）：{"jsonrpc":"2.0","id":"rpc_688b21f15a126","result":{"success":true,"message":"退出登录成功","token":"68fe7ea8cbec945cec906ce3b48172dc"}}
2025-07-31 15:57:37 [tcp] 新客户端连接：127.0.0.1:56689（clientId：32）
2025-07-31 15:57:37 [tcp] 客户端断开（127.0.0.1:56685）
2025-07-31 15:57:37 [tcp] 收到请求（127.0.0.1:56689）：{"jsonrpc":"2.0","method":"demo.add","params":["张三0",20],"id":"rpc_688b21f1b7950"}
2025-07-31 15:57:38 [tcp] 发送响应（127.0.0.1:56689）：{"jsonrpc":"2.0","id":"rpc_688b21f1b7950","result":"用户添加成功！姓名：张三0，年龄：20（服务端处理时间：15:57:37）"}
2025-07-31 15:57:38 [tcp] 新客户端连接：127.0.0.1:56690（clientId：33）
2025-07-31 15:57:38 [tcp] 客户端断开（127.0.0.1:56689）
2025-07-31 15:57:38 [tcp] 收到请求（127.0.0.1:56690）：{"jsonrpc":"2.0","method":"demo.add","params":["张三1",20],"id":"rpc_688b21f21cd01"}
2025-07-31 15:57:38 [tcp] 发送响应（127.0.0.1:56690）：{"jsonrpc":"2.0","id":"rpc_688b21f21cd01","result":"用户添加成功！姓名：张三1，年龄：20（服务端处理时间：15:57:38）"}
2025-07-31 15:57:38 [tcp] 新客户端连接：127.0.0.1:56691（clientId：34）
2025-07-31 15:57:38 [tcp] 客户端断开（127.0.0.1:56690）
2025-07-31 15:57:38 [tcp] 收到请求（127.0.0.1:56691）：{"jsonrpc":"2.0","method":"demo.add","params":["张三2",20],"id":"rpc_688b21f2778d6"}
2025-07-31 15:57:38 [tcp] 发送响应（127.0.0.1:56691）：{"jsonrpc":"2.0","id":"rpc_688b21f2778d6","result":"用户添加成功！姓名：张三2，年龄：20（服务端处理时间：15:57:38）"}
2025-07-31 15:57:38 [tcp] 新客户端连接：127.0.0.1:56692（clientId：35）
2025-07-31 15:57:38 [tcp] 客户端断开（127.0.0.1:56691）
2025-07-31 15:57:39 [tcp] 收到请求（127.0.0.1:56692）：{"jsonrpc":"2.0","method":"demo.add","params":["张三3",20],"id":"rpc_688b21f2d3cfb"}
2025-07-31 15:57:39 [tcp] 发送响应（127.0.0.1:56692）：{"jsonrpc":"2.0","id":"rpc_688b21f2d3cfb","result":"用户添加成功！姓名：张三3，年龄：20（服务端处理时间：15:57:39）"}
2025-07-31 15:57:39 [tcp] 新客户端连接：127.0.0.1:56694（clientId：36）
2025-07-31 15:57:39 [tcp] 客户端断开（127.0.0.1:56692）
2025-07-31 15:57:39 [tcp] 收到请求（127.0.0.1:56694）：{"jsonrpc":"2.0","method":"demo.add","params":["张三4",20],"id":"rpc_688b21f33ac20"}
2025-07-31 15:57:39 [tcp] 发送响应（127.0.0.1:56694）：{"jsonrpc":"2.0","id":"rpc_688b21f33ac20","result":"用户添加成功！姓名：张三4，年龄：20（服务端处理时间：15:57:39）"}
2025-07-31 15:57:39 [tcp] 新客户端连接：127.0.0.1:56695（clientId：37）
2025-07-31 15:57:39 [tcp] 客户端断开（127.0.0.1:56694）
2025-07-31 15:57:39 [tcp] 收到请求（127.0.0.1:56695）：{"jsonrpc":"2.0","method":"demo.add","params":["张三5",20],"id":"rpc_688b21f396de1"}
2025-07-31 15:57:39 [tcp] 发送响应（127.0.0.1:56695）：{"jsonrpc":"2.0","id":"rpc_688b21f396de1","result":"用户添加成功！姓名：张三5，年龄：20（服务端处理时间：15:57:39）"}
2025-07-31 15:57:40 [heartbeat] 成功（demo）->Xiaosongshu\Nacos\Samples\DemoService
2025-07-31 15:57:40 [heartbeat] 成功（login）->Xiaosongshu\Nacos\Samples\LoginService
2025-07-31 15:57:40 [tcp] 新客户端连接：127.0.0.1:56696（clientId：40）
2025-07-31 15:57:40 [tcp] 客户端断开（127.0.0.1:56695）
2025-07-31 15:57:40 [tcp] 收到请求（127.0.0.1:56696）：{"jsonrpc":"2.0","method":"demo.add","params":["张三6",20],"id":"rpc_688b21f3f2711"}
2025-07-31 15:57:40 [tcp] 发送响应（127.0.0.1:56696）：{"jsonrpc":"2.0","id":"rpc_688b21f3f2711","result":"用户添加成功！姓名：张三6，年龄：20（服务端处理时间：15:57:40）"}
2025-07-31 15:57:40 [tcp] 新客户端连接：127.0.0.1:56703（clientId：41）
2025-07-31 15:57:40 [tcp] 客户端断开（127.0.0.1:56696）
2025-07-31 15:57:40 [tcp] 收到请求（127.0.0.1:56703）：{"jsonrpc":"2.0","method":"demo.add","params":["张三7",20],"id":"rpc_688b21f460c2d"}
2025-07-31 15:57:40 [tcp] 发送响应（127.0.0.1:56703）：{"jsonrpc":"2.0","id":"rpc_688b21f460c2d","result":"用户添加成功！姓名：张三7，年龄：20（服务端处理时间：15:57:40）"}
2025-07-31 15:57:40 [tcp] 新客户端连接：127.0.0.1:56704（clientId：42）
2025-07-31 15:57:40 [tcp] 客户端断开（127.0.0.1:56703）
2025-07-31 15:57:41 [tcp] 收到请求（127.0.0.1:56704）：{"jsonrpc":"2.0","method":"demo.add","params":["张三8",20],"id":"rpc_688b21f4bc128"}
2025-07-31 15:57:41 [tcp] 发送响应（127.0.0.1:56704）：{"jsonrpc":"2.0","id":"rpc_688b21f4bc128","result":"用户添加成功！姓名：张三8，年龄：20（服务端处理时间：15:57:41）"}
2025-07-31 15:57:41 [tcp] 新客户端连接：127.0.0.1:56707（clientId：43）
2025-07-31 15:57:41 [tcp] 客户端断开（127.0.0.1:56704）
2025-07-31 15:57:41 [tcp] 收到请求（127.0.0.1:56707）：{"jsonrpc":"2.0","method":"demo.add","params":["张三9",20],"id":"rpc_688b21f523d36"}
2025-07-31 15:57:41 [tcp] 发送响应（127.0.0.1:56707）：{"jsonrpc":"2.0","id":"rpc_688b21f523d36","result":"用户添加成功！姓名：张三9，年龄：20（服务端处理时间：15:57:41）"}
2025-07-31 15:57:41 [tcp] 新客户端连接：127.0.0.1:56708（clientId：44）
2025-07-31 15:57:41 [tcp] 客户端断开（127.0.0.1:56707）
2025-07-31 15:57:41 [tcp] 收到请求（127.0.0.1:56708）：{"jsonrpc":"2.0","method":"demo.add","params":["张三10",20],"id":"rpc_688b21f58026e"}
2025-07-31 15:57:41 [tcp] 发送响应（127.0.0.1:56708）：{"jsonrpc":"2.0","id":"rpc_688b21f58026e","result":"用户添加成功！姓名：张三10，年龄：20（服务端处理时间：15:57:41）"}
2025-07-31 15:57:42 [tcp] 新客户端连接：127.0.0.1:56709（clientId：45）
2025-07-31 15:57:42 [tcp] 客户端断开（127.0.0.1:56708）
2025-07-31 15:57:42 [tcp] 收到请求（127.0.0.1:56709）：{"jsonrpc":"2.0","method":"demo.get","params":["张三"],"id":"rpc_688b21f5dafd0"}
2025-07-31 15:57:42 [tcp] 发送响应（127.0.0.1:56709）：{"jsonrpc":"2.0","id":"rpc_688b21f5dafd0","result":{"name":"张三","age":25,"message":"查询成功（服务端时间：15:57:42）"}}
2025-07-31 15:57:42 [tcp] 客户端断开（127.0.0.1:56709）
2025-07-31 15:57:45 [heartbeat] 成功（demo）->Xiaosongshu\Nacos\Samples\DemoService
2025-07-31 15:57:45 [heartbeat] 成功（login）->Xiaosongshu\Nacos\Samples\LoginService
2025-07-31 15:57:50 [heartbeat] 成功（demo）->Xiaosongshu\Nacos\Samples\DemoService
2025-07-31 15:57:50 [heartbeat] 成功（login）->Xiaosongshu\Nacos\Samples\LoginService
```

客户端的效果是这个样子的
```text
PS D:\php\nacosServer> php .\client.php
登录成功：
Token：68fe7ea8cbec945cec906ce3b48172dc
有效期：3600秒
调用实例：127.0.0.1:8000
退出登录成功：
Token：68fe7ea8cbec945cec906ce3b48172dc
调用实例：127.0.0.1:8000
添加用户结果：用户添加成功！姓名：张三0，年龄：20（服务端处理时间：15:57:37）
调用的服务实例：127.0.0.1:8000

添加用户结果：用户添加成功！姓名：张三1，年龄：20（服务端处理时间：15:57:38）
调用的服务实例：127.0.0.1:8000

添加用户结果：用户添加成功！姓名：张三2，年龄：20（服务端处理时间：15:57:38）
调用的服务实例：127.0.0.1:8000

添加用户结果：用户添加成功！姓名：张三3，年龄：20（服务端处理时间：15:57:39）
调用的服务实例：127.0.0.1:8000

添加用户结果：用户添加成功！姓名：张三4，年龄：20（服务端处理时间：15:57:39）
调用的服务实例：127.0.0.1:8000

添加用户结果：用户添加成功！姓名：张三5，年龄：20（服务端处理时间：15:57:39）
调用的服务实例：127.0.0.1:8000

添加用户结果：用户添加成功！姓名：张三6，年龄：20（服务端处理时间：15:57:40）
调用的服务实例：127.0.0.1:8000

添加用户结果：用户添加成功！姓名：张三7，年龄：20（服务端处理时间：15:57:40）
调用的服务实例：127.0.0.1:8000

添加用户结果：用户添加成功！姓名：张三8，年龄：20（服务端处理时间：15:57:41）
调用的服务实例：127.0.0.1:8000

添加用户结果：用户添加成功！姓名：张三9，年龄：20（服务端处理时间：15:57:41）
调用的服务实例：127.0.0.1:8000

添加用户结果：用户添加成功！姓名：张三10，年龄：20（服务端处理时间：15:57:41）
调用的服务实例：127.0.0.1:8000

查询用户结果：
Array
(
    [name] => 张三
    [age] => 25
    [message] => 查询成功（服务端时间：15:57:42）
)
调用的服务实例：127.0.0.1:8000
所有请求处理完毕

```

#### 服务降级和熔断

系统默认超时率大于等于50%的时候，会对服务进行降级处理，当超时率低于50%的时候会逐步恢复。当服务的错误率高于50%的时候，会对服务进行熔断处理，然后系统会尝试自动恢复服务。
#### 一键搭建nacos服务

```bash
docker run --name nacos -e MODE=standalone --env NACOS_AUTH_ENABLE=true -p 8848:8848 -p 31181:31181 -d nacos/nacos-server:1.3.1
```



