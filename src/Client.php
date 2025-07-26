<?php
namespace Xiaosongshu\Nacos;

use GuzzleHttp\RequestOptions;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client as HttpClient;

/**
 * @purpose nacos客户端
 * @author yanglong
 * @time 2025年7月25日11:44:02
 */
class Client
{

    protected  $client;

    /** @var string|mixed|null $token 鉴权token */
    protected  $token;
    /** @var string $host nacos服务器地址 */
    protected  $host = 'http://127.0.0.1:8848';

    /** 分隔符 */
    public const WORD_SEPARATOR = "\x02";
    /** 结束 */
    public const LINE_SEPARATOR = "\x01";

    /**
     * @param string $host nacos主机
     * @param string $user 账号
     * @param string $pass 密码
     * @throws \Exception
     */
    public function __construct(string $host='http://127.0.0.1:8848',string $user='nacos',string $pass='nacos')
    {

        $client       = new HttpClient([
            'base_uri' => $host,
        ]);

        $response = $client->request('post', '/nacos/v1/auth/login', [
            RequestOptions::QUERY => [
                'username' => $user,
                'password' => $pass
            ]
        ]);

        $status   = $response->getStatusCode();
        if ($status == 200) {
            $data        = json_decode($response->getBody()->getContents(), true);
            $this->token = $data['accessToken'];
        } else {
            $this->token = null;
        }
        $this->client = $client;
        if ($this->token == null) {
            throw new \Exception("无法获取token，停止运行");
        }
    }

    /**
     * 公共请求方法
     * @param $method
     * @param $url
     * @param $params
     * @return array
     */
    protected function request($method, $url, $params)
    {
        if (strtolower($method) == 'post') {
            $data = [
                RequestOptions::FORM_PARAMS => $params,
                RequestOptions::QUERY => ['accessToken' => $this->token]
            ];
        }elseif (strtolower($method) == 'put'){
            $data = [
                RequestOptions::QUERY => array_merge($params, ['accessToken' => $this->token]),
                RequestOptions::HEADERS=>['Content-type'=>'application/json'],
                RequestOptions::JSON=>$params,
            ];
        } else {
            $data = [
                RequestOptions::QUERY => array_merge($params, ['accessToken' => $this->token]),
            ];
        }
        try {
            $response = $this->client->request($method, $url, $data);
            return $this->dealResponse($response);
        } catch (GuzzleException  $exception) {
            return ['error' => $exception->getMessage()];
        }

    }

    /**
     * 发布配置
     * @param string $dataId
     * @param string $group
     * @param string $content
     * @return array
     */
    public function publishConfig(string $dataId, string $group, string $content): array
    {
        $data = [
            'dataId' => $dataId,
            'group' => $group,
            'content' => $content,
        ];
        return $this->request('post', '/nacos/v1/cs/configs', $data);
    }

    /**
     * 监听配置变化
     * @param string $dataId
     * @param string $group
     * @param string $content
     * @return array
     * @throws GuzzleException
     */
    public function listenerConfig(string $dataId, string $group, string $content)
    {
        $ListeningConfigs = $dataId . self::WORD_SEPARATOR . $group . self::WORD_SEPARATOR . md5($content) . self::LINE_SEPARATOR;
        $response         = $this->client->request('post', '/nacos/v1/cs/configs/listener', [
            RequestOptions::HEADERS => [
                'Long-Pulling-Timeout' => 30
            ],
            RequestOptions::QUERY => [
                'Listening-Configs' => $ListeningConfigs,
                'accessToken' => $this->token
            ]
        ]);
        return $this->dealResponse($response);
    }

    /**
     * 读取配置
     * @param string $dataId
     * @param string $group
     * @param string $tenant
     * @return array
     */
    public function getConfig(string $dataId, string $group, string $tenant = 'public')
    {
        return $this->request('GET', '/nacos/v1/cs/configs', [
            'dataId' => $dataId,
            'group' => $group,
            'tenant' => $tenant
        ]);
    }

    /**
     * 删除配置
     * @param string $dataId
     * @param string $group
     * @return array
     */
    public function deleteConfig(string $dataId, string $group)
    {
        return $this->request('DELETE', '/nacos/v1/cs/configs', [
            'dataId' => $dataId,
            'group' => $group,
        ]);
    }

    /**
     * 处理响应
     * @param $response
     * @return array
     */
    protected function dealResponse($response): array
    {
        return ['status' => $response->getStatusCode(), 'content' => $response->getBody()->getContents()];
    }


    /**
     * 注册实例
     * @param string $serviceName 服务名称（必须含分组，如DEFAULT_GROUP@@father）
     * @param string $ip IP地址
     * @param string $port 端口
     * @param string $namespaceId 命名空间（默认public可传空）
     * @param array $metadata 元数据（数组形式，内部转为JSON）
     * @param float $weight 权重
     * @param bool $healthy 健康状态（临时实例此参数无效，由心跳决定）
     * @param bool $ephemeral 是否临时实例
     * @return array
     */
    public function createInstance(
        string $serviceName,
        string $ip,
        string $port,
        string $namespaceId,
        array $metadata, // 改为数组，内部转为JSON（避免外部手动编码出错）
        float $weight,
        bool $healthy,
        bool $ephemeral
    ) {
        $data = [
            "serviceName" => $serviceName,
            "ip" => $ip,
            "port" => $port,
            "namespaceId" => $namespaceId,
            "weight" => $weight,
            "healthy" => $healthy ? "true" : "false", // 转为字符串（Nacos要求）
            "ephemeral" => $ephemeral ? "true" : "false", // 字符串"true"/"false"
            "metadata" => json_encode($metadata) // 内部转为JSON字符串，确保格式正确
        ];
        return $this->request('post', '/nacos/v1/ns/instance', $data);
    }


    /**
     * 获取实例列表
     * @param string $serviceName 服务名（格式：groupName@@serviceName 或 仅serviceName）
     * @param string|null $namespaceId 命名空间ID
     * @param bool $healthyOnly 是否只返回健康实例
     * @param string|null $clusters 集群名称（多个集群用逗号分隔）
     * @return array
     */
    public function getInstanceList(
        string $serviceName,
        string $namespaceId = null,
        bool $healthyOnly = false,
        string $clusters = null
    ) {
        // 解析服务名中的分组信息
        $groupName = 'DEFAULT_GROUP';
        if (strpos($serviceName, '@@') !== false) {
            list($groupName, $serviceName) = explode('@@', $serviceName, 2);
        }

        $data = [
            'serviceName' => $serviceName,
            'groupName' => $groupName,
            'healthyOnly' => $healthyOnly ? 'true' : 'false',
        ];

        if ($namespaceId) {
            $data['namespaceId'] = $namespaceId;
        }

        if ($clusters) {
            $data['clusters'] = $clusters;
        }

        // 使用GET请求并确保参数作为URL参数传递
        return $this->request('get', '/nacos/v1/ns/instance/list', $data, true);
    }

    /**
     * 实例详情
     * @param string $serviceName 服务名称
     * @param bool $healthyOnly 只返回健康实例
     * @param string $ip
     * @param string $port
     * @return array
     */
    public function getInstanceDetail(string $serviceName, bool $healthyOnly, string $ip, string $port)
    {
        $data = ['serviceName' => $serviceName, 'healthyOnly' => $healthyOnly?"true":"false", 'ip' => $ip, 'port' => $port];
        return $this->request('get', '/nacos/v1/ns/instance', $data);
    }


    /**
     * 更新实例健康状态（Nacos v1 版本）
     * @param string $serviceName
     * @param string $namespaceId
     * @param string $ip
     * @param string $port
     * @param bool $healthy
     * @return array
     */
    public function updateInstanceHealthy(
        string $serviceName,
        string $namespaceId,
        string $ip,
        string $port,
        bool $healthy = true
    ) {
        $data = [
            'serviceName' => $serviceName,
            'namespaceId' => $namespaceId,
            'ip' => $ip,
            'port' => $port,
            'healthy' => $healthy ? 'true' : 'false'
        ];
        return $this->request('put', '/nacos/v1/ns/health/instance', $data);
    }

    /**
     * 创建服务
     * @param string $serviceName
     * @param string $namespaceId
     * @param string $metadata
     * @return array
     */
    public function createService(string $serviceName, string $namespaceId, string $metadata = '')
    {
        $data = [
            'serviceName' => $serviceName,
            'namespaceId' => $namespaceId,
            'protectThreshold' => 0,
            'metadata' => $metadata,
        ];
        return $this->request('post', '/nacos/v1/ns/service', $data);
    }

    /**
     * 服务详情
     * @param string $serviceName
     * @param string $namespace
     * @return array
     */
    public function getServiceDetail(string $serviceName, string $namespace)
    {
        $data = ['serviceName' => $serviceName, 'namespaceId' => $namespace];
        return $this->request('get', '/nacos/v1/ns/service', $data);
    }

    /**
     * 查看当前集群Server列表
     * @return array
     */
    public function getOperatorService()
    {
        return $this->request('get', '/nacos/v1/ns/operator/servers', []);
    }

    /**
     * 获取服务列表
     * @param string $namespaceId
     * @param int $page
     * @param int $size
     * @return array
     */
    public function getServiceList(string $namespaceId = null, int $page = 1, int $size = 10)
    {
        $data = ['pageSize' => $size, 'pageNo' => $page];
        if ($namespaceId) {
            $data['namespaceId'] = $namespaceId;
        }
        return $this->request('get', '/nacos/v1/ns/service/list', $data);
    }


    /**
     * 发送心跳
     * @param string $serviceName 服务名称（必须与注册时完全一致，含分组）
     * @param string $ip IP地址
     * @param string $port 端口
     * @param string $namespaceId 命名空间
     * @param array $metaData 元数据（与注册时的数组完全一致）
     * @param bool $ephemeral 是否临时实例（与注册时一致）
     * @param float $weight 权重（必须与注册时一致）
     * @param int $heartbeatInterval 心跳间隔（秒）
     * @return array
     */
    public function sendBeat(
        string $serviceName,
        string $ip,
        string $port,
        string $namespaceId,
        array $metaData,
        bool $ephemeral, // 改为bool类型（与创建实例的参数类型一致）
        float $weight,
        int $heartbeatInterval = 5
    ) {
        // 构建beat参数（Nacos必须的字段，缺一不可）
        $beatData = [
            "serviceName" => $serviceName,
            "ip" => $ip,
            "port" => (int)$port, // 转为整数（与Nacos内部存储类型一致）
            "cluster" => "DEFAULT", // 集群名称（默认DEFAULT，必须指定）
            "weight" => $weight, // 权重必须与注册时完全一致
            "metadata" => $metaData, // 元数据数组（与注册时的数组一致）
            "scheduled" => false, // 固定值false（Nacos内部标识）
            "period" => $heartbeatInterval*1000 // 心跳间隔（与实例的instanceHeartBeatInterval一致）
        ];

        $data = [
            "serviceName" => $serviceName,
            "ip" => $ip,
            "port" => $port,
            "namespaceId" => $namespaceId,
            "ephemeral" => $ephemeral ? "true" : "false", // 字符串类型
            "beat" => json_encode($beatData, JSON_UNESCAPED_UNICODE) // 确保JSON格式正确
        ];
        return $this->request('put', '/nacos/v1/ns/instance/beat', $data);
    }

    /**
     * 移除服务
     * @param string $serviceName
     * @param string $namespaceId
     * @return array
     */
    public function removeService(string $serviceName, string $namespaceId)
    {
        $data = ['namespaceId' => $namespaceId, 'serviceName' => $serviceName];
        return $this->request('DELETE', '/nacos/v1/ns/service', $data);
    }


    /**
     * 删除实例
     * @param string $serviceName
     * @param string $ip
     * @param string $port
     * @param string $namespaceId
     * @return array
     */
    public function removeInstance(string $serviceName, string $ip, string $port, string $namespaceId, string $ephemeral)
    {
        $data = [
            'serviceName' => $serviceName,
            'ip' => $ip,
            'port' => $port,
            'namespaceId' => $namespaceId,
            'ephemeral' => $ephemeral
        ];
        return $this->request('DELETE', '/nacos/v1/ns/instance', $data);
    }


    /**
     * 更新实例权重（Nacos 1.0 版本兼容，支持保留元数据）
     * @param string $serviceName
     * @param string $namespaceId
     * @param string $ip
     * @param string $port
     * @param float $weight
     * @param bool $ephemeral
     * @param array|null $metadata 实例元数据（新增参数）
     * @return array
     */
    public function updateWeight(
        string $serviceName,
        string $namespaceId,
        string $ip,
        string $port,
        float $weight,
        bool $ephemeral,
        ?array $metadata = null
    ) {
        $data = [
            'serviceName' => $serviceName,
            'namespaceId' => $namespaceId,
            'ip' => $ip,
            'port' => $port,
            'weight' => $weight,
            'ephemeral' => $ephemeral ? 'true' : 'false'
        ];

        // 如果提供了元数据，则添加到请求中
        if ($metadata) {
            $data['metadata'] = json_encode($metadata, JSON_UNESCAPED_UNICODE);
        }

        return $this->request('put', '/nacos/v1/ns/instance', $data);
    }
}
