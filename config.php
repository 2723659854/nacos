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
        'demo'=>[
            'enable'=>true,
            'serviceName'=>\Xiaosongshu\Nacos\Samples\DemoService::class,
            'namespace'=>'public',
            'metadata'=>['method'=>'add','params'=>json_encode([['type'=>'string','name'=>'name'],['type'=>'int','name'=>'age']]),'description' => '示例服务'],
            'heartbeat_interval'=>5
        ]
    ]
];