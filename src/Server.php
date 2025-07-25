<?php

namespace Xiaosongshu\Nacos;

use Exception;

/**
 * Nacos微服务服务端（支持服务标识解耦与JSON-RPC 2.0）
 * 核心特性：服务标识与实现分离、自动处理Nacos服务注册、安全隐藏代码结构
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
    const ERROR_INVALID_PARAMS = ['code' => -32602, 'message' => '参数无效（必须是数组）'];
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
        $this->initEnabledServices();
    }

    /**
     * 验证配置合法性
     * @throws Exception
     */
    private function validateConfig()
    {
        // 验证Nacos服务器配置
        if (empty($this->config['server']['host']) || empty($this->config['server']['username']) || empty($this->config['server']['password'])) {
            throw new Exception("Nacos配置不完整（host/username/password不能为空）");
        }

        // 验证实例配置
        if (empty($this->config['instance']['ip']) || empty($this->config['instance']['port'])) {
            throw new Exception("实例配置不完整（ip/port不能为空）");
        }

        // 验证服务配置（至少启用一个服务）
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
        $this->heartbeatInterval = $this->config['server']['heartbeat_interval'] ?? 5; // 默认5秒心跳
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
     * 初始化启用的服务（服务标识与实现类映射）
     * @throws Exception
     */
    private function initEnabledServices()
    {
        foreach ($this->serviceConfig as $serviceKey => $service) {
            // 跳过未启用的服务
            if (empty($service['enable'])) {
                continue;
            }

            $serviceClass = $service['serviceName'];
            // 验证服务类是否存在
            if (!class_exists($serviceClass)) {
                throw new Exception("服务类不存在：{$serviceClass}（服务标识：{$serviceKey}）");
            }

            // 生成安全的Nacos服务名（隐藏类名）
            $nacosServiceName = $this->generateSafeNacosName($serviceKey);

            // 实例化服务类
            $serviceInstance = new $serviceClass();
            $this->enabledServices[$serviceKey] = [
                'serviceKey' => $serviceKey,         // 服务标识（如demo）
                'serviceClass' => $serviceClass,     // 实际实现类（内部使用）
                'nacosServiceName' => $nacosServiceName, // 注册到Nacos的服务名
                'instance' => $serviceInstance,      // 服务实例
                'namespace' => $service['namespace'] ?? 'public',
                'metadata' => $service['metadata'] ?? []
            ];

            echo "[初始化] 已加载服务：{$serviceKey} -> {$serviceClass}\n";
        }
    }

    /**
     * 生成安全的Nacos服务名（避免特殊字符，隐藏实现）
     * @param string $serviceKey 服务标识（如demo）
     * @return string 安全服务名（如SERVICE@@demo）
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
            // 注册实例到Nacos
            $this->registerToNacos();

            // 启动TCP服务
            $this->startTcpServer();

            // 注册优雅退出钩子
            register_shutdown_function([$this, 'shutdown']);

            // 进入事件循环
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
                $service['nacosServiceName'], // 使用安全服务名注册
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                $service['namespace'],
                $service['metadata'],
                (float)$this->instanceConfig['weight'],
                true, // 健康状态（临时实例由心跳决定）
                true  // 临时实例（需心跳）
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

        // 创建TCP套接字
        $this->serverSocket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->serverSocket === false) {
            throw new Exception("创建TCP套接字失败：" . socket_strerror(socket_last_error()));
        }

        // 设置非阻塞模式
        socket_set_nonblock($this->serverSocket);

        // 允许端口复用
        socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);

        // 绑定IP和端口
        if (socket_bind($this->serverSocket, '0.0.0.0', $port) === false) {
            throw new Exception("绑定端口失败（{$ip}:{$port}）：" . socket_strerror(socket_last_error($this->serverSocket)));
        }

        // 开始监听
        if (socket_listen($this->serverSocket, 100) === false) {
            throw new Exception("监听端口失败（{$ip}:{$port}）：" . socket_strerror(socket_last_error($this->serverSocket)));
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

            // 降低CPU占用
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

        // 添加客户端套接字到监控集合
        foreach ($this->clients as $clientSocket) {
            $clientId = (int)$clientSocket;
            $read[] = $clientSocket;

            if (!empty($this->writeBuffers[$clientId])) {
                $write[] = $clientSocket;
            }
            $except[] = $clientSocket;
        }

        // 监控事件
        $activity = socket_select($read, $write, $except, 1);
        if ($activity === false) {
            $errorCode = socket_last_error();
            if ($errorCode != SOCKET_EINTR) {
                echo "[TCP] select错误：" . socket_strerror($errorCode) . "\n";
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
        $newClient = socket_accept($this->serverSocket);
        if ($newClient === false) {
            return;
        }

        socket_getpeername($newClient, $clientIp, $clientPort);
        $clientId = (int)$newClient;
        $clientAddr = "{$clientIp}:{$clientPort}";

        socket_set_nonblock($newClient);

        $this->clients[$clientId] = $newClient;
        $this->clientAddresses[$clientId] = $clientAddr;
        $this->writeBuffers[$clientId] = [];

        // 发送欢迎消息
        $welcomeMsg = $this->buildJsonRpcResponse(null, null, [
            'message' => '已连接到JSON-RPC服务',
            'request_format' => '{"jsonrpc":"2.0","method":"服务标识.方法名","params":[参数],"id":"请求ID"}',
            'example' => '{"jsonrpc":"2.0","method":"demo.add","params":["tom",18],"id":"1"}'
        ]);
        //$this->writeBuffers[$clientId][] = $welcomeMsg . "\n";

        echo "[TCP] 新客户端连接：{$clientAddr}（clientId：{$clientId}）\n";
    }

    /**
     * 处理客户端请求（JSON-RPC解析）
     */
    private function handleClientRequest($socket)
    {
        $clientId = (int)$socket;
        $clientAddr = $this->clientAddresses[$clientId] ?? "未知";

        $data = socket_read($socket, 4096);
        if ($data === false) {
            $errorCode = socket_last_error($socket);
            if (!in_array($errorCode, [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK])) {
                //echo "[TCP] 读取错误（{$clientAddr}）：" . socket_strerror($errorCode) . "\n";
                $errorMsg = in_array($errorCode, [SOCKET_ECONNRESET, SOCKET_ETIMEDOUT])
                    ? "Client closed connection or timeout"
                    : "Read error (code: {$errorCode})";
                echo "[TCP] 读取错误（{$clientAddr}）：{$errorMsg}\n";
                $this->closeClient($socket);
            }
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
            $bytesWritten = socket_write($socket, $response);

            if ($bytesWritten === false) {
                echo "[TCP] 发送失败（{$clientAddr}）：" . socket_strerror(socket_last_error($socket)) . "\n";
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
     * 处理JSON-RPC请求（基于服务标识）
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

        // 解析方法（格式：服务标识.方法名，如demo.add）
        $methodParts = explode('.', $method, 2);
        if (count($methodParts) !== 2) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32600,
                'message' => 'method格式错误（应为：服务标识.方法名，如demo.add）'
            ]);
        }
        list($serviceKey, $methodName) = $methodParts;

        // 验证服务标识是否存在
        if (!isset($this->enabledServices[$serviceKey])) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32601,
                'message' => "服务不存在（标识：{$serviceKey}），可用服务：" . implode(',', array_keys($this->enabledServices))
            ]);
        }
        $service = $this->enabledServices[$serviceKey];

        // 验证方法是否存在
        $serviceInstance = $service['instance'];
        if (!method_exists($serviceInstance, $methodName)) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32601,
                'message' => "服务{$serviceKey}不存在方法：{$methodName}"
            ]);
        }

        // 验证参数
        if (!is_array($params)) {
            return $this->buildJsonRpcResponse($requestId, self::ERROR_INVALID_PARAMS);
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
            socket_close($this->clients[$clientId]);
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
            socket_close($socket);
        }

        // 关闭TCP服务
        if ($this->serverSocket) {
            socket_close($this->serverSocket);
        }

        echo "[退出] 资源清理完成\n";
    }
}
