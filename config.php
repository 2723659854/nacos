<?php

return [

    /** 连接服务器的基本配置 */
    'server' => [
        'host' => 'http://127.0.0.1:8848',
        'username' => 'nacos',
        'password' => 'nacos@123456',
        'heartbeat_interval' => 5, // 心跳间隔（秒，默认5秒）
    ],

    /** 服务提供者实例的配置 */
    'instance' => [
        'ip' => '192.168.110.72',
        'port' => '8000',
        'weight' => 100, // 初始权重（降级时会动态调整）
        'timeout_threshold' => 1000, // 超时阈值（毫秒，超过此时间视为超时，用于计算超时率）
    ],

    /** 健康检查与熔断降级配置 */
    'health' => [
        'stat_window_size' => 10, // 统计窗口大小（最近100个请求用于计算超时率/错误率）
        'adjust_cool_down' => 10, // 调整冷却时间（秒，避免频繁调整，默认30秒）
        // 可根据需求添加其他配置，如熔断恢复阈值等
    ],

    /** 需要监听的配置 作为配置，你可能需要尽可能将配置合并到比较少的文件，一个项目一个配置文件最多两个配置文件就足够了。 */
    'config' => [
        'app' => [
             # 是否开启监听
            'enable' => true,
            # 是否将本地配置发布到服务器
            'publish'=> false,
            'dataId' => 'default',
            'group' => 'default',
            # 需要配监听的配置文件
            'file' => __DIR__ . '/application.yaml',
            # 监听到配置发生变化的回调
            'callback' => function ($content) {
                file_put_contents(__DIR__ . "/application.yaml", $content);
                // todo 重新加载配置，刷新应用
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