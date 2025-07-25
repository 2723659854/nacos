<?php

namespace Xiaosongshu\Nacos;

use Exception;
use ReflectionClass;
use ReflectionMethod;

/**
 * 微服务服务端（支持元数据自动上报，符合JSON-RPC 2.0规范）
 * 核心特性：
 * 1. 自动解析服务接口元数据（方法、参数、类型）
 * 2. 注册到Nacos时携带元数据，供客户端查询
 * 3. 自动校验客户端请求参数
 * @purpose 微服务服务端
 * @author yanglong
 * @time 2025年7月25日17:34:12
 */
class Server
{
    /** 配置参数 */
    private $config;
    private $serverConfig;       // Nacos服务器配置
    private $instanceConfig;     // 实例配置（IP/端口等）
    private $serviceConfig;      // 服务配置（服务标识映射）
    private $heartbeatInterval;  // 心跳间隔（秒）

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
     * 初始化启用的服务（包含元数据解析）
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
                'metadata' => $metadata // 包含接口元数据
            ];

            echo "[初始化] 已加载服务：{$serviceKey} -> {$serviceClass}（元数据解析完成）\n";
        }
    }

    /**
     * 解析服务元数据（序列化为JSON字符串，避免Nacos格式错误）
     */
    private function parseServiceMetadata($serviceInstance, string $serviceKey): array
    {
        $reflection = new ReflectionClass($serviceInstance);
        $methods = [];

        // 遍历所有公共方法
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

            $methods[$methodName] = [
                'params' => $params
            ];
        }

        // 当前服务的契约 就是功能和方法的映射关系
        $contract = $this->serviceConfig[$serviceKey]['contract'] ?? [];
        // 复杂元数据序列化为JSON字符串
        $complexMetadata = [
            'serviceKey' => $serviceKey,
            'methods' => $methods,
            'contract' => $contract
        ];

        // 返回扁平的键值对（Nacos可接受的格式）
        return [
            'serviceMetadata' => json_encode($complexMetadata, JSON_UNESCAPED_UNICODE),
            'description' => "{$serviceKey}服务元数据" // 简单描述（非嵌套）
        ];
    }

    /**
     * 从方法注释中提取参数描述（简化实现）
     */
    private function getParamDescription(ReflectionMethod $method, string $paramName): string
    {
        $comment = $method->getDocComment() ?: '';
        preg_match("/@param\s+\S+\s+\$$paramName\s+(.*?)\r?\n/", $comment, $matches);
        return $matches[1] ?? "参数{$paramName}";
    }

    /**
     * 从方法注释中提取方法描述
     */
    private function getMethodDescription(ReflectionMethod $method): string
    {
        $comment = $method->getDocComment() ?: '';
        preg_match("/\/\*\*\s*(\S.*?)\r?\n/", $comment, $matches);
        return $matches[1] ?? "未定义描述";
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
            $this->registerToNacos(); // 注册到Nacos（携带元数据）
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
     * 注册实例到Nacos（携带元数据）
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
                $service['metadata'], // 上报元数据
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
     * 事件循环（处理请求+心跳）
     */
    private function eventLoop()
    {
        $lastHeartbeatTime = 0;

        while (true) {
            $now = time();

            // 定时发送心跳
            if ($now - $lastHeartbeatTime >= $this->heartbeatInterval) {
                $this->sendNacosHeartbeat();
                $lastHeartbeatTime = $now;
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
                (float)$this->instanceConfig['weight']
            );

            if (isset($result['error'])) {
                echo "[心跳] 失败（{$service['serviceKey']}）=>{$service['serviceClass']}：{$result['error']}\n";
            } else {
                echo "[心跳] 成功（{$service['serviceKey']}）=>{$service['serviceClass']}（" . date('H:i:s') . "）\n";
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
     * 处理客户端请求（包含参数校验）
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
     * 处理JSON-RPC请求（包含参数校验）
     */
    private function processJsonRpcRequest(string $jsonData): string
    {
        // 验证JSON格式
        $request = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->buildJsonRpcResponse(null, self::ERROR_PARSE);
        }

        // 验证JSON-RPC规范
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== self::JSON_RPC_VERSION
            || !isset($request['method']) || !isset($request['id'])) {
            return $this->buildJsonRpcResponse($request['id'] ?? null, self::ERROR_INVALID_REQUEST);
        }

        $requestId = $request['id'];
        $method = $request['method'];
        $params = $request['params'] ?? [];

        // 解析方法（格式：服务标识.方法名，如login.login）
        $methodParts = explode('.', $method, 2);
        if (count($methodParts) !== 2) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32600,
                'message' => 'method格式错误（应为：服务标识.方法名，如login.login）'
            ]);
        }
        // 解析用户请求的服务和方法
        list($serviceKey,  $funcName) = $methodParts;

        // 验证服务标识是否存在
        if (!isset($this->enabledServices[$serviceKey])) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32601,
                'message' => "服务不存在（标识：{$serviceKey}），可用服务：" . implode(',', array_keys($this->enabledServices))
            ]);
        }
        // 获取服务类
        $service = $this->enabledServices[$serviceKey];
        // 获取服务的元数据
        $serviceMetadata = $service["metadata"]['serviceMetadata']??"";
        if ($serviceMetadata) {
            $serviceMetadata = json_decode($serviceMetadata, true);
            // 获取契约
            $contract = $serviceMetadata['contract'] ?? [];
        }else{
            $contract = [];
        }

        // 如果定义了契约，则调用契约，否则调用原来的方法
        $methodName = $contract[$funcName] ?? $funcName;

        // 验证方法是否存在
        $serviceInstance = $service['instance'];
        if (!method_exists($serviceInstance, $methodName)) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32601,
                'message' => "服务{$serviceKey}不存在方法：{$methodName}"
            ]);
        }

        // 验证参数（基于元数据）
        $paramValidation = $this->validateParams(
            $params,
            $service['metadata']['methods'][$methodName]['params'] ?? []
        );
        if (!$paramValidation['valid']) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32602,
                'message' => $paramValidation['message']
            ]);
        }

        // 调用服务方法
        try {
            $result = call_user_func_array([$serviceInstance, $methodName], $params);
            return $this->buildJsonRpcResponse($requestId, null, $result);
        } catch (Exception $e) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32603,
                'message' => "方法调用异常：{$e->getMessage()}"
            ]);
        }
    }

    /**
     * 基于元数据验证参数
     */
    private function validateParams(array $params, array $paramRules): array
    {
        // 检查参数数量是否匹配（必填项+可选参数）
        $requiredCount = count(array_filter($paramRules, function($rule) {
            return $rule['required'];
        }));
        if (count($params) < $requiredCount) {
            return [
                'valid' => false,
                'message' => "参数数量不足（至少需要{$requiredCount}个必填参数）"
            ];
        }

        // 检查每个参数的类型
        foreach ($paramRules as $index => $rule) {
            // 跳过可选参数（未传参时不校验）
            if (!isset($params[$index]) && !$rule['required']) {
                continue;
            }

            $paramValue = $params[$index] ?? null;
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

        // 从Nacos注销实例
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

        // 关闭客户端连接
        foreach ($this->clients as $socket) {
            \socket_close($socket);
        }

        // 关闭TCP服务
        if ($this->serverSocket) {
            \socket_close($this->serverSocket);
        }

        echo "[退出] 资源清理完成\n";
    }
}
