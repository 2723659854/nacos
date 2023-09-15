简单的php版本nacos客户端

### 安装
```bash 
composer require xiaosongshu/nacos
```
### 客户端提供的方法
```php 

        $dataId      = 'CalculatorService';
        $group       = 'api';
        $serviceName = 'mother';
        $namespace   = 'public';
        $client      = new \Xiaosongshu\Nacos\Client();
        /** 发布配置 */
        print_r($client->publishConfig($dataId, $group, json_encode(['name' => 'fool', 'bar' => 'ha'])));
        /** 获取配置 */
        print_r($client->getConfig($dataId, $group));
        /** 监听配置 */
        print_r($client->listenerConfig($dataId, $group, json_encode(['name' => 'fool', 'bar' => 'ha'])));
        /** 删除配置 */
        print_r($client->deleteConfig($dataId, $group));
        /** 创建服务 */
        print_r($client->createService($serviceName, $namespace, json_encode(['name' => 'tom', 'age' => 15])));
        /** 创建实例 */
        print_r($client->createInstance($serviceName, "192.168.4.110", '9504', $namespace, json_encode(['name' => 'tom', 'age' => 15]), 99, 1, false));
        /** 获取服务列表 */
        print_r($client->getServiceList($namespace));
        /** 服务详情 */
        print_r($client->getServiceDetail($serviceName, $namespace));
        /** 获取实例列表 */
        print_r($client->getInstanceList($serviceName, $namespace));
        /** 获取实例详情 */
        print_r($client->getInstanceDetail($serviceName, false, '192.168.4.110', '9504'));
        /** 发送心跳 */
        print_r($client->sendBeat($serviceName, '192.168.4.110', 9504, $namespace, false, 'beat'));
        /** 移除实例*/
        print_r($client->removeInstance($serviceName, '192.168.4.110', 9504, $namespace, false));
        /** 删除服务 */
        print_r($client->removeService($serviceName, $namespace));
        
```