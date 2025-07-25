<?php

return [

    /** 连接服务器的基本配置 */

    'host'=>'http://192.168.4.110:8848',
    'username'=>'nacos',
    'password'=>'nacos',
    'heartbeat' => 5,


    /** 需要监听的配置 */
    'config'=>[
        'first'=>[
            'dataId'=>'default',
            'group'=>'default',
            /** 这只是一个示例 ，具体逻辑按照你自己的需求来写 */
            'content'=> [
                'username'=>'tom',
                'password'=>'123456',
                'age'=>25
            ],
        ]
    ],

    /** 需要注册的服务 */
    'service'=>[
        /** 服务名称 */
        'demo'=>[
            /** 服务名这个使用类名 */
            'serviceName'=>\Xiaosongshu\Nacos\Samples\DemoService::class,
            /** 命名空间 */
            'namespace'=>'public',
            /** 元数据 */
            'metadata'=>['method'=>'add','param'=>'a,b','id'=>''],
            /** 实例列表 */
            'instance'=>[
                [
                    /** IP */
                    'ip'=>'192.168.110.72',
                    /** 端口 */
                    'port'=>'8000',
                    /** 权重 */
                    'weight'=>99,
                    /** 健康状态 */
                    'healthy'=>true,
                    /** 是否临时实例 */
                    'ephemeral'=>true,
                ],
                [
                    /** IP */
                    'ip'=>'192.168.110.72',
                    /** 端口 */
                    'port'=>'9504',
                    /** 权重 */
                    'weight'=>92,
                    /** 健康状态 */
                    'healthy'=>true,
                    /** 是否临时实例 */
                    'ephemeral'=>true,
                ]
            ]
        ]
    ]
];