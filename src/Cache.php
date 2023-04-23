<?php

namespace Tianyage\SimpleCache;

use Closure;
use Exception;
use Redis;
use RedisException;
use Throwable;

class Cache
{
    
    private static redis $redis;
    private static int   $db_index = 0;
    
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
        if (empty(self::$redis)) {
            $config = self::getConfig();
            try {
                self::$redis = new Redis();
                self::$redis->connect($config['hostname'], $config['port']);
                self::$redis->auth($config['password']); //设置密码
            } catch (Exception $e) {
                echo '链接redis失败:' . $e->getMessage();
                throw $e;
            }
        }
        // 切换db时做判断是否一致
        if ($index !== self::$db_index) {
            self::$redis->select($index);
            self::$db_index = $index;
        }
        return self::$redis;
    }
    
    /**
     * 模糊查找redis key
     *
     * @param string $pattern // 要匹配的规则  'test_*'
     * @param int    $count   // 每次遍历数量 count越大总耗时越短，但单次阻塞越长。 建议5000-10000。并发不高则可以调至接近1w。
     *
     * @return array
     * @throws
     */
    public static function redisScan(string $pattern, int $count = 5000): array
    {
        $keyArr = [];
        while (true) {
            // $iterator 下条数据的坐标
            $data   = self::$redis->scan($iterator, $pattern, $count);
            $keyArr = array_merge($keyArr, $data ?: []);
            
            if ($iterator === 0) {   //迭代结束，未找到匹配
                break;
            }
            if ($iterator === null) {//"游标为null了，重置为0，继续扫描"
                $iterator = "0";
            }
        }
        //        $keyArr = array_flip($keyArr);
        //        $keyArr = array_flip($keyArr);
        return $keyArr;
    }
    
    public static function delMutil(string $key, int $db = null)
    {
        if (!is_null($db)) {
            self::$redis->select($db);
        }
        $keys = self::redisScan($key); // 扫描查找
        if ($keys) {
            $pipe = self::$redis->multi(self::$redis::PIPELINE);  // 开启管道模式，代表将操作命令暂时放在管道里
            foreach ($keys as $key) {
                $pipe->del($key);
            }
            $pipe->exec(); // 开始执行管道里所有命令
        }
        return true;
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
        $ds     = DIRECTORY_SEPARATOR; // 目录分隔符 /或\
        $config = require_once dirname(__DIR__) . "{$ds}config{$ds}simple-cache.php";
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
}