<?php
namespace Xiaosongshu\Nacos\Samples;

/**
 * @purpose 示例服务提供者
 * @author yanglong
 * @time 2025年7月25日12:16:19
 */
class DemoService
{

    /**
     * 示例方法：添加用户信息
     * @param string $name 姓名
     * @param int $age 年龄
     * @return string
     */
    public function add(string $name, int $age): string
    {
        $needSleep=file_get_contents(__DIR__."/demo.txt");
        if (!empty($needSleep)){
            throw new \Exception("测试抛出异常，服务熔断");
        }
        return "用户添加成功！姓名：{$name}，年龄：{$age}（服务端处理时间：" . date('H:i:s') . "）";
    }

    /**
     * 示例方法：获取用户信息
     * @param string $name 姓名
     * @return array
     */
    public function get(string $name): array
    {
        return [
            'name' => $name,
            'age' => 25,
            'message' => "查询成功（服务端时间：" . date('H:i:s') . "）"
        ];
    }
}