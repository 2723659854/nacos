<?php

namespace Xiaosongshu\Nacos;

use GuzzleHttp\Exception\GuzzleException;

/**
 * 基于Nacos服务发现的JSON-RPC客户端
 * 核心特性：通过服务标识调用、自动发现服务实例、隐藏实现细节
 */
class JsonRpcClient
{
    /** @var Client Nacos客户端实例 */
    private $nacosClient;

    /** @var string 服务标识（如demo） */
    private $serviceKey;

    /** @var string Nacos中的安全服务名（如SERVICE@@demo） */
    private $nacosServiceName;

    /** @var string 命名空间 */
    private $namespace;

    /** @var int 超时时间（秒） */
    private $timeout;

    /** @var array 缓存的服务实例 */
    private $instances = [];

    /** @var int 实例缓存过期时间（秒） */
    private $instanceCacheExpire = 30;

    /** @var int 最后一次获取实例的时间 */
    private $lastInstanceFetchTime = 0;

    /**
     * 构造函数：初始化Nacos连接和服务标识
     * @param array $nacosConfig Nacos配置：host, username, password
     * @param string $serviceKey 服务标识（如demo）
     * @param string $namespace 命名空间，默认public
     * @param int $timeout 超时时间（秒）
     * @throws \Exception
     */
    public function __construct(array $nacosConfig, string $serviceKey, string $namespace = 'public', int $timeout = 5)
    {
        // 初始化Nacos客户端
        $this->nacosClient = new Client(
            $nacosConfig['host'] ?? 'http://127.0.0.1:8848',
            $nacosConfig['username'] ?? 'nacos',
            $nacosConfig['password'] ?? 'nacos'
        );

        $this->serviceKey = $serviceKey;
        $this->nacosServiceName = $this->generateSafeNacosName($serviceKey); // 生成安全服务名
        $this->namespace = $namespace;
        $this->timeout = $timeout;
    }

    /**
     * 生成安全的Nacos服务名（与服务端保持一致）
     * @param string $serviceKey 服务标识
     * @return string 安全服务名
     */
    private function generateSafeNacosName(string $serviceKey): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $serviceKey);
        return "SERVICE@@{$safeKey}";
    }

    /**
     * 核心方法：调用服务方法
     * @param string $method 方法名（如add）
     * @param array $params 方法参数
     * @return array 统一返回格式：
     *               - 成功：['success' => true, 'result' => ..., 'instance' => ...]
     *               - 失败：['success' => false, 'error' => ...]
     */
    public function request(string $method, array $params = []): array
    {
        try {
            // 获取可用实例
            $instance = $this->getAvailableInstance();
            if (!$instance) {
                return [
                    'success' => false,
                    'error' => "未找到可用的{$this->serviceKey}服务实例"
                ];
            }

            // 调用实例方法（方法名格式：服务标识.方法名）
            return $this->callInstance($instance, "{$this->serviceKey}.{$method}", $params);

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "调用失败：{$e->getMessage()}"
            ];
        }
    }

    /**
     * 获取可用的服务实例（从Nacos）
     * @return array|null 实例信息
     * @throws GuzzleException
     */
    private function getAvailableInstance(): ?array
    {
        // 缓存过期则刷新
        $now = time();
        if ($now - $this->lastInstanceFetchTime > $this->instanceCacheExpire) {
            $this->fetchInstancesFromNacos();
            $this->lastInstanceFetchTime = $now;
        }

        // 过滤健康实例
        $healthyInstances = array_filter($this->instances, function($instance) {
            return $instance['healthy'] ?? false;
        });

        if (empty($healthyInstances)) {
            return null;
        }

        // 简单负载均衡：随机选择
        return $healthyInstances[array_rand($healthyInstances)];
    }

    /**
     * 从Nacos获取实例列表
     * @throws GuzzleException
     */
    private function fetchInstancesFromNacos()
    {
        $response = $this->nacosClient->getInstanceList(
            $this->nacosServiceName,
            $this->namespace,
            true
        );

        if (isset($response['error'])) {
            throw new \Exception("从Nacos获取实例失败：{$response['error']}");
        }

        $content = json_decode($response['content'], true);
        $this->instances = $content['hosts'] ?? [];
    }

    /**
     * 调用具体实例
     * @param array $instance 实例信息
     * @param string $method 方法名（服务标识.方法名）
     * @param array $params 参数
     * @return array 调用结果
     */
    private function callInstance(array $instance, string $method, array $params): array
    {
        $socket = null;
        try {
            // 创建套接字
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket === false) {
                throw new \Exception("创建连接失败：" . socket_strerror(socket_last_error()));
            }

            // 设置接收超时（SO_RCVTIMEO）
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
                'sec' => $this->timeout,
                'usec' => 0
            ]);
            // 设置发送超时（SO_SNDTIMEO）
            socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, [
                'sec' => $this->timeout,
                'usec' => 0
            ]);

            // 连接实例
            $connectResult = socket_connect($socket, $instance['ip'], $instance['port']);
            if ($connectResult === false) {
                throw new \Exception("无法连接到实例（{$instance['ip']}:{$instance['port']}）：" . socket_strerror(socket_last_error($socket)));
            }
            // 单独保存请求id
            $requestId = uniqid('rpc_');
            // 构建JSON-RPC请求
            $request = [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params,
                'id' => $requestId
            ];
            $requestJson = json_encode($request) . "\n";

            // 发送请求
            $sendResult = socket_write($socket, $requestJson);
            if ($sendResult === false) {
                throw new \Exception("发送请求失败：" . socket_strerror(socket_last_error($socket)));
            }

            // 接收响应
            $responseJson = '';
            $timeout = time() + $this->timeout; // 超时时间
            while (time() < $timeout) {
                $buffer = socket_read($socket, 4096);
                if ($buffer === false) {
                    // 非阻塞无数据时短暂等待
                    usleep(100000);
                    continue;
                }
                $responseJson .= $buffer;
                // 响应以换行结束（服务端响应末尾添加了\n）
                if (strpos($responseJson, "\n") !== false) {
                    break;
                }
            }
            $responseJson = trim($responseJson);

            if ($responseJson === false) {
                throw new \Exception("接收响应失败：" . socket_strerror(socket_last_error($socket)));
            }

            // 解析响应
            $response = json_decode(trim($responseJson), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("响应格式错误：{$responseJson}");
            }

            // 新增：校验响应id与请求id一致
            if ($response['id'] !== $requestId) {
                throw new \Exception("响应id不匹配（请求id：{$requestId}，响应id：{$response['id']}）");
            }

            // 处理服务端响应
            if (isset($response['error'])) {
                return [
                    'success' => false,
                    'error' => "服务端错误：{$response['error']['message']}（错误码：{$response['error']['code']}）",
                    'instance' => "{$instance['ip']}:{$instance['port']}"
                ];
            } else {
                return [
                    'success' => true,
                    'result' => $response['result'],
                    'instance' => "{$instance['ip']}:{$instance['port']}"
                ];
            }

        } finally {
            // 确保连接关闭
            if ($socket) {
                socket_close($socket);
            }
        }
    }

    /**
     * 强制刷新实例列表
     * @throws GuzzleException
     */
    public function refreshInstances()
    {
        $this->fetchInstancesFromNacos();
        $this->lastInstanceFetchTime = time();
    }
}
