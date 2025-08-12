<?php

namespace Xiaosongshu\Nacos;

/**
 * @purpose nacos客户端
 * @author yanglong
 * @time 2025年7月30日12:42:34
 */
class Client
{
    /** @var string $token 鉴权token */
    protected $token;
    /** @var string $host nacos服务器地址 */
    protected $host = 'http://127.0.0.1:8848';
    /** 用户名 */
    protected $user = "nacos";
    /** 密码 */
    protected $pass = "nacos";
    /** @var int $tokenExpireTime token过期时间戳 */
    protected $tokenExpireTime;

    /** 分隔符 */
    public const WORD_SEPARATOR = "\x02";
    /** 结束 */
    public const LINE_SEPARATOR = "\x01";

    /**
     * 初始化
     * @param string $host nacos主机
     * @param string $user 账号
     * @param string $pass 密码
     * @throws \Exception
     */
    public function __construct(string $host = 'http://127.0.0.1:8848', string $user = 'nacos', string $pass = 'nacos')
    {
        $this->host = rtrim($host, '/');
        $this->user = $user;
        $this->pass = $pass;
        $this->login();
    }

    /**
     * 登录方法（获取并更新token）
     * @return void
     * @throws \Exception
     */
    public function login()
    {
        // 登录接口要求表单格式
        $response = $this->post('/nacos/v1/auth/login', [], [
            'username' => $this->user,
            'password' => $this->pass
        ], ['Content-Type' => 'application/x-www-form-urlencoded']);
        $loginResult = $this->dealResponse($response);
        if (empty($loginResult) || $loginResult['code'] != 200) {
            $errorMsg = $loginResult['message'] ?? '登录失败：未知错误';
            throw new \Exception($errorMsg);
        }
        if (empty($loginResult['content']['accessToken'])){
            $errorMsg = $loginResult['content'] ?? '登录失败：未知错误';
            throw new \Exception($errorMsg);
        }
        if (empty($loginResult['content']['tokenTtl'])){
            $errorMsg = $loginResult['content'] ?? '登录失败：未知错误';
            throw new \Exception($errorMsg);
        }
        $this->token = $loginResult['content']['accessToken'];
        $this->tokenExpireTime = time() + $loginResult['content']['tokenTtl'] - 60; // 提前60秒过期
    }

    /**
     * 检查token是否过期，过期则自动刷新
     * @throws \Exception
     */
    protected function checkToken()
    {
        if (time() >= $this->tokenExpireTime) {
            $this->login();
        }
    }

    /**
     * 获取当前客户端token
     * @return string
     * @throws \Exception
     */
    public function getToken()
    {
        $this->checkToken();
        return $this->token;
    }

    /**
     * 公共请求方法（自动处理token过期）
     * @param string $method 请求方法
     * @param string $url 请求地址
     * @param array $params 请求参数
     * @param array $headers 额外请求头（可覆盖默认）
     * @return array
     * @throws \Exception
     */
    protected function request(string $method, string $url, array $params, array $headers = [])
    {
        $this->checkToken();

        $method = strtolower($method);
        $defaultHeaders = ['Content-Type' => 'application/x-www-form-urlencoded']; // 默认表单格式（适配大部分接口）
        $requestHeaders = array_merge($defaultHeaders, $headers);

        switch ($method) {
            case 'post':
                $response = $this->post($url, ['accessToken' => $this->token], $params, $requestHeaders);
                break;
            case 'put':
                $response = $this->put($url, ['accessToken' => $this->token], $params, $requestHeaders);
                break;
            case 'delete':
                // DELETE请求参数优先通过URL传递
                $response = $this->delete($url, array_merge($params, ['accessToken' => $this->token]), [], $requestHeaders);
                break;
            default: // get
                $response = $this->get($url, array_merge($params, ['accessToken' => $this->token]), $requestHeaders);
        }

        $result = $this->dealResponse($response);
        if (isset($result['code']) && $result['code'] != 200) {
            throw new \Exception($result['message'] ?? "请求失败：{$method} {$url}");
        }
        return $result;
    }

    /**
     * 发布配置（表单格式）
     * @param string $dataId 唯一标识符
     * @param string $group 所属分组（默认DEFAULT_GROUP）
     * @param string $content 配置内容
     * @param string $tenant 命名空间ID（默认空）
     * @return array
     * @throws \Exception
     */
    public function publishConfig(string $dataId, string $group = 'DEFAULT_GROUP', string $content = "", string $tenant = ''): array
    {
        $data = [
            'dataId' => $dataId,
            'group' => $group,
            'content' => $content,
            'tenant' => $tenant
        ];
        // 配置接口要求表单格式
        return $this->request('post', '/nacos/v1/cs/configs', $data);
    }

    /**
     * 监听配置变化（长轮询）
     * @param string $dataId 唯一标识符
     * @param string $group 所属分组（默认DEFAULT_GROUP）
     * @param string $content 本地配置内容（用于计算MD5）
     * @param string $tenant 命名空间ID
     * @return array
     * @throws \Exception
     */
    public function listenerConfig(string $dataId, string $group = 'DEFAULT_GROUP', string $content = "", string $tenant = '')
    {
        $listeningConfig = implode(self::WORD_SEPARATOR, [
                $dataId,
                $group,
                md5($content)
            ]) . self::LINE_SEPARATOR;

        $headers = [
            'Long-Pulling-Timeout' => '30000',
            'Content-Type' => 'application/x-www-form-urlencoded'
        ];

        return $this->request('post', '/nacos/v1/cs/configs/listener', [
            'Listening-Configs' => $listeningConfig,
            'tenant' => $tenant
        ], $headers);
    }

    /**
     * 读取配置（GET请求，参数拼在URL）
     * @param string $dataId 唯一标识符
     * @param string $group 所属分组（默认DEFAULT_GROUP）
     * @param string $tenant 命名空间ID（默认空）
     * @return array
     * @throws \Exception
     */
    public function getConfig(string $dataId, string $group = 'DEFAULT_GROUP', string $tenant = '')
    {
        return $this->request('GET', '/nacos/v1/cs/configs', [
            'dataId' => $dataId,
            'group' => $group,
            'tenant' => $tenant
        ]);
    }

    /**
     * 删除配置（DELETE请求，参数拼在URL）
     * @param string $dataId 唯一标识符
     * @param string $group 所属分组（默认DEFAULT_GROUP）
     * @param string $tenant 命名空间ID
     * @return array
     * @throws \Exception
     */
    public function deleteConfig(string $dataId, string $group = 'DEFAULT_GROUP', string $tenant = '')
    {
        return $this->request('DELETE', '/nacos/v1/cs/configs', [
            'dataId' => $dataId,
            'group' => $group,
            'tenant' => $tenant
        ]);
    }

    /**
     * 注册实例（表单格式）
     * @param string $serviceName 服务名称（格式：group@@service）
     * @param string $ip IP地址
     * @param string $port 端口
     * @param string $namespaceId 命名空间ID（默认空）
     * @param array $metadata 元数据
     * @param float $weight 权重（默认1.0）
     * @param bool $healthy 健康状态（仅永久实例有效）
     * @param bool $ephemeral 是否临时实例（默认true）
     * @return array
     * @throws \Exception
     */
    public function createInstance(
        string $serviceName,
        string $ip,
        string $port,
        string $namespaceId = '',
        array  $metadata = [],
        float  $weight = 1.0,
        bool   $healthy = true,
        bool   $ephemeral = true
    )
    {
        $groupName = 'DEFAULT_GROUP';
        if (strpos($serviceName, '@@') !== false) {
            list($groupName, $serviceName) = explode('@@', $serviceName, 2);
        }
        if (empty($serviceName)) {
            throw new \Exception("serviceName不能为空，请检查格式（如：group@@service）");
        }

        $data = [
            "serviceName" => $serviceName,
            "groupName" => $groupName,
            "ip" => $ip,
            "port" => $port,
            "namespaceId" => $namespaceId,
            "weight" => $weight,
            "healthy" => $healthy ? "true" : "false",
            "ephemeral" => $ephemeral ? "true" : "false",
            "metadata" => json_encode($metadata, JSON_UNESCAPED_UNICODE)
        ];

        return $this->request('post', '/nacos/v1/ns/instance', $data);
    }

    /**
     * 获取实例列表（GET请求）
     * @param string $serviceName 服务名（格式：group@@service）
     * @param string $namespaceId 命名空间ID（默认空）
     * @param bool $healthyOnly 是否只返回健康实例
     * @param string $clusters 集群名称（多个用逗号分隔）
     * @return array
     * @throws \Exception
     */
    public function getInstanceList(
        string $serviceName,
        string $namespaceId = '',
        bool   $healthyOnly = false,
        string $clusters = ''
    )
    {
        $groupName = 'DEFAULT_GROUP';
        if (strpos($serviceName, '@@') !== false) {
            list($groupName, $serviceName) = explode('@@', $serviceName, 2);
        }

        $data = [
            'serviceName' => $serviceName,
            'groupName' => $groupName,
            'healthyOnly' => $healthyOnly ? 'true' : 'false',
            'namespaceId' => $namespaceId,
            'clusters' => $clusters
        ];

        return $this->request('get', '/nacos/v1/ns/instance/list', $data);
    }

    /**
     * 实例详情（GET请求）
     * @param string $serviceName 服务名称
     * @param string $ip 实例ip
     * @param string $port 实例端口
     * @param string $namespaceId 命名空间ID（默认空）
     * @param bool $healthyOnly 是否只返回健康实例
     * @return array
     * @throws \Exception
     */
    public function getInstanceDetail(
        string $serviceName,
        string $ip,
        string $port,
        string $namespaceId = '',
        bool   $healthyOnly = false
    )
    {
        $data = [
            'serviceName' => $serviceName,
            'ip' => $ip,
            'port' => $port,
            'namespaceId' => $namespaceId,
            'healthyOnly' => $healthyOnly ? "true" : "false"
        ];
        return $this->request('get', '/nacos/v1/ns/instance', $data);
    }

    /**
     * 更新实例健康状态（PUT表单格式）
     * @param string $serviceName 服务名称
     * @param string $ip 实例ip
     * @param string $port 实例端口
     * @param string $namespaceId 命名空间ID（默认空）
     * @param bool $healthy 是否健康
     * @return array
     * @throws \Exception
     */
    public function updateInstanceHealthy(
        string $serviceName,
        string $ip,
        string $port,
        string $namespaceId = '',
        bool   $healthy = true
    )
    {
        $data = [
            'serviceName' => $serviceName,
            'ip' => $ip,
            'port' => $port,
            'namespaceId' => $namespaceId,
            'healthy' => $healthy ? 'true' : 'false'
        ];
        return $this->request('put', '/nacos/v1/ns/health/instance', $data);
    }

    /**
     * 创建服务（表单格式）
     * @param string $serviceName 服务名称
     * @param string $namespaceId 命名空间ID（默认空）
     * @param array $metadata 元数据（默认空）
     * @param float $protectThreshold 保护阈值（默认0）
     * @return array
     * @throws \Exception
     */
    public function createService(
        string $serviceName,
        string $namespaceId = '',
        array  $metadata = [],
        float  $protectThreshold = 0.0
    )
    {
        $data = [
            'serviceName' => $serviceName,
            'namespaceId' => $namespaceId,
            'protectThreshold' => $protectThreshold,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE)
        ];
        return $this->request('post', '/nacos/v1/ns/service', $data);
    }

    /**
     * 服务详情（GET请求）
     * @param string $serviceName 服务名称
     * @param string $namespaceId 命名空间ID（默认空）
     * @return array
     * @throws \Exception
     */
    public function getServiceDetail(string $serviceName, string $namespaceId = '')
    {
        $data = [
            'serviceName' => $serviceName,
            'namespaceId' => $namespaceId
        ];
        return $this->request('get', '/nacos/v1/ns/service', $data);
    }

    /**
     * 查看当前集群Server列表（GET请求）
     * @return array
     * @throws \Exception
     */
    public function getOperatorService()
    {
        return $this->request('get', '/nacos/v1/ns/operator/servers', []);
    }

    /**
     * 获取服务列表（GET请求）
     * @param string $namespaceId 命名空间ID（默认空）
     * @param int $page 当前页（默认1）
     * @param int $size 每页条数（默认10）
     * @return array
     * @throws \Exception
     */
    public function getServiceList(string $namespaceId = '', int $page = 1, int $size = 10)
    {
        $data = [
            'pageSize' => $size,
            'pageNo' => $page,
            'namespaceId' => $namespaceId
        ];
        return $this->request('get', '/nacos/v1/ns/service/list', $data);
    }

    /**
     * 发送心跳（PUT表单格式）
     * @param string $serviceName 服务名称（含分组）
     * @param string $ip IP地址
     * @param string $port 端口
     * @param string $namespaceId 命名空间ID（默认空）
     * @param array $metaData 元数据
     * @param bool $ephemeral 是否临时实例（默认true）
     * @param float $weight 权重
     * @param int $heartbeatInterval 心跳间隔（秒，默认5）
     * @return array
     * @throws \Exception
     */
    public function sendBeat(
        string $serviceName,
        string $ip,
        string $port,
        string $namespaceId = '',
        array  $metaData = [],
        bool   $ephemeral = true,
        float  $weight = 1.0,
        int    $heartbeatInterval = 5
    )
    {
        $beatData = [
            "serviceName" => $serviceName,
            "ip" => $ip,
            "port" => (int)$port,
            "cluster" => "DEFAULT",
            "weight" => $weight,
            "metadata" => $metaData,
            "scheduled" => false,
            "period" => $heartbeatInterval * 1000
        ];

        $data = [
            "serviceName" => $serviceName,
            "ip" => $ip,
            "port" => $port,
            "namespaceId" => $namespaceId,
            "ephemeral" => $ephemeral ? "true" : "false",
            "beat" => json_encode($beatData, JSON_UNESCAPED_UNICODE)
        ];
        return $this->request('put', '/nacos/v1/ns/instance/beat', $data);
    }

    /**
     * 移除服务（DELETE请求，参数拼URL）
     * @param string $serviceName 服务名称
     * @param string $namespaceId 命名空间ID（默认空）
     * @return array
     * @throws \Exception
     */
    public function removeService(string $serviceName, string $namespaceId = '')
    {
        $data = [
            'namespaceId' => $namespaceId,
            'serviceName' => $serviceName
        ];
        return $this->request('DELETE', '/nacos/v1/ns/service', $data);
    }

    /**
     * 删除实例（DELETE请求，参数拼URL）
     * @param string $serviceName 服务名称
     * @param string $ip 实例ip
     * @param string $port 实例端口
     * @param string $namespaceId 命名空间ID（默认空）
     * @param bool $ephemeral 是否临时实例（默认true）
     * @return array
     * @throws \Exception
     */
    public function removeInstance(
        string $serviceName,
        string $ip,
        string $port,
        string $namespaceId = '',
        bool   $ephemeral = true
    )
    {
        $data = [
            'serviceName' => $serviceName,
            'ip' => $ip,
            'port' => $port,
            'namespaceId' => $namespaceId,
            'ephemeral' => $ephemeral ? 'true' : 'false'
        ];
        return $this->request('DELETE', '/nacos/v1/ns/instance', $data);
    }

    /**
     * 更新实例权重（PUT表单格式）
     * @param string $serviceName 服务名
     * @param string $ip 实例ip
     * @param string $port 实例端口
     * @param float $weight 权重
     * @param string $namespaceId 命名空间ID（默认空）
     * @param bool $ephemeral 是否临时实例（默认true）
     * @param array|null $metadata 元数据（可选）
     * @return array
     * @throws \Exception
     */
    public function updateWeight(
        string $serviceName,
        string $ip,
        string $port,
        float  $weight,
        string $namespaceId = '',
        bool   $ephemeral = true,
        ?array $metadata = null
    )
    {
        $data = [
            'serviceName' => $serviceName,
            'ip' => $ip,
            'port' => $port,
            'weight' => $weight,
            'namespaceId' => $namespaceId,
            'ephemeral' => $ephemeral ? 'true' : 'false'
        ];
        if ($metadata !== null) {
            $data['metadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        }
        return $this->request('put', '/nacos/v1/ns/instance', $data);
    }

    /**
     * 处理服务端响应
     * @param array $response 接口返回的数组（含code、msg、data）
     * @return array
     */
    public function dealResponse(array $response)
    {
        if ($response['code'] != 200) {
            return ['code' => $response['code'], 'message' => $response['msg']];
        }

        $data = $response['data'];
        if (empty($data)) {
            return ['code' => 200, 'content' => []];
        }

        // 解析JSON响应
        $result = json_decode($data, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return ['code' => 200, 'content' => $result];
        }

        // 非JSON响应（如配置内容文本）
        return ['code' => 200, 'content' => $data];
    }

    /**
     * POST请求
     * @param string $url 请求地址
     * @param array $getFields URL参数
     * @param array $postFields 请求体参数
     * @param array $headers 请求头
     * @return array
     */
    public function post(string $url, array $getFields = [], array $postFields = [], array $headers = [])
    {
        return $this->httpRequest('POST', $url, $getFields, $postFields, $headers);
    }

    /**
     * GET请求
     * @param string $url 请求地址
     * @param array $getFields URL参数
     * @param array $headers 请求头
     * @return array
     */
    public function get(string $url, array $getFields = [], array $headers = [])
    {
        return $this->httpRequest('GET', $url, $getFields, [], $headers);
    }

    /**
     * PUT请求
     * @param string $url 请求地址
     * @param array $getFields URL参数
     * @param array $putFields 请求体参数
     * @param array $headers 请求头
     * @return array
     */
    public function put(string $url, array $getFields = [], array $putFields = [], array $headers = [])
    {
        return $this->httpRequest('PUT', $url, $getFields, $putFields, $headers);
    }

    /**
     * DELETE请求
     * @param string $url 请求地址
     * @param array $getFields URL参数
     * @param array $deleteFields 请求体参数
     * @param array $headers 请求头
     * @return array
     */
    public function delete(string $url, array $getFields = [], array $deleteFields = [], array $headers = [])
    {
        return $this->httpRequest('DELETE', $url, $getFields, $deleteFields, $headers);
    }

    /**
     * 通用HTTP请求
     * @param string $method 请求方法
     * @param string $url 请求地址
     * @param array $getFields URL参数
     * @param array $bodyFields 请求体参数
     * @param array $headers 请求头
     * @return array
     */
    private function httpRequest(string $method, string $url, array $getFields = [], array $bodyFields = [], array $headers = [])
    {
        // 处理URL参数
        if (!empty($getFields)) {
            $queryParams = http_build_query($getFields);
            $url .= (stripos($url, '?') !== false) ? "&{$queryParams}" : "?{$queryParams}";
        }
        $fullUrl = $this->host . $url;

        // 处理请求头
        $curlHeaders = [];
        foreach ($headers as $key => $value) {
            $curlHeaders[] = "{$key}: {$value}";
        }

        // 初始化CURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_PROXY, '');
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

        // 处理请求体（根据Content-Type编码）
        $method = strtoupper($method);
        $contentType = $headers['Content-Type'] ?? '';
        $body = '';
        if (!empty($bodyFields)) {
            if (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
                $body = http_build_query($bodyFields); // 表单编码
            } elseif (strpos($contentType, 'application/json') !== false) {
                $body = json_encode($bodyFields, JSON_UNESCAPED_UNICODE); // JSON编码
            }
        }

        // 设置请求方法
        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                break;
            case 'PUT':
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if ($body !== '') {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                }
                break;
        }

        // 执行请求
        $res = curl_exec($ch);
        $errorNo = curl_errno($ch);
        $errorMsg = curl_error($ch);
        curl_close($ch);
        // 处理响应
        if ($errorNo !== 0) {
            return ['code' => 400, 'msg' => "CURL错误[{$errorNo}]：{$errorMsg}", 'data' => ''];
        }
        return ['code' => 200, 'msg' => 'ok', 'data' => $res];
    }
}