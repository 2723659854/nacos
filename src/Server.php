<?php

namespace Xiaosongshu\Nacos;

use \Exception;
use \ReflectionClass;
use \ReflectionMethod;
use Throwable;

/**
 * 微服务服务端（支持元数据自动上报、服务降级与熔断）
 * 核心特性：
 * 1. 自动解析服务接口元数据
 * 2. 基于超时率的服务降级（动态调整权重）
 * 3. 基于错误率的服务熔断（通过控制心跳实现）
 * 4. 每个服务独立统计与调整逻辑
 * 5. 支持配置监听（优化后支持长连接复用）
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

    /** 心跳控制开关（按服务标识分组，true=发送心跳，false=停止心跳） */
    private $sendHeartbeat = [];

    /** JSON-RPC常量（遵循2.0规范） */
    const JSON_RPC_VERSION = "2.0";
    const ERROR_PARSE = ['code' => -32700, 'message' => '解析错误（无效JSON格式）'];
    const ERROR_INVALID_REQUEST = ['code' => -32600, 'message' => '无效请求（不符合JSON-RPC规范）'];
    const ERROR_METHOD_NOT_FOUND = ['code' => -32601, 'message' => '方法不存在'];
    const ERROR_INVALID_PARAMS = ['code' => -32602, 'message' => '参数无效（类型或必填项错误）'];
    const ERROR_INTERNAL = ['code' => -32603, 'message' => '服务端内部错误'];

    /** 需要监听的配置 */
    private $configListen = [];

    private $configStreams = [];
    private $configStreamMap = [];

    /** 分隔符 */
    public const WORD_SEPARATOR = "\x02";
    public const LINE_SEPARATOR = "\x01";

    # 是否开启调试模式
    public $isDebug = false;

    /** 日志回调函数 */
    public $log = null;

    /**
     * 初始化
     * @param array $config 服务端相关配置
     * @param bool $isDebug 是否开启调试模式
     * @throws Exception
     */
    public function __construct(array $config, bool $isDebug = true)
    {
        $this->config = $config;
        $this->isDebug = $isDebug;
        if ($this->isDebug) {
            $this->info("[warn] 系统已启动debug模式，你可以设置isDebug=false关闭调试模式");
        }
        $this->validateConfig();
        $this->initConfig();
        $this->initNacosClient();
        $this->initEnabledServices();
        $this->initConfigListen();
    }

    /**
     * 启动非阻塞配置监听流（添加重复检查）
     */
    private function startConfigListenStream(string $name)
    {
        // 关键修复：如果已有相同名称的监听流在运行，则不创建新流
        if (isset($this->configStreamMap[$name])) {
            $existingSocket = $this->configStreamMap[$name];
            if (is_resource($existingSocket) && !feof($existingSocket)) {
                $this->info("[config] 已存在监听流：{$name}（ID: " . (int)$existingSocket . "），无需重复创建");
                return;
            }
        }

        // 清理旧流
        if (isset($this->configStreamMap[$name])) {
            $oldSocket = $this->configStreamMap[$name];
            if (is_resource($oldSocket)) {
                fclose($oldSocket);
            }
            unset($this->configStreams[(int)$oldSocket], $this->configStreamMap[$name]);
        }

        $config = $this->configListen[$name];
        $urlParts = parse_url($this->serverConfig['host']);
        $host = $urlParts['host'];
        $port = $urlParts['port'] ?? 80; // 仅使用HTTP
        $path = '/nacos/v1/cs/configs/listener';

        // 构建监听配置
        $listeningConfig = implode(self::WORD_SEPARATOR, [
                $config['dataId'],
                $config['group'],
                md5($config['content'] ?? '')
            ]) . self::LINE_SEPARATOR;

        $token = $this->nacosClient->getToken();
        $params = http_build_query([
            'Listening-Configs' => $listeningConfig,
            'tenant' => $config['tenant'] ?? 'public',
            'timeout' => 30000, // 30秒超时
            'accessToken' => $token
        ]);

        // 创建非阻塞socket（仅HTTP）
        $context = \stream_context_create();

        $socket = \stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            0,
            \STREAM_CLIENT_ASYNC_CONNECT,
            $context
        );

        if (!$socket) {
            $this->info("[config] 连接失败：{$name}，3秒后重试...");
            $this->configListen[$name]['retry'] = time() + 3;
            return;
        }

        \stream_set_blocking($socket, false);

        // 构造HTTP请求（启用长连接）
        $request = "POST {$path} HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $request .= "Content-Length: " . strlen($params) . "\r\n";
        $request .= "Long-Pulling-Timeout: 30000\r\n";
        $request .= "Connection: keep-alive\r\n";  // 启用长连接
        $request .= "Keep-Alive: 60\r\n";          // 保持连接超时时间
        $request .= "\r\n";                        // 空行分隔头和体
        $request .= $params;

        $streamId = (int)$socket;
        $this->configStreams[$streamId] = [
            'socket' => $socket,
            'name' => $name,
            'startTime' => time(),
            'sendBuffer' => $request  // 放入发送缓冲区，等待可写事件
        ];
        $this->configStreamMap[$name] = $socket;

        $this->info("[config] 启动监听流：{$name}（ID: {$streamId}）");
    }

    /**
     * 处理配置监听响应（优化连接复用）
     */
    private function processConfigResponse($socket, string $name)
    {
        $streamId = (int)$socket;
        $configChanged = false; // 标记是否发生了配置变更

        try {
            $config = $this->configListen[$name];
            $responseData = '';

            // 读取响应数据
            while (!feof($socket)) {
                $buffer = @fread($socket, 4096);
                # 不等待，没有消息直接跳过
                if ($buffer === false || $buffer === "") {
                    break;
                }
                $responseData .= $buffer;
            }

            if (empty($responseData)) {
                $this->info("[config] {$name} 长轮询超时（无数据）");
                return;
            }

            // 解析HTTP头
            $headerEndPos = strpos($responseData, "\r\n\r\n");
            if ($headerEndPos === false) {
                if (strpos($responseData, "HTTP/1.1 200 OK") !== false) {
                    $this->info("[config] {$name} 配置未变化（超时）");
                    return;
                }
                throw new \Exception("无效的HTTP响应格式");
            }

            // 解析状态码
            $headers = explode("\r\n", substr($responseData, 0, $headerEndPos));
            $httpStatus = 200;
            foreach ($headers as $header) {
                if (preg_match('/^HTTP\/\d\.\d (\d+)/', $header, $matches)) {
                    $httpStatus = (int)$matches[1];
                    break;
                }
            }

            // 处理错误状态码
            if (in_array($httpStatus, [401, 403])) {
                $this->info("[config] {$name} 认证失败（{$httpStatus}），刷新token...");
                $this->nacosClient->getToken(true);
                $this->configListen[$name]['retry'] = time() + 2;
                return;
            }

            if ($httpStatus == 400) {
                $body = $this->parseHttpBody($responseData, $headerEndPos, $headers);
                $this->info("[config] {$name} 请求参数错误（400）：{$body}");
                $this->configListen[$name]['retry'] = time() + 3;
                return;
            }

            // 获取并处理响应体
            $body = $this->parseHttpBody($responseData, $headerEndPos, $headers);
            $body = trim($body);

            if ($httpStatus == 200 && !empty($body)) {
                // 解码URL编码的响应
                $decodedBody = urldecode($body);
                $this->info("[debug] 原始响应: {$body} | 解码后: {$decodedBody} | 监听配置: dataId={$config['dataId']}, group={$config['group']}");
                // 分割变更条目
                $changeEntries = explode(self::LINE_SEPARATOR, $decodedBody);
                foreach ($changeEntries as $entry) {
                    $entry = trim($entry);
                    if (empty($entry)) {
                        continue;
                    }

                    // 使用Nacos标准分隔符分割（\x02）
                    $parts = explode("\x02", $entry);
                    $changedDataId = $parts[0] ?? '';
                    $changedGroup = $parts[1] ?? '';

                    // 标准化group（默认值处理）
                    $normalizedConfigGroup = empty($config['group']) ? 'default' : $config['group'];
                    $normalizedChangedGroup = empty($changedGroup) ? 'default' : $changedGroup;

                    // 精确匹配dataId和group
                    if ($changedDataId === $config['dataId'] && $normalizedChangedGroup === $normalizedConfigGroup) {
                        $this->info("[config] {$name} 配置发生变化（dataId: {$changedDataId}, group: {$changedGroup}）");
                        $configChanged = true; // 标记发生了配置变更

                        // 获取最新配置
                        $configFromNacos = $this->nacosClient->getConfig(
                            $config['dataId'],
                            $normalizedConfigGroup,
                            $config['tenant'] ?? 'public'
                        );

                        if ($configFromNacos['code'] == 200) {
                            $content = $configFromNacos['content'];
                            if (stripos($content,"config data not exist") !== false) {
                                $this->info("[warn] 配置{$name}不存在，请检查服务器上是否有正确的配置");
                                break;
                            }
                            if ($content !== $this->configListen[$name]['content']) {
                                $this->configListen[$name]['content'] = $content;

                                if ($config['callback'] && is_callable($config['callback'])) {
                                    try {
                                        call_user_func($config['callback'], $content);
                                        $this->info("[info] 配置{$name}更新成功");
                                    } catch (\Throwable $exception) {
                                        $this->info("[error] 配置{$name}更新失败，发生了异常：File:" . $exception->getFile() . " line:" . $exception->getLine() . " message:" . $exception->getMessage());
                                    }
                                } else {
                                    $this->info("[warn] 配置{$name}发生了变化，但未处理，请检查是否设置了正确的回调函数");
                                }
                            }
                        }
                        break; // 处理完匹配的变更后跳出循环
                    }
                }

                // 未匹配到的详细日志
                if (!$configChanged) {
                    $this->info("[config] 未匹配变更 - 监听(dataId:{$config['dataId']})，收到:{$decodedBody}");
                }
            } else {
                $this->info("[config] {$name} 配置未变化（长轮询超时）");
            }
        } catch (Exception $e) {
            $this->info("[config] {$name} 处理异常：{$e->getMessage()} ");
            $this->configListen[$name]['retry'] = time() + 3;
            // 异常情况下需要关闭连接
            if (is_resource($socket)) {
                fclose($socket);
            }
            unset($this->configStreams[$streamId], $this->configStreamMap[$name]);
            $this->startConfigListenStream($name);
            return;
        }

        // 正常处理完成后复用连接
        if (is_resource($socket) && !feof($socket)) {
            // 准备下一次请求
            $this->prepareNextRequest($socket, $name);
        } else {
            $this->info("[config] {$name} 连接无法复用，释放连接 ");
            // 连接无效时重建
            if (is_resource($socket)) {
                fclose($socket);
            }
            unset($this->configStreams[$streamId], $this->configStreamMap[$name]);
            $this->startConfigListenStream($name);
        }
    }

    /**
     * 准备下一次长轮询请求（复用现有连接）
     */
    private function prepareNextRequest($socket, string $name)
    {
        $streamId = (int)$socket;
        $config = $this->configListen[$name];

        // 构建新的监听配置
        $listeningConfig = implode(self::WORD_SEPARATOR, [
                $config['dataId'],
                $config['group'],
                md5($config['content'] ?? '')
            ]) . self::LINE_SEPARATOR;

        $token = $this->nacosClient->getToken();
        $params = http_build_query([
            'Listening-Configs' => $listeningConfig,
            'tenant' => $config['tenant'] ?? 'public',
            'timeout' => 30000, // 30秒超时
            'accessToken' => $token
        ]);

        // 构造新的HTTP请求
        $urlParts = parse_url($this->serverConfig['host']);
        $host = $urlParts['host'];

        $request = "POST /nacos/v1/cs/configs/listener HTTP/1.1\r\n";
        $request .= "Host: {$host}\r\n";
        $request .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $request .= "Content-Length: " . strlen($params) . "\r\n";
        $request .= "Long-Pulling-Timeout: 30000\r\n";
        $request .= "Connection: keep-alive\r\n";
        $request .= "Keep-Alive: 60\r\n";
        $request .= "\r\n";
        $request .= $params;

        // 更新流信息，准备发送下一次请求
        $this->configStreams[$streamId]['startTime'] = time();
        $this->configStreams[$streamId]['sendBuffer'] = $request;
        $this->info("[config] 准备复用连接，{$name} 下一次监听（ID: {$streamId}）");
    }

    /**
     * 重试失败的配置监听流（加强频率控制）
     */
    private function retryFailedConfigStreams()
    {
        $now = time();
        $maxRetriesPerLoop = 1; // 每次事件循环最多重试1个
        $retryCount = 0;
        $minRetryInterval = 5; // 最小重试间隔（秒）

        foreach ($this->configListen as $name => $config) {
            // 检查是否需要重试
            if (empty($config['enable']) ||
                !isset($config['retry']) ||
                $config['retry'] > $now ||
                isset($this->configStreamMap[$name])) {
                continue;
            }

            // 检查是否过于频繁
            $lastRetry = $config['lastRetry'] ?? 0;
            if ($now - $lastRetry < $minRetryInterval) {
                continue;
            }

            // 限制重试次数
            if ($retryCount >= $maxRetriesPerLoop) {
                break;
            }

            $this->configListen[$name]['lastRetry'] = $now;
            $this->startConfigListenStream($name);
            unset($this->configListen[$name]['retry']);
            $retryCount++;
        }
    }

    /**
     * 连接保活机制
     */
    private function keepAliveConnections()
    {
        $now = time();
        foreach ($this->configStreams as $streamId => $info) {
            $socket = $info['socket'];
            // 检查连接是否超过55秒未活动（接近Keep-Alive超时）
            if ($now - $info['startTime'] > 55 && !isset($info['sendBuffer'])) {
                // 发送空数据保持连接
                @fwrite($socket, "\r\n");
                $this->configStreams[$streamId]['startTime'] = $now;
                $this->info("[config] 发送保活数据包（ID: {$streamId}）");
            }
        }
    }

    /**
     * 解析HTTP响应体（处理分块编码）
     */
    private function parseHttpBody($responseData, $headerEndPos, $headers)
    {
        $body = substr($responseData, $headerEndPos + 4);
        $transferEncoding = '';

        // 查找传输编码方式
        foreach ($headers as $header) {
            if (stripos($header, 'Transfer-Encoding:') === 0) {
                $transferEncoding = trim(str_replace('Transfer-Encoding:', '', $header));
                break;
            }
        }

        // 处理分块编码
        if ($transferEncoding === 'chunked') {
            $decodedBody = '';
            $offset = 0;
            $bodyLength = strlen($body);

            while ($offset < $bodyLength) {
                $chunkSizeEnd = strpos($body, "\r\n", $offset);
                if ($chunkSizeEnd === false) break;

                $chunkSizeHex = substr($body, $offset, $chunkSizeEnd - $offset);
                $chunkSize = hexdec($chunkSizeHex);
                if ($chunkSize === 0) break;

                $offset = $chunkSizeEnd + 2;
                $decodedBody .= substr($body, $offset, $chunkSize);
                $offset += $chunkSize + 2;
            }

            return $decodedBody;
        }

        return $body;
    }

    /**
     * 初始化配置监听
     */
    private function initConfigListen()
    {
        foreach ($this->config['config'] ?? [] as $name => $config) {
            $this->configListen[$name] = $config;
            if (file_exists($config['file'])) {
                $this->configListen[$name]['content'] = @file_get_contents($config['file']);
                $this->info("[init] 已加载配置：{$name} -> {$config['file']} ");
            } else {
                $this->configListen[$name]['content'] = "";
                $this->info("[init] 已加载配置：{$name} -> {$config['file']}（文件不存在）");
            }

            if (!empty($config['enable'])) {
                $this->startConfigListenStream($name);
            }
        }
    }

    /**
     * 处理TCP请求和配置监听流（非阻塞方式）
     */
    private function handleTcpRequests()
    {
        $socketRead = [$this->serverSocket];
        $socketWrite = [];
        $socketExcept = [];
        $streamRead = [];
        $streamWrite = [];
        $streamExcept = [];

        // 添加客户端Socket
        foreach ($this->clients as $clientSocket) {
            $socketRead[] = $clientSocket;
        }

        // 添加配置监听Socket
        foreach ($this->configStreams as $info) {
            $streamRead[] = $info['socket'];
            // 有发送缓冲区的流需要检查可写事件
            if (!empty($info['sendBuffer'])) {
                $streamWrite[] = $info['socket'];
            }
        }

        // 收集可写Socket
        foreach ($this->clients as $clientSocket) {
            $clientId = (int)$clientSocket;
            if (!empty($this->writeBuffers[$clientId])) {
                $socketWrite[] = $clientSocket;
            }
        }

        # 处理连接被系统中断的问题。
        // 处理Socket事件（超时100ms）
        $socketActivity = false;
        $socketError = false;
        $socketErrno = 0;
        if (!empty($socketRead) || !empty($socketWrite) || !empty($socketExcept)) {
            $socketActivity = @\socket_select($socketRead, $socketWrite, $socketExcept, 0, 100000);
            if ($socketActivity === false) {
                $socketErrno = \socket_last_error();
                $socketError = true;
            }
        }

        // 处理流事件（超时100ms）
        $streamActivity = false;
        $streamError = false;
        $streamErrno = 0;
        if (!empty($streamRead) || !empty($streamWrite) || !empty($streamExcept)) {
            $streamActivity = @\stream_select($streamRead, $streamWrite, $streamExcept, 0, 100000);
            if ($streamActivity === false) {
                $streamErrno = \errno(); // stream_select的错误用errno()获取
                $streamError = true;
            }
        }

        // 判断是否为中断错误（EINTR）
        $isInterrupted = false;
        // 处理socket_select的错误
        if ($socketError) {
            if ($socketErrno == \SOCKET_EINTR) {
                $isInterrupted = true;
            } else {
                $this->info("[TCP] socket_select错误：" . \socket_strerror($socketErrno));
                return; // 非中断错误，退出
            }
        }
        // 处理stream_select的错误
        if ($streamError) {
            if ($streamErrno == 4) { // EINTR对应的错误码是4
                $isInterrupted = true;
            } else {
                $this->info("[TCP] stream_select错误：" . \error_get_last()['message']);
                return; // 非中断错误，退出
            }
        }

        // 如果是中断，重置流数组并返回（避免下一次循环使用被修改的数组）
        if ($isInterrupted) {
            $this->info("[TCP] 系统调用被中断（EINTR），继续运行");
            return;
        }

        // 处理异常
        foreach ($socketExcept as $socket) {
            $this->closeClient($socket);
        }

        foreach ($streamExcept as $socket) {
            $streamId = (int)$socket;
            if (isset($this->configStreams[$streamId])) {
                $info = $this->configStreams[$streamId];
                fclose($socket);
                unset($this->configStreams[$streamId], $this->configStreamMap[$info['name']]);
                $this->configListen[$info['name']]['retry'] = time() + 3;
            }
        }

        // 处理新连接
        if (in_array($this->serverSocket, $socketRead)) {
            $this->handleNewConnection();
        }

        // 处理客户端请求
        foreach ($socketRead as $socket) {
            if ($socket === $this->serverSocket) continue;
            $this->handleClientRequest($socket);
        }

        // 处理配置监听响应
        foreach ($streamRead as $socket) {
            $streamId = (int)$socket;
            if (isset($this->configStreams[$streamId])) {
                $this->processConfigResponse($socket, $this->configStreams[$streamId]['name']);
            }
        }

        // 处理配置监听流的发送缓冲区
        foreach ($streamWrite as $socket) {
            $streamId = (int)$socket;
            if (isset($this->configStreams[$streamId]['sendBuffer'])) {
                $buffer = $this->configStreams[$streamId]['sendBuffer'];
                $bytesWritten = @fwrite($socket, $buffer);

                if ($bytesWritten === false) {
                    // 发送失败
                    $info = $this->configStreams[$streamId];
                    $this->info("[config] 发送失败（{$info['name']}），准备重试");
                    fclose($socket);
                    unset($this->configStreams[$streamId], $this->configStreamMap[$info['name']]);
                    $this->configListen[$info['name']]['retry'] = time() + 3;
                } elseif ($bytesWritten === strlen($buffer)) {
                    // 发送完成
                    $this->configStreams[$streamId]['startTime'] = time();
                    unset($this->configStreams[$streamId]['sendBuffer']);
                    $this->info("[config] 请求发送完成（ID: {$streamId}）");
                } else {
                    // 部分发送，更新缓冲区
                    $this->configStreams[$streamId]['sendBuffer'] = substr($buffer, $bytesWritten);
                    $this->info("[config] 部分发送（ID: {$streamId}，已发送: {$bytesWritten}/" . strlen($buffer) . "）");
                }
            }
        }

        // 发送响应
        foreach ($socketWrite as $socket) {
            $this->sendClientResponse($socket);
        }
    }

    /**
     * 验证配置合法性
     * @return void
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

        $hasEnabledConfig = false;
        foreach ($this->config['config'] ?? [] as $name => $config) {
            if (!empty($config['enable'])) {
                $hasEnabledConfig = true;
                break;
            }
        }

        if (!$hasEnabledService && !$hasEnabledConfig) {
            throw new Exception("至少需要启用一个服务（service中enable=true）或者需要被监听的配置(config中enable=true)");
        }
    }

    /**
     * 初始化配置参数
     * @return void
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
     * @return void
     * @throws Exception
     */
    private function initNacosClient()
    {
        try {
            $this->nacosClient = new Client(
                $this->serverConfig['host'],
                $this->serverConfig['username'],
                $this->serverConfig['password']
            );
        } catch (\Exception $e) {
            $this->info("[error] 连接服务器失败：" . $e->getMessage());
            throw new Exception("服务启动失败");
        }

    }

    /**
     * 初始化启用的服务（包含元数据解析和独立统计初始化）
     * @return void
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
                'currentHealthy' => true, // 记录本地健康状态（用于判断是否需要恢复心跳）
                'lastWeightAdjust' => 0,
                'lastHealthAdjust' => 0
            ];

            // 初始化心跳开关（默认发送心跳）
            $this->sendHeartbeat[$serviceKey] = true;
            $this->info("[init] 已加载服务：{$serviceKey} -> {$serviceClass}（元数据解析完成）");
        }
    }

    /**
     * 解析服务元数据
     * @param object $serviceInstance
     * @param string $serviceKey
     * @return array
     * @throws \ReflectionException
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
     * @param string $serviceKey 服务名
     * @return string
     */
    private function generateSafeNacosName(string $serviceKey): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', $serviceKey);
        return "SERVICE@@{$safeKey}";
    }

    /**
     * 启动服务（核心入口）
     * @return void
     */
    public function run()
    {
        try {
            $this->registerToNacos();
            $this->publishConfig();
            $this->startTcpServer();
            register_shutdown_function([$this, 'shutdown']);
            $this->eventLoop();
        } catch (Throwable $throwable) {
            $this->info("[error] 服务启动失败或者异常退出：{$throwable->getMessage()}");
            $this->shutdown();
        }
    }

    /**
     * 发布配置到服务器
     * @return void
     */
    private function publishConfig()
    {
        foreach ($this->configListen as $name => $config) {
            if (empty($config['enable'])) {
                continue;
            }
            if (empty($config['publish'])) {
                continue;
            }
            if (!empty($config['content'])) {
                $this->nacosClient->publishConfig($config['dataId'], $config['group'], $config['content']);
                $this->info("[init] 已发布配置：{$name}（本地配置发布完毕）");
            }
        }
    }

    /**
     * 注册实例到Nacos
     * @return void
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
                true,  // 健康状态（初始为健康）
                true   // 临时实例（关键：通过心跳控制健康状态）
            );

            if (isset($result['error'])) {
                throw new Exception("Nacos注册失败（{$service['serviceKey']}）：{$result['error']}");
            }
            $this->info("[service] 已注册服务：{$service['serviceKey']} -> {$service['nacosServiceName']}（IP：{$this->instanceConfig['ip']}:{$this->instanceConfig['port']}）");
        }
    }

    /**
     * 打印日志
     * @param string $message
     * @return void
     */
    private function info(string $message)
    {
        $message = date('Y-m-d H:i:s') . " " . trim($message);
        if ($this->isDebug) {
            echo $message;
            echo "\n";
        }
        if (isset($this->log) && is_callable($this->log)) {
            try {
                call_user_func($this->log, $message);
            } catch (\Throwable $exception) {
                echo "日志处理异常：" . $exception->getMessage();
                echo "\n";
            }
        }
    }

    /**
     * 启动TCP服务
     * @return void
     * @throws Exception
     */
    private function startTcpServer()
    {
        $ip = $this->instanceConfig['ip'];
        $port = $this->instanceConfig['port'];

        $this->serverSocket = \socket_create(\AF_INET, \SOCK_STREAM, \SOL_TCP);
        if ($this->serverSocket === false) {
            throw new Exception("创建TCP套接字失败：" . \socket_strerror(\socket_last_error()));
        }

        \socket_set_nonblock($this->serverSocket);
        \socket_set_option($this->serverSocket, \SOL_SOCKET, \SO_REUSEADDR, 1);

        if (\socket_bind($this->serverSocket, '0.0.0.0', $port) === false) {
            throw new Exception("绑定端口失败（{$ip}:{$port}）：" . \socket_strerror(\socket_last_error($this->serverSocket)));
        }

        if (\socket_listen($this->serverSocket, 100) === false) {
            throw new Exception("监听端口失败（{$ip}:{$port}）：" . \socket_strerror(\socket_last_error($this->serverSocket)));
        }

        $this->info("[init] 已启动，监听：{$ip}:{$port}（JSON-RPC协议）");
    }

    /**
     * 事件循环（处理请求+心跳+健康检查）
     * @return mixed
     */
    private function eventLoop()
    {
        $lastHeartbeatTime = 0;
        $lastCheckTime = 0;
        $checkInterval = 5; // 健康检查间隔（秒）
        $lastKeepAliveTime = 0;
        $keepAliveInterval = 10; // 连接保活检查间隔（秒）

        while (true) {
            $now = time();

            // 定时发送心跳（受心跳开关控制）
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

            // 定期检查连接保活
            if ($now - $lastKeepAliveTime >= $keepAliveInterval) {
                $this->keepAliveConnections();
                $lastKeepAliveTime = $now;
            }

            // 处理配置监听流重试
            $this->retryFailedConfigStreams();

            // 处理TCP请求和配置监听流（统一IO多路复用）
            $this->handleTcpRequests();

            usleep(10000);
        }
    }

    /**
     * 发送Nacos心跳（仅发送启用了心跳的服务）
     * @return void
     */
    private function sendNacosHeartbeat()
    {
        foreach ($this->enabledServices as $serviceKey => $service) {
            // 跳过关闭心跳的服务（熔断中）
            if (!$this->sendHeartbeat[$serviceKey]) {
                $this->info("[heartbeat] 已停止（{$serviceKey}）->{$service['serviceClass']}");
                continue;
            }

            $result = $this->nacosClient->sendBeat(
                $service['nacosServiceName'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                $service['namespace'],
                $service['metadata'],
                true,  // 临时实例
                $this->requestStats[$serviceKey]['currentWeight'], // 使用当前权重
                $this->heartbeatInterval
            );

            if (isset($result['error'])) {
                $this->info("[heartbeat] 失败（{$serviceKey}）->{$service['serviceClass']}：{$result['error']}");
            } else {
                $this->info("[heartbeat] 成功（{$serviceKey}）->{$service['serviceClass']}");
            }
        }
    }

    /**
     * 处理新客户端连接
     * @return void
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
        $this->info("[tcp] 新客户端连接：{$clientAddr}（clientId：{$clientId}）");
    }

    /**
     * 处理客户端请求
     * @param resource $socket 客户端
     * @return void
     */
    private function handleClientRequest($socket)
    {
        $clientId = (int)$socket;
        $clientAddr = $this->clientAddresses[$clientId] ?? "未知";

        $data = @\socket_read($socket, 4096);
        if ($data === false) {
            $errorCode = \socket_last_error($socket);
            $errorMsg = in_array($errorCode, [\SOCKET_ECONNRESET, \SOCKET_ETIMEDOUT])
                ? "Client closed connection or timeout"
                : "Read error (code: {$errorCode})";
            $this->info("[tcp] 读取错误（{$clientAddr}）：{$errorMsg}");
            $this->closeClient($socket);
            return;
        }

        if (trim($data) === '') {
            $this->info("[tcp] 客户端断开（{$clientAddr}）");
            $this->closeClient($socket);
            return;
        }

        $this->info("[tcp] 收到请求（{$clientAddr}）：{$data}");
        $response = $this->processJsonRpcRequest(trim($data));
        $this->writeBuffers[$clientId][] = $response . "\n";
    }

    /**
     * 发送客户端响应
     * @param resource $socket 客户端连接
     * @return void
     */
    private function sendClientResponse($socket)
    {
        $clientId = (int)$socket;
        $clientAddr = $this->clientAddresses[$clientId] ?? "未知";

        while (!empty($this->writeBuffers[$clientId])) {
            $response = array_shift($this->writeBuffers[$clientId]);
            $bytesWritten = @\socket_write($socket, $response);

            if ($bytesWritten === false) {
                $this->info("[tcp] 发送失败（{$clientAddr}）：" . \mb_convert_encoding(\socket_strerror(\socket_last_error($socket)), 'UTF-8', 'GBK'));
                $this->closeClient($socket);
                break;
            }

            if ($bytesWritten < strlen($response)) {
                $remaining = substr($response, $bytesWritten);
                array_unshift($this->writeBuffers[$clientId], $remaining);
                break;
            }
            $this->info("[tcp] 发送响应（{$clientAddr}）：{$response}");
        }
    }

    /**
     * 处理JSON-RPC请求（包含性能统计）
     * @param string $jsonData
     * @return string
     */
    private function processJsonRpcRequest(string $jsonData): string
    {
        $request = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->info("[error] " . self::ERROR_PARSE["message"]);
            return $this->buildJsonRpcResponse(null, self::ERROR_PARSE);
        }

        if (!isset($request['jsonrpc']) || $request['jsonrpc'] !== self::JSON_RPC_VERSION
            || !isset($request['method']) || !isset($request['id'])) {
            $this->info("[error] " . self::ERROR_INVALID_REQUEST["message"]);
            return $this->buildJsonRpcResponse($request['id'] ?? null, self::ERROR_INVALID_REQUEST);
        }

        $requestId = $request['id'];
        $method = $request['method'];
        $params = $request['params'] ?? [];

        $methodParts = explode('.', $method, 2);
        if (count($methodParts) !== 2) {
            $this->info('[error] method格式错误（应为：服务标识.方法名）');
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32600,
                'message' => 'method格式错误（应为：服务标识.方法名）'
            ]);
        }
        list($serviceKey, $funcName) = $methodParts;

        if (!isset($this->enabledServices[$serviceKey])) {
            $this->info("[error] 服务不存在（标识：{$serviceKey}），可用服务：" . implode(',', array_keys($this->enabledServices)));
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
            $this->info("[error] 服务{$serviceKey}不存在方法：{$methodName}");
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32601,
                'message' => "服务{$serviceKey}不存在方法：{$methodName}"
            ]);
        }

        $paramRules = $service['metadata']['methods'][$methodName]['params'] ?? [];
        $paramValidation = $this->validateParams($params, $paramRules);
        if (!$paramValidation['valid']) {
            $this->info("[error] " . $paramValidation['message']);
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
            $this->info("[error] " . "方法调用异常：{$e->getMessage()}");
            return $this->buildJsonRpcResponse($requestId, [
                'code' => -32603,
                'message' => "方法调用异常：{$e->getMessage()}"
            ]);
        }
    }

    /**
     * 验证参数
     * @param array $params 参数
     * @param array $paramRules 顺序
     * @return array
     */
    private function validateParams(array $params, array $paramRules): array
    {
        $requiredCount = count(array_filter($paramRules, function ($rule) {
            return $rule['required'];
        }));
        if (count($params) < $requiredCount) {
            $this->info("[error] " . "参数数量不足（至少需要{$requiredCount}个必填参数）");
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
                $this->info("[error] " . "参数{$rule['name']}类型错误（期望{$expectedType}，实际{$actualType}）");
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
     * @param $id
     * @param array|null $error
     * @param $result
     * @return string
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
     * @param $socket
     * @return void
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
     * @return void
     */
    public function shutdown()
    {
        $this->info("[exit] 开始清理资源...");

        // 关闭配置监听流
        foreach ($this->configStreams as $info) {
            if (is_resource($info['socket'])) {
                fclose($info['socket']);
            }
        }

        // 注销服务实例
        foreach ($this->enabledServices as $service) {
            $this->nacosClient->removeInstance(
                $service['nacosServiceName'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                $service['namespace'],
                'true'
            );
            $this->info("[exit] 已注销服务：{$service['serviceKey']}");
        }

        // 关闭客户端连接
        foreach ($this->clients as $socket) {
            \socket_close($socket);
        }

        // 关闭TCP服务
        if ($this->serverSocket) {
            \socket_close($this->serverSocket);
        }

        $this->info("[exit] 资源清理完成");
    }

    /**
     * 记录请求统计（按服务分组）
     * @param string $serviceKey 服务名
     * @param bool $isTimeout 是否超时
     * @param bool $isError 是否发生了错误
     * @return void
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
     * @param string $serviceKey 服务名
     * @return void
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

        // 处理错误率（熔断：控制心跳）
        $this->handleErrorRate($serviceKey, $errorRate, $now);

        // 处理超时率（降级：调整权重）
        $this->handleTimeoutRate($serviceKey, $timeoutRate, $now);
    }

    /**
     * 处理错误率（熔断逻辑：通过控制心跳实现）
     * 临时实例不允许手动修改健康状态，通过停止/恢复心跳让Nacos自动标记健康状态
     * @param string $serviceKey
     * @param float $errorRate
     * @param int $now
     * @return void
     */
    private function handleErrorRate(string $serviceKey, float $errorRate, int $now)
    {
        $service = $this->enabledServices[$serviceKey];
        $stats = $this->requestStats[$serviceKey];
        $coolDownPassed = ($now - $stats['lastHealthAdjust']) > $this->adjustCoolDown;

        // 错误率≥50% 且 冷却时间已过 且 当前健康（需要熔断）
        if ($errorRate >= 0.5 && $coolDownPassed && $stats['currentHealthy']) {
            // 停止发送心跳（Nacos会在心跳超时后标记实例为不健康）
            $this->sendHeartbeat[$serviceKey] = false;

            $this->info("[{$serviceKey} service] 触发熔断（错误率{$errorRate}），已停止发送心跳");
            // 更新本地状态
            $this->requestStats[$serviceKey]['currentHealthy'] = false;
            $this->requestStats[$serviceKey]['lastHealthAdjust'] = $now;
            return;
        }

        // 错误率<50% 且 冷却时间已过 且 当前不健康（需要手动恢复）
        if ($errorRate < 0.5 && $coolDownPassed && !$stats['currentHealthy']) {
            // 恢复发送心跳（Nacos会在收到心跳后标记实例为健康）
            $this->sendHeartbeat[$serviceKey] = true;
            $this->info("[{$serviceKey} service] 恢复健康（错误率{$errorRate}），已恢复发送心跳");
            // 更新本地状态
            $this->requestStats[$serviceKey]['currentHealthy'] = true;
            $this->requestStats[$serviceKey]['lastHealthAdjust'] = $now;
        }
    }

    /**
     * 处理超时率（降级逻辑，支持逐步恢复）
     * @param string $serviceKey 服务名
     * @param float $timeoutRate 超时率
     * @param int $now 当前时间
     * @return void
     */
    private function handleTimeoutRate(string $serviceKey, float $timeoutRate, int $now)
    {
        $service = $this->enabledServices[$serviceKey];
        $stats = $this->requestStats[$serviceKey];
        $originalWeight = (float)$this->instanceConfig['weight'];
        $coolDownPassed = ($now - $stats['lastWeightAdjust']) >= $this->adjustCoolDown;
        $currentWeight = $stats['currentWeight'];
        $metadata = $service['metadata'];
        $ephemeral = true; // 统一使用临时实例

        // 降级逻辑：超时率≥50% 且 冷却时间已过 且 权重未到最低
        if ($timeoutRate >= 0.5 && $coolDownPassed) {
            $newWeight = max(0.1, $currentWeight * 0.5);
            if (abs($newWeight - $currentWeight) < 0.001) {
                return;
            }

            $result = $this->nacosClient->updateWeight(
                $service['nacosServiceName'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                $newWeight,
                $service['namespace'],
                $ephemeral,
                $metadata
            );
            if (isset($result['error'])) {
                $this->info("[{$serviceKey} service] 降级失败（超时率{$timeoutRate}）：{$result['error']}");
            } else {
                $this->info("[{$serviceKey} service] 触发降级（超时率{$timeoutRate}），权重从{$currentWeight}调整为{$newWeight}");
                $this->requestStats[$serviceKey]['currentWeight'] = $newWeight;
                $this->requestStats[$serviceKey]['lastWeightAdjust'] = $now;
            }
            return;
        }
        // 恢复逻辑：超时率下降时逐步恢复
        if ($coolDownPassed && $currentWeight < $originalWeight) {
            $recoveryFactor = 1 + (0.5 - $timeoutRate) * 2;
            $newWeight = min($originalWeight, $currentWeight * $recoveryFactor);
            if (abs($newWeight - $currentWeight) < 0.001) {
                return;
            }

            $result = $this->nacosClient->updateWeight(
                $service['nacosServiceName'],
                $this->instanceConfig['ip'],
                $this->instanceConfig['port'],
                $newWeight,
                $service['namespace'],
                $ephemeral,
                $metadata
            );
            if (isset($result['error'])) {
                $this->info("[{$serviceKey} service] 恢复权重失败（超时率{$timeoutRate}）：{$result['error']}");
            } else {
                $this->info("[{$serviceKey} service] 恢复权重（超时率{$timeoutRate}），权重从{$currentWeight}调整为{$newWeight}");
                $this->requestStats[$serviceKey]['currentWeight'] = $newWeight;
                $this->requestStats[$serviceKey]['lastWeightAdjust'] = $now;
            }
        }
    }
}
