<?php

use Tianyage\SimpleCache\Cache;

require_once '../vendor/autoload.php';

try {
    $cache = Cache::getInstance(8);
} catch (Exception $e) {
    echo $e->getMessage();
    die;
}

$cache->setex('test', 20, date("Y-m-d H:i:s"));
echo $cache->get('test') . PHP_EOL;

$arr = Cache::redisScan('test*');
print_r($arr);

var_dump(Cache::delMutil('test*'));