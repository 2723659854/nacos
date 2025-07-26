<?php

namespace Xiaosongshu\Nacos;

use Exception;
use ReflectionClass;
use ReflectionMethod;

/**
 * 微服务服务端（支持元数据自动上报、服务降级与熔断）
 * 核心特性：
 * 1. 自动解析服务接口元数据
 * 2. 基于超时率的服务降级（动态调整权重）
 * 3. 基于错误率的服务熔断（标记健康状态）
 * 4. 每个服务独立统计与调整逻辑
 * @purpose 微服务服务端
 * @author yanglong
 * @time 2025年7月26日
 */
class Server
{
    /** 配置参数 */
    private $config;
    private $serverConfig;       // Nacos服务器配置
    private $instanceConfig;     // 实例配置（IP/端口等）
    private $serviceConfig;      // 服务配置（服务标识映射）
    private $heartbeatInterval;  // 心跳间隔（秒）

    /** 健康检查相关配置 */
    private $timeoutThreshold;   // 超时阈值（毫秒）
    private $statWindowSize;     // 统计窗口大小（最近N个请求）
    private $adjustCoolDown;     // 调整冷却时间（秒）

    /**
     * 请求统计数据（按服务标识分组）
     * 结构：['serviceKey' => [
     *   'window' => [],
     *   'currentWeight' => 1.0,
     *   'currentHealthy' => true,
     *   'lastWeightAdjust' => 0,
     *   'lastHealthAdjust' => 0
     * ]]
     */
    private $requestStats = [];

    /** 核心组件 */
    private $nacosClient;        // Nacos客户端
    private $enabledServices = []; // 启用的服务列表（服务标识 => 服务信息）

    /** TCP服务相关 */
    private $serverSocket;       // TCP服务端套接字
    private $clients = [];       // 客户端连接（clientId => socket）
    private $writeBuffers = [];  // 写缓冲区（clientId => 待发送数据）
    private $clientAddresses = []; // 客户端地址（clientId => IP:Port）

    /** JSON-RPC常量（遵循2.0规范） */
    const JSON_RPC_VERSION = "2.0";
    const ERROR_PARSE = ['code' => -32700, 'message' => '解析错误（无效JSON格式）'];
    const ERROR_INVALID_REQUEST = ['code' => -32600, 'message' => '无效请求（不符合JSON-RPC规范）'];
    const ERROR_METHOD_NOT_FOUND = ['code' => -32601, 'message' => '方法不存在'];
    const ERROR_INVALID_PARAMS = ['code' => -32602, 'message' => '参数无效（类型或必填项错误）'];
    const ERROR_INTERNAL = ['code' => -32603, 'message' => '服务端内部错误'];

    /**
     * 构造函数：初始化配置与组件
     * @param array $config 配置数组
     * @throws Exception
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->validateConfig();
        $this->initConfig();
        $this->initNacosClient();
        $this->initEnabledServices(); // 初始化服务并解析元数据
    }

    /**
     * 验证配置合法性
     * @throws Exception
     */
    private function validateConfig()
    {
        if (empty($this->config['server']['host']) || empty($this->config['server']['username']) || empty($this->config['server']['password'])) {
            throw new Exception("Nacos配置不完整（host/username/password不能为空）");
        }
        if (empty($this->config['instance']['ip']) || empty($this->config['instance']['port'])) {
            throw new Exception("实例配置不完整（ip/port不能为空）");
        }
        $hasEnabledService = false;
        foreach ($this->config['service'] as $service) {
            if (!empty($service['enable'])) {
                $hasEnabledService = true;
                break;
            }
        }
        if (!$hasEnabledService) {
            throw new Exception("至少需要启用一个服务（service中enable=true）");
        }
    }

    /**
     * 初始化配置参数
     */
    private function initConfig()
    {
        $this->serverConfig = $this->config['server'];
        $this->instanceConfig = $this->config['instance'];
        $this->serviceConfig = $this->config['service'];
        $this->heartbeatInterval = $this->config['server']['heartbeat_interval'] ?? 5;

        // 初始化健康检查配置
        $this->timeoutThreshold = $this->config['instance']['timeout_threshold'] ?? 1000; // 默认超时1秒
        $this->statWindowSize = $this->config['health']['stat_window_size'] ?? 100;      // 统计窗口大小
        $this->adjustCoolDown = $this->config['health']['adjust_cool_down'] ?? 30;        // 冷却时间（秒）
    }

    /**
     * 初始化Nacos客户端
     * @throws Exception
     */
    private function initNacosClient()
    {
        $this->nacosClient = new Client(
            $this->serverConfig['host'],
            $this->serverConfig['username'],
            $this->serverConfig['password']
        );
    }

    /**
     * 初始化启用的服务（包含元数据解析和独立统计初始化）
     * @throws Exception
     */
    private function initEnabledServices()
    {
        foreach ($this->serviceConfig as $serviceKey => $service) {
            if (empty($service['enable'])) {
                continue;
            }

            $serviceClass = $service['serviceName'];
            if (!class_exists($serviceClass)) {
                throw new Exception("服务类不存在：{$serviceClass}（服务标识：{$serviceKey}）");
            }

            // 实例化服务类
            $serviceInstance = new $serviceClass();

            // 解析服务元数据（方法、参数、类型等）
            $metadata = $this->parseServiceMetadata($serviceInstance, $serviceKey);

            // 生成安全的Nacos服务名
            $nacosServiceName = $this->generateSafeNacosName($serviceKey);

            $this->enabledServices[$serviceKey] = [
                'serviceKey' => $serviceKey,
                'serviceClass' => $serviceClass,
                'nacosServiceName' => $nacosServiceName,
                'instance' => $serviceInstance,
                'namespace' => $service['namespace'] ?? 'public',
                'metadata' => $metadata
            ];

            // 为每个服务初始化独立的统计数据
            $this->requestStats[$serviceKey] = [
                'window' => [],
                'currentWeight' => (float)$this->instanceConfig['weight'],
                'currentHealthy' => true,
                'lastWeightAdjust' => 0,
                'lastHealthAdjust' => 0
            ];

            echo "[初始化] 已加载服务：{$serviceKey} -> {$serviceClass}（元数据解析完成）\n";
        }
    }

    /**
     * 解析服务元数据
     */
    private function parseServiceMetadata($serviceInstance, string $serviceKey): array
    {
        $reflection = new ReflectionClass($serviceInstance);
        $methods = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== get_class($serviceInstance)) {
                continue;
            }

            $methodName = $method->getName();
            $params = [];
            foreach ($method->getParameters() as $param) {
                $params[] = [
                    'name' => $param->getName(),
                    'type' => $param->getType() ? @(string)$param->getType() : 'mixed',
                    'required' => !$param->isOptional()
                ];
            }

            $methods[$methodName] = ['params' => $params];
        }

        $contract = $this->serviceConfig[$serviceKey]['contract'] ?? [];
        $complexMetadata = [
            'serviceKey' => $serviceKey,
            'methods' => $methods,
            'contract' => $contract
        ];

        return [
            'serviceMetadata' => json_encode($complexMetadata, JSON_UNESCAPED_UNICODE),
            'description' => "{$serviceKey}服务元数据"
        ];
    }

    /**
     * 生成安全的Nacos服务名
     */
    private function generateSafeNacosName(string $serviceKey): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $serviceKey);
        return "SERVICE@@{$safeKey}";
    }

    /**
     * 启动服务（核心入口）
     */
    public function run()
    {
        try {
            $this->registerToNacos();
            $this->startTcpServer();
            register_shutdown_function([$this, 'shutdown']);
            $this->eventLoop();
        } catch (Exception $e) {
            echo "[错误] 服务启动失败：{$e->getMessage()}\n";
            $this->shutdown();
            exit(1);
        }
    }

    /**
     * 注册实例到Nacos
     * @throws Exception
     */
    private function registerToNacos()
    {
        foreach ($this->enabledServices as $service) {
            $result = $this->nacosClient->createInstance(
                $service['nacosServiceName'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                $service['namespace'],
                $service['metadata'],
                (float)$this->instanceConfig['weight'],
                true,
                true
            );

            if (isset($result['error'])) {
                throw new Exception("Nacos注册失败（{$service['serviceKey']}）：{$result['error']}");
            }

            echo "[Nacos] 已注册服务：{$service['serviceKey']} -> {$service['nacosServiceName']}（IP：{$this->instanceConfig['ip']}:{$this->instanceConfig['port']}）\n";
        }
    }

    /**
     * 启动TCP服务
     * @throws Exception
     */
    private function startTcpServer()
    {
        $ip = $this->instanceConfig['ip'];
        $port = $this->instanceConfig['port'];

        $this->serverSocket = \socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->serverSocket === false) {
            throw new Exception("创建TCP套接字失败：" . \socket_strerror(\socket_last_error()));
        }

        \socket_set_nonblock($this->serverSocket);
        \socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (\socket_bind($this->serverSocket, '0.0.0.0', $port) === false) {
            throw new Exception("绑定端口失败（{$ip}:{$port}）：" . \socket_strerror(\socket_last_error($this->serverSocket)));
        }

        if (\socket_listen($this->serverSocket, 100) === false) {
            throw new Exception("监听端口失败（{$ip}:{$port}）：" . \socket_strerror(\socket_last_error($this->serverSocket)));
        }

        echo "[TCP服务] 已启动，监听：{$ip}:{$port}（JSON-RPC协议）\n";
    }

    /**
     * 事件循环（处理请求+心跳+健康检查）
     */
    private function eventLoop()
    {
        $lastHeartbeatTime = 0;
        $lastCheckTime = 0;
        $checkInterval = 5; // 健康检查间隔（秒）

        while (true) {
            $now = time();

            // 定时发送心跳
            if ($now - $lastHeartbeatTime >= $this->heartbeatInterval) {
                $this->sendNacosHeartbeat();
                $lastHeartbeatTime = $now;
            }

            // 定期检查所有服务的健康状态
            if ($now - $lastCheckTime >= $checkInterval) {
                foreach (array_keys($this->enabledServices) as $serviceKey) {
                    $this->checkHealthStatus($serviceKey);
                }
                $lastCheckTime = $now;
            }

            // 处理TCP请求
            $this->handleTcpRequests();

            usleep(10000);
        }
    }

    /**
     * 发送Nacos心跳
     */
    private function sendNacosHeartbeat()
    {
        foreach ($this->enabledServices as $service) {
            $result = $this->nacosClient->sendBeat(
                $service['nacosServiceName'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                $service['namespace'],
                $service['metadata'],
                true,
                $this->requestStats[$service['serviceKey']]['currentWeight'] // 使用当前权重发送心跳
            );

            if (isset($result['error'])) {
                echo "[心跳] 失败（{$service['serviceKey']}）：{$result['error']}\n";
            } else {
                echo "[心跳] 成功（{$service['serviceKey']}）（" . date('H:i:s') . "）\n";
            }
        }
    }

    /**
     * 处理TCP请求
     */
    private function handleTcpRequests()
    {
        $read = [$this->serverSocket];
        $write = [];
        $except = [];

        foreach ($this->clients as $clientSocket) {
            $clientId = (int)$clientSocket;
            $read[] = $clientSocket;
            if (!empty($this->writeBuffers[$clientId])) {
                $write[] = $clientSocket;
            }
            $except[] = $clientSocket;
        }

        $activity = \socket_select($read, $write, $except, 1);
        if ($activity === false) {
            $errorCode = \socket_last_error();
            if ($errorCode != \SOCKET_EINTR) {
                echo "[TCP] select错误：" . \socket_strerror($errorCode) . "\n";
            }
            return;
        }

        // 处理异常连接
        foreach ($except as $socket) {
            $clientId = (int)$socket;
            $clientAddr = $this->clientAddresses[$clientId] ?? "未知";
            echo "[TCP] 客户端异常（{$clientAddr}），关闭连接\n";
            $this->closeClient($socket);
        }

        // 处理新连接
        if (in_array($this->serverSocket, $read)) {
            $this->handleNewConnection();
        }

        // 处理客户端请求
        foreach ($read as $socket) {
            if ($socket === $this->serverSocket) {
                continue;
            }
            $this->handleClientRequest($socket);
        }

        // 发送响应
        foreach ($write as $socket) {
            $this->sendClientResponse($socket);
        }
    }

    /**
     * 处理新客户端连接
     */
    private function handleNewConnection()
    {
        $newClient = \socket_accept($this->serverSocket);
        if ($newClient === false) {
            return;
        }

        \socket_getpeername($newClient, $clientIp, $clientPort);
        $clientId = (int)$newClient;
        $clientAddr = "{$clientIp}:{$clientPort}";

        \socket_set_nonblock($newClient);

        $this->clients[$clientId] = $newClient;
        $this->clientAddresses[$clientId] = $clientAddr;
        $this->writeBuffers[$clientId] = [];

        echo "[TCP] 新客户端连接：{$clientAddr}（clientId：{$clientId}）\n";
    }

    /**
     * 处理客户端请求
     */
    private function handleClientRequest($socket)
    {
        $clientId = (int)$socket;
        $clientAddr = $this->clientAddresses[$clientId] ?? "未知";

        $data = \socket_read($socket, 4096);
        if ($data === false) {
            $errorCode = \socket_last_error($socket);
            $errorMsg = in_array($errorCode, [\SOCKET_ECONNRESET, \SOCKET_ETIMEDOUT])
                ? "Client closed connection or timeout"
                : "Read error (code: {$errorCode})";
            echo "[TCP] 读取错误（{$clientAddr}）：{$errorMsg}\n";
            $this->closeClient($socket);
            return;
        }

        if (trim($data) === '') {
            echo "[TCP] 客户端断开（{$clientAddr}）\n";
            $this->closeClient($socket);
            return;
        }

        echo "[TCP] 收到请求（{$clientAddr}）：{$data}";
        $response = $this->processJsonRpcRequest(trim($data));
        $this->writeBuffers[$clientId][] = $response . "\n";
    }

    /**
     * 发送客户端响应
     */
    private function sendClientResponse($socket)
    {
        $clientId = (int)$socket;
        $clientAddr = $this->clientAddresses[$clientId] ?? "未知";

        while (!empty($this->writeBuffers[$clientId])) {
            $response = array_shift($this->writeBuffers[$clientId]);
            $bytesWritten = \socket_write($socket, $response);

            if ($bytesWritten === false) {
                echo "[TCP] 发送失败（{$clientAddr}）：" . \socket_strerror(\socket_last_error($socket)) . "\n";
                $this->closeClient($socket);
                break;
            }

            if ($bytesWritten < strlen($response)) {
                $remaining = substr($response, $bytesWritten);
                array_unshift($this->writeBuffers[$clientId], $remaining);
                break;
            }

            echo "[TCP] 发送响应（{$clientAddr}）：{$response}";
        }
    }

    /**
     * 处理JSON-RPC请求（包含性能统计）
     */
    private function processJsonRpcRequest(string $jsonData): string
    {
        $request = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->buildJsonRpcResponse(null, self::ERROR_PARSE);
        }

        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== self::JSON_RPC_VERSION
            || !isset($request['method']) || !isset($request['id'])) {
            return $this->buildJsonRpcResponse($request['id'] ?? null, self::ERROR_INVALID_REQUEST);
        }

        $requestId = $request['id'];
        $method = $request['method'];
        $params = $request['params'] ?? [];

        $methodParts = explode('.', $method, 2);
        if (count($methodParts) !== 2) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32600,
                'message' => 'method格式错误（应为：服务标识.方法名）'
            ]);
        }
        list($serviceKey, $funcName) = $methodParts;

        if (!isset($this->enabledServices[$serviceKey])) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32601,
                'message' => "服务不存在（标识：{$serviceKey}），可用服务：" . implode(',', array_keys($this->enabledServices))
            ]);
        }

        $service = $this->enabledServices[$serviceKey];
        $serviceInstance = $service['instance'];
        $serviceMetadata = $service["metadata"]['serviceMetadata'] ?? "";
        $contract = $serviceMetadata ? json_decode($serviceMetadata, true)['contract'] ?? [] : [];
        $methodName = $contract[$funcName] ?? $funcName;

        if (!method_exists($serviceInstance, $methodName)) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32601,
                'message' => "服务{$serviceKey}不存在方法：{$methodName}"
            ]);
        }

        $paramRules = $service['metadata']['methods'][$methodName]['params'] ?? [];
        $paramValidation = $this->validateParams($params, $paramRules);
        if (!$paramValidation['valid']) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32602,
                'message' => $paramValidation['message']
            ]);
        }

        // 记录请求处理时间用于超时判断
        $startTime = microtime(true) * 1000;
        try {
            $result = call_user_func_array([$serviceInstance, $methodName], $params);
            $endTime = microtime(true) * 1000;
            $isTimeout = ($endTime - $startTime) > $this->timeoutThreshold;
            $this->recordRequestStats($serviceKey, $isTimeout, false);
            return $this->buildJsonRpcResponse($requestId, null, $result);
        } catch (Exception $e) {
            $endTime = microtime(true) * 1000;
            $isTimeout = ($endTime - $startTime) > $this->timeoutThreshold;
            $this->recordRequestStats($serviceKey, $isTimeout, true);
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32603,
                'message' => "方法调用异常：{$e->getMessage()}"
            ]);
        }
    }

    /**
     * 验证参数
     */
    private function validateParams(array $params, array $paramRules): array
    {
        $requiredCount = count(array_filter($paramRules, function($rule) {
            return $rule['required'];
        }));
        if (count($params) < $requiredCount) {
            return [
                'valid' => false,
                'message' => "参数数量不足（至少需要{$requiredCount}个必填参数）"
            ];
        }

        foreach ($paramRules as $index => $rule) {
            if (!isset($params[$index]) && !$rule['required']) {
                continue;
            }

            $paramValue = $params[$index] ?? null;
            $paramType = gettype($paramValue);
            $expectedType = $rule['type'];

            $typeMap = ['integer' => 'int', 'boolean' => 'bool', 'double' => 'float'];
            $actualType = $typeMap[$paramType] ?? $paramType;

            if ($actualType !== $expectedType && $expectedType !== 'mixed') {
                return [
                    'valid' => false,
                    'message' => "参数{$rule['name']}类型错误（期望{$expectedType}，实际{$actualType}）"
                ];
            }
        }

        return ['valid' => true, 'message' => '参数验证通过'];
    }

    /**
     * 构建JSON-RPC响应
     */
    private function buildJsonRpcResponse($id, ?array $error, $result = null): string
    {
        $response = [
            'jsonrpc' => self::JSON_RPC_VERSION,
            'id' => $id
        ];
        if ($error) {
            $response['error'] = $error;
        } else {
            $response['result'] = $result;
        }
        return json_encode($response, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 关闭客户端连接
     */
    private function closeClient($socket)
    {
        $clientId = (int)$socket;
        if (isset($this->clients[$clientId])) {
            \socket_close($this->clients[$clientId]);
            unset(
                $this->clients[$clientId],
                $this->writeBuffers[$clientId],
                $this->clientAddresses[$clientId]
            );
        }
    }

    /**
     * 优雅退出
     */
    public function shutdown()
    {
        echo "\n[退出] 开始清理资源...\n";

        foreach ($this->enabledServices as $service) {
            $this->nacosClient->removeInstance(
                $service['nacosServiceName'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                $service['namespace'],
                'true'
            );
            echo "[退出] 已注销服务：{$service['serviceKey']}\n";
        }

        foreach ($this->clients as $socket) {
            \socket_close($socket);
        }

        if ($this->serverSocket) {
            \socket_close($this->serverSocket);
        }

        echo "[退出] 资源清理完成\n";
    }

    /**
     * 记录请求统计（按服务分组）
     */
    private function recordRequestStats(string $serviceKey, bool $isTimeout, bool $isError)
    {
        if (!isset($this->requestStats[$serviceKey])) {
            return;
        }

        $this->requestStats[$serviceKey]['window'][] = [
            'timeout' => $isTimeout,
            'error' => $isError,
            'time' => time()
        ];

        // 保持窗口大小
        if (count($this->requestStats[$serviceKey]['window']) > $this->statWindowSize) {
            array_shift($this->requestStats[$serviceKey]['window']);
        }
    }

    /**
     * 检查单个服务的健康状态
     */
    private function checkHealthStatus(string $serviceKey)
    {
        $stats = $this->requestStats[$serviceKey];
        $window = $stats['window'];
        $totalRequests = count($window);

        // 至少需要10个请求才计算（避免小样本误差）
        if ($totalRequests < 10) {
            return;
        }

        // 计算超时率和错误率
        $timeoutCount = 0;
        $errorCount = 0;
        foreach ($window as $req) {
            if ($req['timeout']) $timeoutCount++;
            if ($req['error']) $errorCount++;
        }
        $timeoutRate = $timeoutCount / $totalRequests;
        $errorRate = $errorCount / $totalRequests;
        $now = time();

        // 处理错误率（熔断）
        $this->handleErrorRate($serviceKey, $errorRate, $now);

        // 处理超时率（降级）
        $this->handleTimeoutRate($serviceKey, $timeoutRate, $now);
    }

    /**
     * 处理错误率（熔断逻辑）
     */
    private function handleErrorRate(string $serviceKey, float $errorRate, int $now)
    {
        $service = $this->enabledServices[$serviceKey];
        $stats = $this->requestStats[$serviceKey];
        $coolDownPassed = ($now - $stats['lastHealthAdjust']) > $this->adjustCoolDown;
        $metadata = $service['metadata']; // 获取服务元数据

        // 错误率≥50% 且 冷却时间已过 且 当前健康
        if ($errorRate >= 0.5 && $coolDownPassed && $stats['currentHealthy']) {
            $result = $this->nacosClient->updateInstanceHealthy(
                $service['nacosServiceName'],
                $service['namespace'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                false,
                false, // 不清除元数据
                $metadata // 显式传递元数据
            );
            if (isset($result['error'])) {
                echo "[{$serviceKey}服务] 熔断失败（错误率{$errorRate}）：{$result['error']}\n";
            } else {
                echo "[{$serviceKey}服务] 触发熔断（错误率{$errorRate}），标记为不健康\n";
                $this->requestStats[$serviceKey]['currentHealthy'] = false;
                $this->requestStats[$serviceKey]['lastHealthAdjust'] = $now;
            }
            return;
        }

        // 错误率<50% 且 冷却时间已过 且 当前不健康（恢复）
        if ($errorRate < 0.5 && $coolDownPassed && !$stats['currentHealthy']) {
            $result = $this->nacosClient->updateInstanceHealthy(
                $service['nacosServiceName'],
                $service['namespace'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                true,
                false, // 不清除元数据
                $metadata // 显式传递元数据
            );
            if (isset($result['error'])) {
                echo "[{$serviceKey}服务] 恢复健康失败（错误率{$errorRate}）：{$result['error']}\n";
            } else {
                echo "[{$serviceKey}服务] 恢复健康（错误率{$errorRate}），标记为健康\n";
                $this->requestStats[$serviceKey]['currentHealthy'] = true;
                $this->requestStats[$serviceKey]['lastHealthAdjust'] = $now;
            }
        }
    }

    /**
     * 处理超时率（降级逻辑，支持逐步恢复）
     */
    private function handleTimeoutRate(string $serviceKey, float $timeoutRate, int $now)
    {
        $service = $this->enabledServices[$serviceKey];
        $stats = $this->requestStats[$serviceKey];
        $originalWeight = (float)$this->instanceConfig['weight'];
        $coolDownPassed = ($now - $stats['lastWeightAdjust']) > $this->adjustCoolDown;
        $currentWeight = $stats['currentWeight'];
        $metadata = $service['metadata'];
        $ephemeral = true; // 统一使用临时实例（与注册时保持一致）

        // 降级逻辑：超时率≥50% 且 冷却时间已过 且 权重未到最低
        if ($timeoutRate >= 0.5 && $coolDownPassed) {
            $newWeight = max(0.1, $currentWeight * 0.5);
            if (abs($newWeight - $currentWeight) < 0.001) {
                return;
            }
            $result = $this->nacosClient->updateWeight(
                $service['nacosServiceName'],
                $service['namespace'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                $newWeight,
                $ephemeral, // 使用统一的ephemeral设置
                $metadata
            );
            if (isset($result['error'])) {
                echo "[{$serviceKey}服务] 降级失败（超时率{$timeoutRate}）：{$result['error']}\n";
            } else {
                echo "[{$serviceKey}服务] 触发降级（超时率{$timeoutRate}），权重从{$currentWeight}调整为{$newWeight}\n";
                $this->requestStats[$serviceKey]['currentWeight'] = $newWeight;
                $this->requestStats[$serviceKey]['lastWeightAdjust'] = $now;
            }
            return;
        }

        // 恢复逻辑
        if ($coolDownPassed && $currentWeight < $originalWeight) {
            $recoveryFactor = 1 + (0.5 - $timeoutRate) * 2;
            $newWeight = min($originalWeight, $currentWeight * $recoveryFactor);
            if (abs($newWeight - $currentWeight) < 0.001) {
                return;
            }

            $result = $this->nacosClient->updateWeight(
                $service['nacosServiceName'],
                $service['namespace'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                $newWeight,
                $ephemeral, // 使用统一的ephemeral设置
                $metadata
            );
            if (isset($result['error'])) {
                echo "[{$serviceKey}服务] 恢复权重失败（超时率{$timeoutRate}）：{$result['error']}\n";
            } else {
                echo "[{$serviceKey}服务] 恢复权重（超时率{$timeoutRate}），权重从{$currentWeight}调整为{$newWeight}\n";
                $this->requestStats[$serviceKey]['currentWeight'] = $newWeight;
                $this->requestStats[$serviceKey]['lastWeightAdjust'] = $now;
            }
        }
    }
}