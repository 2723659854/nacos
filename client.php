<?php

// 使用示例
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . "/src/Client.php";
require_once __DIR__ . "/src/JsonRpcClient.php";

use Xiaosongshu\Nacos\JsonRpcClient;

// Nacos配置（与服务端一致）
$nacosConfig = [
    'host' => 'http://127.0.0.1:8848',
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

echo "所有请求处理完毕\r\n";