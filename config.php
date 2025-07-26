<?php

return [

    /** 连接服务器的基本配置 */
    'server'=>[
        'host'=>'http://192.168.1.3:8848',
        'username'=>'nacos',
        'password'=>'nacos',
        'heartbeat_interval' => 5, // 心跳间隔（秒，默认5秒）
    ],

    /** 服务提供者实例的配置 */
    'instance'=>[
        'ip'=>'192.168.1.3',
        'port'=>'8000',
        'weight'=>99, // 初始权重（降级时会动态调整）
        'timeout_threshold' => 1000, // 超时阈值（毫秒，超过此时间视为超时，用于计算超时率）
    ],

    /** 健康检查与熔断降级配置 */
    'health' => [
        'stat_window_size' => 10, // 统计窗口大小（最近100个请求用于计算超时率/错误率）
        'adjust_cool_down' => 10, // 调整冷却时间（秒，避免频繁调整，默认30秒）
        // 可根据需求添加其他配置，如熔断恢复阈值等
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
            'serviceName' => \Xiaosongshu\Nacos\Samples\LoginService::class,
            'namespace' => 'public',
            'contract' => [
                'out' => 'logout'
            ]
        ]
    ]
];