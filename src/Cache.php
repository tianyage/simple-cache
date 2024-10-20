<?php

namespace Tianyage\SimpleCache;

use Exception;
use Redis;
use RedisException;
use Throwable;

class Cache
{
    
    private static array $instances = [];
    
    //    public static function getInstance(int $index): Redis
    //    {
    //        if (empty(self::$redis)) {
    //            $config = self::getConfig();
    //            try {
    //                self::$redis = new Redis();
    //                self::$redis->connect($config['hostname'], $config['port']);
    //                self::$redis->auth($config['password']); //设置密码
    //            } catch (Exception $e) {
    //                echo '链接redis失败:' . $e->getMessage();
    //                throw $e;
    //            }
    //        }
    //        // 切换db时做判断是否一致
    //        if ($index !== self::$db_index) {
    //            self::$redis->select($index);
    //            self::$db_index = $index;
    //        }
    //        return self::$redis;
    //    }
    
    /**
     * redis单例
     *
     * @param int $index 数据库编号
     *
     * @return Redis
     * @throws
     */
    public static function getInstance(int $index): Redis
    {
        // 检查当前实例是否存在
        if (!isset(self::$instances[$index])) {
            self::createRedisInstance($index);
        }
        
        // 检查Redis连接是否仍然有效
        if (!self::$instances[$index]->ping()) {
            self::reconnectRedis($index);
        }
        
        return self::$instances[$index];
    }
    
    /**
     * 创建新的 Redis 实例
     *
     * @param int $index
     *
     * @return void
     * @throws RedisException
     */
    private static function createRedisInstance(int $index): void
    {
        $config = self::getConfig();
        try {
            $redis = new Redis();
            $redis->connect($config['hostname'], $config['port']);
            $redis->auth($config['password']);
            $redis->select($index);  // 选择对应的数据库
            self::$instances[$index] = $redis;
        } catch (Exception $e) {
            echo '连接redis失败: ' . $e->getMessage();
            throw $e;
        }
    }
    
    /**
     * 重连 Redis 实例
     *
     * @param int $index
     *
     * @return void
     * @throws RedisException
     */
    private static function reconnectRedis(int $index): void
    {
        try {
            //            echo "检测到 Redis 连接断开，尝试重连...\n";
            self::$instances[$index]->close(); // 先关闭原来的连接
            self::createRedisInstance($index); // 重新创建实例
            //            echo "Redis 重连成功\n";
        } catch (Exception $e) {
            echo 'Redis 重连失败: ' . $e->getMessage();
            throw $e;
        }
    }
    
    /**
     * 获取配置
     *
     * @param string       $name
     * @param array|string $default
     *
     * @return array|string
     */
    private static function getConfig(string $name = '', array|string $default = ''): array|string
    {
        static $config = null;
        if (!$config) {
            $lib_path    = realpath(dirname(__DIR__)) . DIRECTORY_SEPARATOR; // D:\WorkSpace\Git\qq-utils\vendor\tianyage\simple-cache\
            $root_path   = dirname($lib_path, 3) . DIRECTORY_SEPARATOR; // D:\WorkSpace\Git\qq-utils\
            $config_path = "{$root_path}config" . DIRECTORY_SEPARATOR . "simple-cache.php";
            try {
                $config = require $config_path;
            } catch (Throwable $e) {
                echo "文件打开失败：{$config_path}";
                die;
            }
        }
        // 无参数时获取所有
        if (empty($name)) {
            return $config;
        }
        
        if (!str_contains($name, '.')) {
            return $config[$name] ?? [];
        }
        
        $name    = explode('.', $name);
        $name[0] = strtolower($name[0]);
        //        $config  = self::$config;
        
        // 按.拆分成多维数组进行判断
        foreach ($name as $val) {
            if (isset($config[$val])) {
                $config = $config[$val];
            } else {
                return $default;
            }
        }
        
        return $config;
    }
    
    /**
     * 模糊查找redis key
     *
     * @param string $pattern 要匹配的规则  'test_*'
     * @param int    $db      数据库号 默认0
     * @param int    $count   每次遍历数量 count越大总耗时越短，但单次阻塞越长。 建议5000-10000。并发不高则可以调至接近1w。
     *
     * @return array
     * @throws RedisException
     */
    public static function redisScan(string $pattern, int $db, int $count = 5000): array
    {
        $redis = self::getInstance($db);
        
        $keyArr = [];
        while (true) {
            // $iterator 下条数据的坐标
            $data   = $redis->scan($iterator, $pattern, $count);
            $keyArr = array_merge($keyArr, $data ?: []);
            
            if ($iterator === 0) {   //迭代结束，未找到匹配
                break;
            }
            if ($iterator === null) { //"游标为null了，重置为0，继续扫描"
                $iterator = "0";
            }
        }
        //        $keyArr = array_flip($keyArr);
        //        $keyArr = array_flip($keyArr);
        return $keyArr;
    }
    
    /**
     * 批量删除（pip管道模式）
     *
     * @param string $key 支持模糊搜索[*] exp: test1_*
     * @param int    $db  数据库编号
     *
     * @return true
     * @throws RedisException
     */
    public static function delMutil(string $key, int $db): bool
    {
        $redis = self::getInstance($db);
        
        $keys = self::redisScan($key, $db); // 模糊扫描查找
        if (count($keys) > 0) {
            $pipe = $redis->multi($redis::PIPELINE);  // 开启管道模式，代表将操作命令暂时放在管道里
            foreach ($keys as $key) {
                $pipe->del($key);
            }
            $pipe->exec(); // 开始执行管道里所有命令
        }
        return true;
    }
}