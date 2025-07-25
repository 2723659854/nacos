<?php

namespace Xiaosongshu\Nacos\Samples;

/**
 * @purpose 用户登录服务
 * @author yanglong
 * @time 2025年7月25日17:32:27
 */
class LoginService
{
    /**
     * 用户登录接口
     * @param string $username 用户名（必填，长度≥3）
     * @param string $password 密码（必填，长度≥6）
     * @return array 登录结果，包含token
     */
    public function login(string $username, string $password): array
    {
        // 模拟业务逻辑：验证用户名密码
        if (strlen($username) < 3) {
            return ['success' => false, 'message' => '用户名长度不能少于3位'];
        }
        if (strlen($password) < 6) {
            return ['success' => false, 'message' => '密码长度不能少于6位'];
        }

        // 模拟生成token
        $token = md5($username . time() . 'secret');
        return [
            'success' => true,
            'message' => '登录成功',
            'token' => $token,
            'expire' => 3600 // token有效期（秒）
        ];
    }

    /**
     * 退出登录
     * @param string $token
     * @return array
     */
    public function logout(string $token): array{
        return [
            'success' => true,
            'message' => '退出登录成功',
            'token' => $token,
        ];
    }
}