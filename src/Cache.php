<?php

declare(strict_types=1);

namespace Tianyage\SimpleCache;

use Exception;
use Redis;
use RedisException;
use Throwable;

class Cache
{
    private static array $instances     = [];
    private static array $lastCheckTime = []; // 记录最后一次检查时间
    private static array $configs       = [];
    
    private const DEFAULT_STORE = 'default';
    private const PING_INTERVAL = 5;
    
    /**
     * 获取 Redis 单例
     *
     * @param int    $index 数据库编号
     * @param string $store 配置名称
     *
     * @return Redis
     */
    public static function getInstance(int $index, string $store = self::DEFAULT_STORE): Redis
    {
        if (!isset(self::$instances[$store][$index])) {
            return self::createInstance($index, $store);
        }
        
        // 每隔N秒检查一次连接状态
        $lastChecked = self::$lastCheckTime[$store][$index] ?? 0;
        if (time() - $lastChecked > self::PING_INTERVAL) {
            // 显式检查连接状态（捕获ping的异常）
            try {
                self::$instances[$store][$index]->ping();
            } catch (RedisException) {
                return self::reconnect($index, $store);
            } finally {
                self::$lastCheckTime[$store][$index] = time(); // 更新检查时间
            }
        }
        
        return self::$instances[$store][$index];
    }
    
    /**
     * 创建新的 Redis 实例
     *
     * @param int    $index
     * @param string $store
     *
     * @return Redis
     */
    private static function createInstance(int $index, string $store): Redis
    {
        $config = self::loadConfig($store);
        
        try {
            $redis = new Redis();
            $redis->connect(
                $config['hostname'] ?? '127.0.0.1',
                $config['port'] ?? 6379,
                5, // 超时时间（秒）
                null,
                300 // 重试间隔（毫秒）
            );
            
            if (!empty($config['password'])) {
                $redis->auth($config['password']);
            }
            $redis->select($index); // 选择对应的数据库
            self::$instances[$store][$index]     = $redis;
            self::$lastCheckTime[$store][$index] = time();
            
            return $redis;
        } catch (Exception $e) {
            throw new RedisException("连接 Redis 失败: " . $e->getMessage());
        }
    }
    
    /**
     * 重连 Redis 实例
     *
     * @param int    $index
     * @param string $store
     *
     * @return Redis
     */
    private static function reconnect(int $index, string $store): Redis
    {
        // 移除旧实例（会自动触发连接关闭 ->close() ）
        unset(self::$instances[$store][$index]);
        
        try {
            // 重新创建连接
            return self::createInstance($index, $store);
        } catch (Exception $e) {
            // 确保实例被清理
            if (isset(self::$instances[$store][$index])) {
                unset(self::$instances[$store][$index]);
            }
            throw new RedisException("Redis 重连失败: " . $e->getMessage());
        }
    }
    
    /**
     * 加载配置
     *
     * @param string $store
     *
     * @return array
     */
    private static function loadConfig(string $store = self::DEFAULT_STORE): array
    {
        if (empty(self::$configs[$store])) {
            $configPath = self::resolveConfigPath();
            
            try {
                self::$configs = require $configPath;
                
                // 选择指定配置
                if (empty(self::$configs[$store])) {
                    throw new RedisException("Redis配置'{$store}'不存在");
                }
            } catch (Throwable $e) {
                throw new RedisException("加载Redis配置失败({$configPath}): " . $e->getMessage());
            }
        }
        
        return self::$configs[$store];
    }
    
    /**
     * 解析配置路径
     *
     * @return string
     */
    private static function resolveConfigPath(): string
    {
        if (function_exists('root_path')) {
            $root_path = root_path();
            // 保证目录结尾带有斜杠
            $root_path = rtrim($root_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            return $root_path . 'config' . DIRECTORY_SEPARATOR . 'simple-cache.php';
        }
        
        $vendorDir   = realpath(dirname(__DIR__));
        $projectRoot = dirname($vendorDir, 3);
        $configPath  = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'simple-cache.php';
        
        if (file_exists($configPath)) {
            return $configPath;
        }
        throw new RedisException("Redis配置文件不存在: {$configPath}");
    }
    
    /**
     * 获取某项配置
     */
    public static function config(string $key = '', array|string $default = '', string $store = self::DEFAULT_STORE): array|string
    {
        $config = self::loadConfig($store);
        
        // 无参数时获取所有
        if ($key === '') {
            return $config;
        }
        
        // 获取单级配置
        if (!str_contains($key, '.')) {
            return $config[$key] ?? $default;
        }
        
        // 获取二级配置
        $parts = explode('.', $key);  // 按.拆分成多维数组进行判断
        foreach ($parts as $part) {
            if (!is_array($config) || !array_key_exists($part, $config)) {
                return $default;
            }
            $config = $config[$part];
        }
        
        return $config;
    }
    
    
    /**
     * 模糊查找redis key
     *
     * @param Redis  $redis
     * @param string $pattern 要匹配的规则  'test_*'
     * @param int    $count   每次遍历数量 count越大总耗时越短，但单次阻塞越长。 建议5000-10000。并发不高则可以调至接近1w。
     * @param int    $timeout
     *
     * @return array
     */
    public static function redisScan(Redis $redis, string $pattern, int $count = 5000, int $timeout = 30): array
    {
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
            
            // 迭代结束，未找到匹配
            if ($iterator === 0 || $iterator === '0') { //（兼容字符串 "0" 和整数 0）
                break;
            }
            
            // 异常处理
            if ($iterator === null) {
                throw new RedisException("Redis SCAN iterator is null");
            }
            //            if ($iterator === null) { //"游标为null了，重置为0，继续扫描"
            //                $iterator = "0";
            //            }
        }
        //        $keyArr = array_flip($keyArr);
        //        $keyArr = array_flip($keyArr);
        return $keyArr;
    }
    
    /**
     * 批量删除（pip管道模式）
     *
     * @param Redis  $redis
     * @param string $key 支持模糊搜索[*] exp: test1_*
     * @param int    $batchSize
     *
     * @return true
     */
    public static function delMutil(Redis $redis, string $key, int $batchSize = 1000): bool
    {
        $keys = self::redisScan($redis, $key); // 模糊扫描查找
        
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