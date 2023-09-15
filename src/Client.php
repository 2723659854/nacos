<?php

namespace Xiaosongshu\Nacos;

use GuzzleHttp\RequestOptions;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client as HttpClient;

class Client
{
    /** @var \GuzzleHttp\Client $client guzzle客户端 */
    protected HttpClient $client;
    /** @var string|mixed|null $token 鉴权token */
    protected string $token;
    /** @var string $host nacos服务器地址 */
    protected string $host = 'http://127.0.0.1:8848';

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
        /** @var Client $client 建议放到handle里面去 */
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
     * @param string $serviceName
     * @param string $ip
     * @param string $port
     * @param string $namespaceId
     * @param string $metadata
     * @param float $weight
     * @param bool $healthy
     * @param bool $ephemeral
     * @return array
     */
    public function createInstance(string $serviceName, string $ip, string $port, string $namespaceId, string $metadata, float $weight, bool $healthy, bool $ephemeral)
    {
        $data = [
            "serviceName" => $serviceName,
            "ip" => $ip,
            "port" => $port,
            "namespaceId" => $namespaceId,
            "weight" => $weight,
            "healthy" => $healthy,
            'ephemeral' => $ephemeral,
            'metadata' => $metadata
        ];
        return $this->request('post', '/nacos/v1/ns/instance', $data);
    }

    /**
     * 获取实例列表
     * @param string $serviceName
     * @param string|null $namespaceId
     * @return array
     */
    public function getInstanceList(string $serviceName, string $namespaceId = null)
    {
        $data = ['serviceName' => $serviceName,];
        if ($namespaceId) {
            $data['namespaceId'] = $namespaceId;
        }
        return $this->request('get', '/nacos/v1/ns/instance/list', $data);

    }

    /**
     * 实例详情
     * @param string $serviceName
     * @param string $healthyOnly
     * @param string $ip
     * @param string $port
     * @return array
     */
    public function getInstanceDetail(string $serviceName, string $healthyOnly, string $ip, string $port)
    {
        $data = ['serviceName' => $serviceName, 'healthyOnly' => $healthyOnly, 'ip' => $ip, 'port' => $port];
        return $this->request('get', '/nacos/v1/ns/instance', $data);
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
     * @param string $serviceName
     * @param string $ip
     * @param string $port
     * @param string $namespaceId
     * @param string $ephemeral
     * @param string $beat
     * @return array
     */
    public function sendBeat(string $serviceName, string $ip, string $port, string $namespaceId, string $ephemeral, string $beat)
    {
        $data = [
            'serviceName' => $serviceName,
            'ip' => $ip,
            'port' => $port,
            'ephemeral' => $ephemeral,
            'beat' => $beat,
            'namespaceId' => $namespaceId,
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
}