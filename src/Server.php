<?php

namespace Xiaosongshu\Nacos;

use Exception;
use Xiaosongshu\Nacos\Client;

/**
 * Nacos微服务服务端（支持JSON-RPC 2.0协议）
 * 功能：自动注册Nacos、定时心跳、处理JSON-RPC请求、调用对应服务方法
 */
class Server
{
    /** 配置参数 */
    private $config;
    private $serverConfig;       // Nacos服务器配置
    private $instanceConfig;     // 实例配置（IP/端口等）
    private $serviceConfig;      // 服务配置（需注册的服务）
    private $heartbeatInterval;  // 心跳间隔（秒）

    /** 核心组件 */
    private $nacosClient;        // Nacos客户端（已提供的Client类）
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
     * @param array $config 配置数组（从配置文件读取）
     * @throws Exception
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->validateConfig();       // 验证配置合法性
        $this->initConfig();           // 初始化配置参数
        $this->initNacosClient();      // 初始化Nacos客户端
        $this->initEnabledServices();  // 初始化启用的服务
    }

    /**
     * 验证配置合法性（生产环境必备）
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
     * 初始化Nacos客户端（使用提供的Client类）
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
     * 初始化启用的服务（实例化服务类）
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

            // 实例化服务类
            $serviceInstance = new $serviceClass();
            $this->enabledServices[$serviceKey] = [
                'serviceKey' => $serviceKey,
                'serviceName' => $service['serviceName'], // 服务名（类名）
                'instance' => $serviceInstance,           // 服务实例
                'namespace' => $service['namespace'] ?? 'public', // Nacos命名空间
                'metadata' => $service['metadata'] ?? []  // 服务元数据
            ];

            echo "[初始化] 已加载服务：{$serviceClass}（服务标识：{$serviceKey}）\n";
        }
    }

    /**
     * 启动服务（核心入口）
     */
    public function run()
    {
        try {
            // 1. 初始化启用的服务
            $this->initEnabledServices();

            // 2. 注册实例到Nacos
            $this->registerToNacos();

            // 3. 启动TCP服务（监听端口）
            $this->startTcpServer();

            // 4. 注册优雅退出钩子（停止时清理资源）
            register_shutdown_function([$this, 'shutdown']);

            // 5. 进入事件循环（处理请求+定时心跳）
            $this->eventLoop();

        } catch (Exception $e) {
            echo "[错误] 服务启动失败：{$e->getMessage()}\n";
            $this->shutdown();
            exit(1);
        }
    }

    /**
     * 注册实例到Nacos（所有启用的服务）
     * @throws Exception
     */
    private function registerToNacos()
    {
        foreach ($this->enabledServices as $service) {
            $result = $this->nacosClient->createInstance(
                $service['serviceName'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                $service['namespace'],
                $service['metadata'],
                (float)$this->instanceConfig['weight'],
                true, // 健康状态（临时实例由心跳决定，此处无效）
                true  // 临时实例（需心跳）
            );

            if (isset($result['error'])) {
                throw new Exception("Nacos注册失败（{$service['serviceName']}）：{$result['error']}");
            }

            echo "[Nacos] 已注册服务到Nacos：{$service['serviceName']}（IP：{$this->instanceConfig['ip']}:{$this->instanceConfig['port']}）\n";
        }
    }

    /**
     * 启动TCP服务（监听端口，处理客户端连接）
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

        // 允许端口复用（避免重启时端口占用）
        socket_set_option($this->serverSocket, SOL_SOCKET, SO_REUSEADDR, 1);

        // 绑定IP和端口
        if (socket_bind($this->serverSocket, '0.0.0.0', $port) === false) {
            throw new Exception("绑定端口失败（{$ip}:{$port}）：" . socket_strerror(socket_last_error($this->serverSocket)));
        }

        // 开始监听（最大等待连接数100）
        if (socket_listen($this->serverSocket, 100) === false) {
            throw new Exception("监听端口失败（{$ip}:{$port}）：" . socket_strerror(socket_last_error($this->serverSocket)));
        }

        echo "[TCP服务] 已启动，监听：{$ip}:{$port}（JSON-RPC协议）\n";
    }

    /**
     * 事件循环（处理TCP请求+定时心跳）
     */
    private function eventLoop()
    {
        $lastHeartbeatTime = 0; // 上次心跳时间

        while (true) {
            $now = time();

            // 1. 定时发送Nacos心跳（每隔指定间隔）
            if ($now - $lastHeartbeatTime >= $this->heartbeatInterval) {
                $this->sendNacosHeartbeat();
                $lastHeartbeatTime = $now;
            }

            // 2. 处理TCP客户端请求
            $this->handleTcpRequests();

            // 3. 降低CPU占用（10ms）
            usleep(10000);
        }
    }

    /**
     * 发送Nacos心跳（所有启用的服务）
     */
    private function sendNacosHeartbeat()
    {
        foreach ($this->enabledServices as $service) {
            $result = $this->nacosClient->sendBeat(
                $service['serviceName'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                $service['namespace'],
                $service['metadata'],
                true, // 临时实例
                (float)$this->instanceConfig['weight']
            );

            if (isset($result['error'])) {
                echo "[心跳] 失败（{$service['serviceName']}）：{$result['error']}\n";
            } else {
                echo "[心跳] 成功（{$service['serviceName']}）（" . date('H:i:s') . "）\n";
            }
        }
    }

    /**
     * 处理TCP请求（基于select模型）
     */
    private function handleTcpRequests()
    {
        // 初始化文件描述符集合
        $read = [$this->serverSocket];
        $write = [];
        $except = [];

        // 添加客户端套接字到监控集合
        foreach ($this->clients as $clientSocket) {
            $clientId = (int)$clientSocket;
            $read[] = $clientSocket;

            // 有未发送数据则加入写集合
            if (!empty($this->writeBuffers[$clientId])) {
                $write[] = $clientSocket;
            }

            // 所有客户端加入异常监控
            $except[] = $clientSocket;
        }

        // 监控事件（超时1秒，避免阻塞心跳）
        $activity = socket_select($read, $write, $except, 1);
        if ($activity === false) {
            $errorCode = socket_last_error();
            if ($errorCode != SOCKET_EINTR) { // 忽略中断错误
                echo "[TCP] select错误：" . socket_strerror($errorCode) . "\n";
            }
            return;
        }

        // 1. 处理异常连接（优先关闭）
        foreach ($except as $socket) {
            $clientId = (int)$socket;
            $clientAddr = $this->clientAddresses[$clientId] ?? "未知";
            echo "[TCP] 客户端异常（{$clientAddr}），关闭连接\n";
            $this->closeClient($socket);
        }

        // 2. 处理新客户端连接
        if (in_array($this->serverSocket, $read)) {
            $this->handleNewConnection();
        }

        // 3. 处理客户端请求（JSON-RPC）
        foreach ($read as $socket) {
            // 跳过服务端套接字（已单独处理新连接）
            if ($socket === $this->serverSocket) {
                continue;
            }
            $this->handleClientRequest($socket);
        }

        // 4. 发送响应（写缓冲区数据）
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

        // 获取客户端地址
        socket_getpeername($newClient, $clientIp, $clientPort);
        $clientId = (int)$newClient;
        $clientAddr = "{$clientIp}:{$clientPort}";

        // 设置客户端为非阻塞模式
        socket_set_nonblock($newClient);

        // 存储客户端信息
        $this->clients[$clientId] = $newClient;
        $this->clientAddresses[$clientId] = $clientAddr;
        $this->writeBuffers[$clientId] = [];

        // 发送欢迎消息（JSON-RPC格式说明）
        $welcomeMsg = $this->buildJsonRpcResponse(null, null, [
            'message' => '已连接到JSON-RPC服务',
            'request_format' => '{"jsonrpc":"2.0","method":"服务标识.方法名","params":[参数],"id":"请求ID"}',
            'example' => '{"jsonrpc":"2.0","method":"demo.add","params":["tom",18],"id":"1"}'
        ]);
        $this->writeBuffers[$clientId][] = $welcomeMsg . "\n";

        echo "[TCP] 新客户端连接：{$clientAddr}（clientId：{$clientId}）\n";
    }

    /**
     * 处理客户端请求（解析JSON-RPC并调用方法）
     */
    private function handleClientRequest($socket)
    {
        $clientId = (int)$socket;
        $clientAddr = $this->clientAddresses[$clientId] ?? "未知";

        // 读取客户端数据
        $data = socket_read($socket, 4096);
        if ($data === false) {
            $errorCode = socket_last_error($socket);
            // 忽略非阻塞无数据的正常错误
            if (!in_array($errorCode, [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK])) {
                echo "[TCP] 读取错误（{$clientAddr}）：" . socket_strerror($errorCode) . "\n";
                $this->closeClient($socket);
            }
            return;
        }

        // 客户端断开连接（空数据）
        if (trim($data) === '') {
            echo "[TCP] 客户端断开（{$clientAddr}）\n";
            $this->closeClient($socket);
            return;
        }

        // 处理JSON-RPC请求
        echo "[TCP] 收到请求（{$clientAddr}）：{$data}";
        $response = $this->processJsonRpcRequest(trim($data));
        $this->writeBuffers[$clientId][] = $response . "\n";
    }

    /**
     * 发送客户端响应（写缓冲区数据）
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

            // 处理部分发送（非阻塞下可能发生）
            if ($bytesWritten < strlen($response)) {
                $remaining = substr($response, $bytesWritten);
                array_unshift($this->writeBuffers[$clientId], $remaining);
                break;
            }

            echo "[TCP] 发送响应（{$clientAddr}）：{$response}";
        }
    }

    /**
     * 处理JSON-RPC请求（核心逻辑，遵循2.0规范）
     */
    private function processJsonRpcRequest(string $jsonData): string
    {
        // 1. 验证JSON格式
        $request = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->buildJsonRpcResponse(null, self::ERROR_PARSE);
        }

        // 2. 验证JSON-RPC规范（必须包含jsonrpc、method、id）
        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== self::JSON_RPC_VERSION
            || !isset($request['method']) || !isset($request['id'])) {
            return $this->buildJsonRpcResponse($request['id'] ?? null, self::ERROR_INVALID_REQUEST);
        }

        $requestId = $request['id'];
        $method = $request['method'];
        $params = $request['params'] ?? [];

        // 3. 解析方法（格式：服务标识.方法名，如demo.add）
        $methodParts = explode('.', $method, 2);
        if (count($methodParts) !== 2) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32600,
                'message' => 'method格式错误（应为：服务标识.方法名，如demo.add）'
            ]);
        }
        list($serviceKey, $methodName) = $methodParts;

        // 4. 验证服务是否存在
        if (!isset($this->enabledServices[$serviceKey])) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32601,
                'message' => "服务不存在（serviceKey：{$serviceKey}），可用服务：" . implode(',', array_keys($this->enabledServices))
            ]);
        }
        $service = $this->enabledServices[$serviceKey];

        // 5. 验证方法是否存在
        $serviceInstance = $service['instance'];
        if (!method_exists($serviceInstance, $methodName)) {
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32601,
                'message' => "服务{$serviceKey}不存在方法：{$methodName}"
            ]);
        }

        // 6. 验证参数（必须是数组）
        if (!is_array($params)) {
            return $this->buildJsonRpcResponse($requestId, self::ERROR_INVALID_PARAMS);
        }

        // 7. 调用服务方法并返回结果
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
     * 关闭客户端连接（清理资源）
     */
    private function closeClient($socket)
    {
        $clientId = (int)$socket;
        if (isset($this->clients[$clientId])) {
            socket_close($this->clients[$clientId]);
            // 清理所有相关资源
            unset(
                $this->clients[$clientId],
                $this->writeBuffers[$clientId],
                $this->clientAddresses[$clientId]
            );
        }
    }

    /**
     * 优雅退出（清理资源）
     */
    public function shutdown()
    {
        echo "\n[退出] 开始清理资源...\n";

        // 1. 从Nacos注销实例（临时实例可选，但更优雅）
        foreach ($this->enabledServices as $service) {
            $this->nacosClient->removeInstance(
                $service['serviceName'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                $service['namespace'],
                'true' // 临时实例
            );
            echo "[退出] 已注销Nacos实例：{$service['serviceName']}\n";
        }

        // 2. 关闭所有客户端连接
        foreach ($this->clients as $socket) {
            socket_close($socket);
        }

        // 3. 关闭TCP服务
        if ($this->serverSocket) {
            socket_close($this->serverSocket);
        }

        echo "[退出] 资源清理完成\n";
    }
}