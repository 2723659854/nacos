<?php

namespace Xiaosongshu\Nacos;

/**
 * 微服务客户端（支持自动服务发现、元数据查询、参数校验、负载均衡）
 * 核心特性：
 * 1. 开发人员仅需传入服务标识和业务参数（键值对）
 * 2. 自动从Nacos获取服务实例和接口元数据
 * 3. 自动校验参数合法性并转换为服务端所需格式
 * 4. 基于访问次数和权重的负载均衡策略
 * @purpose 新版微服务客户端
 * @author yanglong
 * @time 2025年7月25日17:33:44
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
     * 实例访问计数器（按服务标识分组，键为ip:port，值为访问次数）
     * 结构：['serviceKey' => ['192.168.1.1:8080' => 10, '192.168.1.2:8080' => 5, ...]]
     */
    private $instanceAccessCount = [];


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
        $this->instanceAccessCount = []; // 初始化访问计数器
    }


    /**
     * 核心方法：极简调用入口（优化负载均衡）
     * @param string $serviceKey 服务标识（如login）
     * @param array $businessParams 业务参数（键值对）
     * @param string $method 方法名（默认与服务标识同名）
     * @return array 调用结果：['success' => bool, 'result' => ..., 'error' => ...]
     */
    public function call(string $serviceKey, array $businessParams, string $method = ''): array
    {
        try {
            // 默认方法名与服务标识相同
            $method = $method ?: $serviceKey;

            // 1. 获取服务实例和元数据（自动刷新缓存）
            list($instance, $metadata) = $this->getInstanceAndMetadata($serviceKey);
            $instanceKey = "{$instance['ip']}:{$instance['port']}"; // 实例唯一标识

            // 2. 验证方法是否存在（含契约处理）
            if (!isset($metadata['methods'][$method]) && !isset($metadata['contract'][$method])) {
                return [
                    'success' => false,
                    'error' => "服务{$serviceKey}不存在方法{$method}，可用方法：" . implode(',', array_keys($metadata['methods']))
                ];
            }

            // 3. 处理契约方法的参数规则
            $contractMethod = $metadata['contract'][$method] ?? '';
            $paramRules = $contractMethod
                ? $metadata['methods'][$contractMethod]['params']
                : $metadata['methods'][$method]['params'];

            // 4. 校验业务参数
            $validateResult = $this->validateParams($businessParams, $paramRules);
            if (!$validateResult['valid']) {
                return [
                    'success' => false,
                    'error' => "参数错误：{$validateResult['message']}"
                ];
            }

            // 5. 转换参数为服务端所需的顺序
            $orderedParams = $this->convertParamsToOrdered($businessParams, $paramRules);

            // 6. 发起JSON-RPC调用（调用后更新访问计数）
            $result = $this->request($serviceKey, $method, $orderedParams, $instance);

            // 7. 成功调用后更新实例访问次数
            $this->instanceAccessCount[$serviceKey][$instanceKey] = ($this->instanceAccessCount[$serviceKey][$instanceKey] ?? 0) + 1;

            return $result;

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => "调用失败：{$e->getMessage()}"
            ];
        }
    }


    /**
     * 获取实例和元数据（核心优化：负载均衡选择实例）
     */
    private function getInstanceAndMetadata(string $serviceKey): array
    {
        $now = time();
        $nacosServiceName = $this->generateSafeNacosName($serviceKey);

        // 缓存过期则刷新（同步更新访问计数器）
        if (!isset($this->lastCacheTime[$serviceKey]) || $now - $this->lastCacheTime[$serviceKey] > $this->cacheExpire) {
            $this->refreshInstances($serviceKey, $nacosServiceName);
            $this->lastCacheTime[$serviceKey] = $now;
        }

        if (empty($this->instances[$serviceKey])) {
            throw new \Exception("未找到{$serviceKey}服务的可用实例");
        }

        // 优化：基于访问次数和权重选择实例（负载均衡核心）
        $instance = $this->selectInstanceByLoadBalance($serviceKey);

        // 反序列化服务端元数据
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
     * 负载均衡选择实例：优先选择访问次数少、权重高的实例
     * 算法：计算每个实例的"负载分数" = 访问次数 / 权重，分数越低越优先
     * @param string $serviceKey 服务名
     * @return array
     */
    private function selectInstanceByLoadBalance(string $serviceKey): array
    {
        $instances = $this->instances[$serviceKey];
        $instanceScores = []; // 存储每个实例的负载分数

        foreach ($instances as $index => $instance) {
            $instanceKey = "{$instance['ip']}:{$instance['port']}";
            $accessCount = $this->instanceAccessCount[$serviceKey][$instanceKey] ?? 0; // 访问次数
            $weight = $instance['weight'] ?? 1.0; // 实例权重（Nacos配置）
            $weight = max(0.1, $weight); // 避免权重为0导致除数为0

            // 计算负载分数：访问次数越多、权重越低，分数越高（越不优先）
            $score = $accessCount / $weight;
            $instanceScores[$index] = $score;
        }

        // 选择分数最低的实例（负载最低）
        asort($instanceScores); // 升序排序（分数低的在前）
        $selectedIndex = key($instanceScores); // 取第一个（分数最低）

        return $instances[$selectedIndex];
    }


    /**
     * 从Nacos刷新服务实例（同步更新访问计数器）
     * @param string $serviceKey 服务名
     * @param string $nacosServiceName nacos服务器上服务名
     * @return void
     * @throws \Exception
     */
    private function refreshInstances(string $serviceKey, string $nacosServiceName)
    {
        $response = $this->nacosClient->getInstanceList(
            $nacosServiceName,
            $this->namespace,
            true // 只获取健康实例
        );

        if (isset($response['error'])) {
            throw new \Exception("从Nacos获取{$serviceKey}实例失败：{$response['error']}");
        }


        $content = $response['content'];
        $newInstances = $content['hosts'] ?? [];
        $newInstanceKeys = []; // 新实例的ip:port集合

        // 提取新实例的唯一标识
        foreach ($newInstances as $instance) {
            $newInstanceKeys[] = "{$instance['ip']}:{$instance['port']}";
        }

        // 保留原有实例的访问计数，移除已下线实例的计数
        if (isset($this->instanceAccessCount[$serviceKey])) {
            foreach ($this->instanceAccessCount[$serviceKey] as $key => $_) {
                if (!in_array($key, $newInstanceKeys)) {
                    unset($this->instanceAccessCount[$serviceKey][$key]); // 移除下线实例计数
                }
            }
        }

        // 初始化新实例的访问计数（默认为0）
        foreach ($newInstanceKeys as $key) {
            if (!isset($this->instanceAccessCount[$serviceKey][$key])) {
                $this->instanceAccessCount[$serviceKey][$key] = 0;
            }
        }

        // 更新实例列表（只保留健康实例）
        $this->instances[$serviceKey] = array_filter($newInstances, function($host) {
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
     * @param string $serviceKey 服务名
     * @return string
     */
    private function generateSafeNacosName(string $serviceKey): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $serviceKey);
        return "SERVICE@@{$safeKey}";
    }


    /**
     * 校验业务参数是否符合元数据规则
     * @param array $businessParams 参数
     * @param array $paramRules 顺序
     * @return array|true[]
     */
    private function validateParams(array $businessParams, array $paramRules): array
    {
        // 检查必填参数
        foreach ($paramRules as $rule) {
            if ($rule['required'] && !isset($businessParams[$rule['name']])) {
                return [
                    'valid' => false,
                    'message' => "缺少必填参数：{$rule['name']}"
                ];
            }
        }

        // 检查参数类型
        foreach ($businessParams as $paramName => $paramValue) {
            $rule = null;
            foreach ($paramRules as $r) {
                if ($r['name'] === $paramName) {
                    $rule = $r;
                    break;
                }
            }
            if (!$rule) continue;

            $paramType = gettype($paramValue);
            $expectedType = $rule['type'];
            $typeMap = ['integer' => 'int', 'boolean' => 'bool', 'double' => 'float'];
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
     * 将键值对参数转换为服务端所需的顺序数组
     * @param array $businessParams 参数
     * @param array $paramRules 参数顺序
     * @return array
     */
    private function convertParamsToOrdered(array $businessParams, array $paramRules): array
    {
        $orderedParams = [];
        foreach ($paramRules as $rule) {
            $orderedParams[] = $businessParams[$rule['name']] ?? null;
        }
        return $orderedParams;
    }


    /**
     * 发起JSON-RPC请求
     * @param string $serviceKey 服务名
     * @param string $method 请求方法
     * @param array $params 参数
     * @param array $instance 实例
     * @return array
     * @throws \Exception
     */
    private function request(string $serviceKey, string $method, array $params, array $instance): array
    {
        $socket = null;
        try {
            // 创建套接字
            $socket = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
            if ($socket === false) {
                throw new \Exception("创建连接失败：" . \socket_strerror(\socket_last_error()));
            }

            // 设置接收超时
            \socket_set_option($socket, \SOL_SOCKET, \SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
            // 设置发送超时
            \socket_set_option($socket, \SOL_SOCKET, \SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);

            // 连接实例
            $connectResult = \socket_connect($socket, $instance['ip'], $instance['port']);
            if ($connectResult === false) {
                throw new \Exception("无法连接到实例（{$instance['ip']}:{$instance['port']}）：" . \socket_strerror(\socket_last_error($socket)));
            }

            // 构建JSON-RPC请求
            $requestId = uniqid('rpc_');
            $request = [
                'jsonrpc' => '2.0',
                'method' => "{$serviceKey}.{$method}",
                'params' => $params,
                'id' => $requestId
            ];
            $requestJson = json_encode($request, JSON_UNESCAPED_UNICODE) . "\n";

            // 发送请求
            if (\socket_write($socket, $requestJson) === false) {
                throw new \Exception("发送请求失败：" . \socket_strerror(\socket_last_error($socket)));
            }

            // 接收响应
            $responseJson = '';
            // 执行截止时间
            $timeout = time() + $this->timeout;
            // 若响应时间超过最大时间则退出
            while (time() < $timeout) {
                // 读取数据
                $buffer = \socket_read($socket, 4096);
                // 没有读取到数据，则暂停一下，继续读取数据
                if ($buffer === false) {
                    usleep(100000);
                    continue;
                }
                // 拼接响应数据
                $responseJson .= $buffer;
                // 当读取到一列结束符的时候，则不再读取
                if (strpos($responseJson, "\n") !== false) break;
            }
            // 修剪读取的数据
            $responseJson = trim($responseJson);

            if ($responseJson === '') {
                throw new \Exception("未收到响应（超时）");
            }

            // 解析响应
            $response = json_decode($responseJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("响应格式错误：{$responseJson}");
            }

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
            if ($socket) \socket_close($socket);
        }
    }


    /**
     * 强制刷新缓存（同步更新访问计数器）
     * @param string $serviceKey 服务名
     * @return void
     * @throws \Exception
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


    /**
     * 重置实例访问计数（用于手动平衡负载）
     * @param string $serviceKey 服务名
     * @return void
     */
    public function resetAccessCount(string $serviceKey = '')
    {
        if ($serviceKey) {
            $this->instanceAccessCount[$serviceKey] = [];
        } else {
            $this->instanceAccessCount = [];
        }
    }
}