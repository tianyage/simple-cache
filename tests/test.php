<?php

use Tianyage\SimpleCache\Cache;

require_once '../vendor/autoload.php';

$testdb = 9;

try {
    $redis = Cache::getInstance($testdb);
} catch (Exception $e) {
    echo $e->getMessage();
    die;
}

$redis->setex('testfff', 20, date("Y-m-d H:i:s"));
echo $redis->get('testfff') . PHP_EOL;

$arr = Cache::redisScan('test*', $testdb);
print_r($arr);

var_dump(Cache::delMutil('test*', $testdb));