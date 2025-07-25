<?php

namespace Xiaosongshu\Nacos;

use GuzzleHttp\Exception\GuzzleException;

/**
 * 微服务客户端（支持自动服务发现、元数据查询、参数校验）
 * 核心特性：
 * 1. 开发人员仅需传入服务标识和业务参数（键值对）
 * 2. 自动从Nacos获取服务实例和接口元数据
 * 3. 自动校验参数合法性并转换为服务端所需格式
 */
class JsonRpcClient
{
    /** @var Client Nacos客户端实例 */
    private $nacosClient;

    /** @var string 命名空间 */
    private $namespace;

    /** @var int 超时时间（秒） */
    private $timeout;

    /** @var array 缓存的服务实例（按服务标识分组） */
    private $instances = [];

    /** @var array 缓存的服务元数据（按服务标识分组） */
    private $metadataCache = [];

    /** @var int 缓存过期时间（秒） */
    private $cacheExpire = 30;

    /** @var int 最后一次缓存更新时间（按服务标识分组） */
    private $lastCacheTime = [];

    /**
     * 构造函数：初始化Nacos连接
     * @param array $nacosConfig Nacos配置：host, username, password
     * @param string $namespace 命名空间，默认public
     * @param int $timeout 超时时间（秒）
     * @throws \Exception
     */
    public function __construct(array $nacosConfig, string $namespace = 'public', int $timeout = 5)
    {
        $this->nacosClient = new Client(
            $nacosConfig['host'] ?? 'http://127.0.0.1:8848',
            $nacosConfig['username'] ?? 'nacos',
            $nacosConfig['password'] ?? 'nacos'
        );

        $this->namespace = $namespace;
        $this->timeout = $timeout;
    }

    /**
     * 核心方法：极简调用入口
     * @param string $serviceKey 服务标识（如login）
     * @param array $businessParams 业务参数（键值对，如['username' => 'xxx', 'password' => 'xxx']）
     * @param string $method 方法名（默认与服务标识同名，如login服务默认调用login方法）
     * @return array 调用结果：['success' => bool, 'result' => ..., 'error' => ...]
     */
    public function call(string $serviceKey, array $businessParams, string $method = ''): array
    {
        try {
            // 默认方法名与服务标识相同（如login服务默认调用login方法）
            $method = $method ?: $serviceKey;

            // 1. 获取服务实例和元数据（自动刷新缓存）
            list($instance, $metadata) = $this->getInstanceAndMetadata($serviceKey);

            // 2. 验证方法是否存在
            if (!isset($metadata['methods'][$method])) {
                return [
                    'success' => false,
                    'error' => "服务{$serviceKey}不存在方法{$method}，可用方法：" . implode(',', array_keys($metadata['methods']))
                ];
            }

            // 3. 校验业务参数是否符合元数据规则
            $paramRules = $metadata['methods'][$method]['params'];
            $validateResult = $this->validateParams($businessParams, $paramRules);
            if (!$validateResult['valid']) {
                return [
                    'success' => false,
                    'error' => "参数错误：{$validateResult['message']}"
                ];
            }

            // 4. 转换参数为服务端所需的顺序（服务端按位置接收参数）
            $orderedParams = $this->convertParamsToOrdered($businessParams, $paramRules);

            // 5. 发起JSON-RPC调用
            return $this->request($serviceKey, $method, $orderedParams, $instance);

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "调用失败：{$e->getMessage()}"
            ];
        }
    }

    /**
     * 从实例中提取并反序列化元数据
     */
    private function getInstanceAndMetadata(string $serviceKey): array
    {
        $now = time();
        $nacosServiceName = $this->generateSafeNacosName($serviceKey);

        // 缓存过期则刷新
        if (!isset($this->lastCacheTime[$serviceKey]) || $now - $this->lastCacheTime[$serviceKey] > $this->cacheExpire) {
            $this->refreshInstances($serviceKey, $nacosServiceName);
            $this->lastCacheTime[$serviceKey] = $now;
        }

        if (empty($this->instances[$serviceKey])) {
            throw new \Exception("未找到{$serviceKey}服务的可用实例");
        }

        $instance = $this->instances[$serviceKey][array_rand($this->instances[$serviceKey])];

        // 反序列化服务端上报的JSON元数据（核心修复）
        $metadataStr = $instance['metadata']['serviceMetadata'] ?? '';
        if (empty($metadataStr)) {
            throw new \Exception("服务{$serviceKey}未提供元数据");
        }
        $metadata = json_decode($metadataStr, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("元数据解析失败（无效JSON）：{$metadataStr}");
        }

        return [$instance, $metadata];
    }

    /**
     * 从Nacos刷新服务实例和元数据
     */
    private function refreshInstances(string $serviceKey, string $nacosServiceName)
    {
        $response = $this->nacosClient->getInstanceList(
            $nacosServiceName,
            $this->namespace,
            true
        );

        if (isset($response['error'])) {
            throw new \Exception("从Nacos获取{$serviceKey}实例失败：{$response['error']}");
        }

        $content = json_decode($response['content'], true);
        $hosts = $content['hosts'] ?? [];

        // 过滤健康实例
        $this->instances[$serviceKey] = array_filter($hosts, function($host) {
            return $host['healthy'] ?? false;
        });

        // 缓存元数据（从第一个实例提取，假设所有实例元数据一致）
        if (!empty($this->instances[$serviceKey])) {
            $firstInstance = current($this->instances[$serviceKey]);
            $this->metadataCache[$serviceKey] = $firstInstance['metadata'] ?? [];
        }
    }

    /**
     * 生成安全的Nacos服务名（与服务端保持一致）
     */
    private function generateSafeNacosName(string $serviceKey): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $serviceKey);
        return "SERVICE@@{$safeKey}";
    }

    /**
     * 校验业务参数是否符合元数据规则
     */
    private function validateParams(array $businessParams, array $paramRules): array
    {
        // 检查必填参数
        foreach ($paramRules as $rule) {
            if ($rule['required'] && !isset($businessParams[$rule['name']])) {
                return [
                    'valid' => false,
                    'message' => "缺少必填参数：{$rule['name']}（{$rule['description']}）"
                ];
            }
        }

        // 检查参数类型
        foreach ($businessParams as $paramName => $paramValue) {
            // 找到参数规则
            $rule = null;
            foreach ($paramRules as $r) {
                if ($r['name'] === $paramName) {
                    $rule = $r;
                    break;
                }
            }
            if (!$rule) {
                continue; // 忽略未定义的参数
            }

            $paramType = gettype($paramValue);
            $expectedType = $rule['type'];

            // 类型映射（PHP类型与声明类型的对应）
            $typeMap = [
                'integer' => 'int',
                'boolean' => 'bool',
                'double' => 'float'
            ];
            $actualType = $typeMap[$paramType] ?? $paramType;

            if ($actualType !== $expectedType && $expectedType !== 'mixed') {
                return [
                    'valid' => false,
                    'message' => "参数{$paramName}类型错误（期望{$expectedType}，实际{$actualType}）"
                ];
            }
        }

        return ['valid' => true];
    }

    /**
     * 将键值对参数转换为服务端所需的顺序数组（按元数据中的参数顺序）
     */
    private function convertParamsToOrdered(array $businessParams, array $paramRules): array
    {
        $orderedParams = [];
        foreach ($paramRules as $rule) {
            // 可选参数未传时用null填充
            $orderedParams[] = $businessParams[$rule['name']] ?? null;
        }
        return $orderedParams;
    }

    /**
     * 发起JSON-RPC请求
     */
    private function request(string $serviceKey, string $method, array $params, array $instance): array
    {
        $socket = null;
        try {
            // 创建套接字
            $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($socket === false) {
                throw new \Exception("创建连接失败：" . socket_strerror(socket_last_error()));
            }

            // 设置超时
            socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
                'sec' => $this->timeout,
                'usec' => 0
            ]);
            socket_set_option($socket, SOL_SOCKET, SO_SNDTIMEO, [
                'sec' => $this->timeout,
                'usec' => 0
            ]);

            // 连接实例
            $connectResult = socket_connect($socket, $instance['ip'], $instance['port']);
            if ($connectResult === false) {
                throw new \Exception("无法连接到实例（{$instance['ip']}:{$instance['port']}）：" . socket_strerror(socket_last_error($socket)));
            }

            // 构建JSON-RPC请求
            $requestId = uniqid('rpc_');
            $request = [
                'jsonrpc' => '2.0',
                'method' => "{$serviceKey}.{$method}", // 格式：服务标识.方法名
                'params' => $params,
                'id' => $requestId
            ];
            $requestJson = json_encode($request, JSON_UNESCAPED_UNICODE) . "\n";

            // 发送请求
            $sendResult = socket_write($socket, $requestJson);
            if ($sendResult === false) {
                throw new \Exception("发送请求失败：" . socket_strerror(socket_last_error($socket)));
            }

            // 接收响应（循环读取直到完整）
            $responseJson = '';
            $timeout = time() + $this->timeout;
            while (time() < $timeout) {
                $buffer = socket_read($socket, 4096);
                if ($buffer === false) {
                    usleep(100000);
                    continue;
                }
                $responseJson .= $buffer;
                if (strpos($responseJson, "\n") !== false) {
                    break;
                }
            }
            $responseJson = trim($responseJson);

            if ($responseJson === '') {
                throw new \Exception("未收到响应（超时）");
            }

            // 解析响应
            $response = json_decode($responseJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("响应格式错误：{$responseJson}");
            }

            // 校验响应ID与请求ID一致
            if ($response['id'] !== $requestId) {
                throw new \Exception("响应ID不匹配（请求ID：{$requestId}，响应ID：{$response['id']}）");
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
            if ($socket) {
                socket_close($socket);
            }
        }
    }

    /**
     * 强制刷新缓存
     */
    public function refreshCache(string $serviceKey = '')
    {
        if ($serviceKey) {
            $nacosServiceName = $this->generateSafeNacosName($serviceKey);
            $this->refreshInstances($serviceKey, $nacosServiceName);
            $this->lastCacheTime[$serviceKey] = time();
        } else {
            // 刷新所有服务缓存
            foreach (array_keys($this->instances) as $key) {
                $this->refreshCache($key);
            }
        }
    }
}
