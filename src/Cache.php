<?php

namespace Tianyage\SimpleCache;

use Exception;
use Redis;
use RedisException;
use Throwable;

class Cache
{
    
    private static array $instances        = [];
    private static array $connectionStatus = []; // 记录最后一次检查时间
    
    /**
     * redis单例
     *
     * @param int $index 数据库编号
     *
     * @return Redis
     * @throws RedisException
     */
    public static function getInstance(int $index): Redis
    {
        try {
            // 检查当前实例是否存在
            if (!isset(self::$instances[$index])) {
                self::createRedisInstance($index);
                self::$connectionStatus[$index] = time(); // 更新检查时间
            } else {
                // 每隔N秒检查一次连接状态
                if (time() - self::$connectionStatus[$index] > 5) {
                    // 显式检查连接状态（捕获ping的异常）
                    try {
                        self::$instances[$index]->ping();
                    } catch (RedisException $e) {
                        self::reconnectRedis($index);
                    }
                    
                    self::$connectionStatus[$index] = time(); // 更新检查时间
                }
            }
        } catch (RedisException $e) {
            throw new RedisException("getInstance失败: " . $e->getMessage());
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
            $redis->connect(
                $config['hostname'],
                $config['port'],
                5,    // 超时时间（秒）
                null,
                300,  // 重试间隔（毫秒）
            );
            $redis->auth($config['password']);
            $redis->select($index);  // 选择对应的数据库
            self::$instances[$index] = $redis;
        } catch (Exception $e) {
            throw new RedisException("createRedisInstance失败: " . $e->getMessage());
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
            // 移除旧实例（会自动触发连接关闭 ->close() ）
            if (isset(self::$instances[$index])) {
                unset(self::$instances[$index]);
            }
            // 重新创建连接
            self::createRedisInstance($index);
        } catch (Exception $e) {
            // 确保实例被清理
            if (isset(self::$instances[$index])) {
                unset(self::$instances[$index]);
            }
            throw new RedisException("reconnectRedis失败: " . $e->getMessage());
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
                throw new RedisException("{$config_path}加载失败:{$e->getMessage()}");
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
     * @param int    $timeout
     *
     * @return array
     */
    public static function redisScan(string $pattern, int $db, int $count = 5000, int $timeout = 30): array
    {
        $redis     = self::getInstance($db);
        $keyArr    = [];
        $startTime = time();
        
        while (true) {
            // 检查是否超时
            if ((time() - $startTime) > $timeout) {
                throw new RedisException("redisScan超时{$timeout}秒");
            }
            
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
    public static function delMutil(string $key, int $db, int $batchSize = 1000): bool
    {
        $redis = self::getInstance($db);
        $keys  = self::redisScan($key, $db); // 模糊扫描查找
        
        if (count($keys) > 0) {
            $pipe = $redis->multi($redis::PIPELINE);  // 开启管道模式，代表将操作命令暂时放在管道里
            // 分批执行（限制每次删除的键数量，避免一次性删除过多数据导致性能异常）
            foreach (array_chunk($keys, $batchSize) as $chunk) {
                foreach ($chunk as $key) {
                    $pipe->del($key);
                }
                $pipe->exec(); // 开始执行管道里所有命令
            }
        }
        return true;
    }
}