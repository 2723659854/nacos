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
     * 提供一个加法
     * @param int $a 第一个参数
     * @param int $b 第二个参数
     * @return int 返回一个整型值
     */
    public function add(int $a,int $b){
        return $a + $b;
    }
}