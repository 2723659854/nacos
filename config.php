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