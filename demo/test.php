<?php
require_once '../src/ydredis/YdRedis.php';
require_once './vendor/autoload.php';

use \Yd\YdRedis;

$logger = new \Monolog\Logger('ydredis');
$logger->pushHandler(new \Monolog\Handler\StreamHandler('/tmp/ydredis.log', \Monolog\Logger::DEBUG));

$loggerSentinel = new \Monolog\Logger('ydredis_sentinel');
$loggerSentinel->pushHandler(new \Monolog\Handler\StreamHandler('/tmp/ydredis_sentinel.log', \Monolog\Logger::DEBUG));

$loggerCluster = new \Monolog\Logger('ydredis_cluster');
$loggerCluster->pushHandler(new \Monolog\Handler\StreamHandler('/tmp/ydredis_cluster.log', \Monolog\Logger::DEBUG));

//配置加载有两种方式：文件，变量
//从文件中加载全局配置
YdRedis::loadConf('./redis.conf');

//从变量中加载全局配置
//YdRedis::setCfgs(parse_ini_file('./redis.conf', true));

//可以不使用全局配置，而单独创建实例对像
////参数：
//    $insKey: 此实例的唯一标识符，指 redis.conf 中的 [default]/[senti]/[cluster], 写日志时会用到此项
//    $cfg：redis的配置
//$cfg = [
//    'db'       => 0,
//    'cmdlog'   => 1,
//    'timeout'  => 0,
//    'password' => 'redisadmin',
//    'address'  => '127.0.0.1:6379',
//];
//$ydredis = new YdRedis('default', $cfg);

//库中自带日志功能，如有需要，可以指定全局日志, 实例对象日志
//全局logger
YdRedis::setDefaultLogger($logger);
//实力对象日志
//$ydredis->setLogger($logger);

$testKey = 'redis_testkey';
$testKeyNo = 'redis_testkey_no';
$testValue = 'redis_test: '.date('Y-m-d H:i:s');

print("连接到master, 使用全局日志\n");
$redis = YdRedis::ins();

$result = $redis->set($testKey, $testValue);
print_r("set {$testKey} {$testValue} ".($result ? "成功" : "失败")."\n");
print_r("lastError: ".$redis->lastError()."\n\n");

$result = $redis->get($testKey);
print_r("get {$testKey} result: {$result}\n");
print_r("lastError: ".$redis->lastError()."\n\n");

//验证key a0不存在时的处理
$result = $redis->get($testKeyNo);
print_r("验证key不存在 get {$testKeyNo} result: {$result}");
print_r("lastError: ".$redis->lastError()."\n\n");

$result = $redis->keys('*');
print_r("验证 rename-command keys 后执行的结果 keys '*' result: ".var_export($result, 1)."\n");
print_r("lastError: ".$redis->lastError()."\n\n");

//重连
print_r("master 重连\n");
$redis->reconn();

$result = $redis->set($testKey, $testValue);
print_r("set {$testKey} {$testValue} ".($result ? "成功" : "失败")."\n");
print_r("lastError: ".$redis->lastError()."\n\n");

print_r("master 重连测试结束\n");
print_r("-----------------------------------------------------\n\n");

print("连接到sentinel, 使用实例对象日志\n");
//$redisSenti = YdRedis::ins('senti');
$cfgs = parse_ini_file('./redis.conf', true);
$redisSenti = new YdRedis('senti', $cfgs['senti']);
$redisSenti->setLogger($loggerSentinel);

$result = $redisSenti->set($testKey, $testValue);
print_r("set {$testKey} {$testValue} ".($result ? "成功" : "失败")."\n");
print_r("lastError: ".$redisSenti->lastError()."\n\n");

$result = $redisSenti->get($testKey);
print_r("get {$testKey} result: {$result}\n");
print_r("lastError: ".$redisSenti->lastError()."\n\n");

//验证key a0不存在时的处理
$result = $redisSenti->get($testKeyNo);
print_r("验证key不存在 get {$testKeyNo} result: {$result}");
print_r("lastError: ".$redisSenti->lastError()."\n\n");

$result = $redisSenti->keys('*');
print_r("验证 rename-command keys 后执行的结果 keys '*' result: ".var_export($result, 1)."\n");
print_r("lastError: ".$redis->lastError()."\n\n");

//重连
print_r("sentinel 重连\n");
$redisSenti->reconn();

$result = $redisSenti->set($testKey, $testValue, 600);
print_r("set {$testKey} {$testValue} ".($result ? "成功" : "失败")."\n");
print_r("lastError: ".$redisSenti->lastError()."\n\n");

print_r("sentinel 重连测试结束\n");
print_r("-----------------------------------------------------\n\n");

print("连接到cluster, 使用实例对象日志\n");
$redisCluster = YdRedis::ins('cluster');
$redisCluster->setLogger($loggerCluster);

$result = $redisCluster->set($testKey, $testValue);
print_r("set {$testKey} {$testValue} ".($result ? "成功" : "失败")."\n");
print_r("lastError: ".$redisCluster->lastError()."\n\n");

$result = $redisCluster->get($testKey);
print_r("get {$testKey} result: {$result}\n");
print_r("lastError: ".$redisCluster->lastError()."\n\n");

//验证key a0不存在时的处理
$result = $redisCluster->get($testKeyNo);
print_r("get {$testKey} result: {$result}\n");
print_r("lastError: ".$redisCluster->lastError()."\n\n");

$result = $redisCluster->keys('*');
print_r("验证 rename-command keys 后执行的结果 keys '*' result: ".var_export($result, 1)."\n");
print_r("lastError: ".$redis->lastError()."\n\n");

//重连
print_r("cluster 重连\n");
$redisCluster->reconn();

$result = $redisCluster->set($testKey, $testValue);
print_r("set {$testKey} {$testValue} ".($result ? "成功" : "失败")."\n");
print_r("lastError: ".$redisCluster->lastError()."\n");

print_r("cluster 重连测试结束\n\n");
print_r("-----------------------------------------------------\n\n");

$redis->del("hscan");
$redisSenti->del("zscan");
$redisCluster->del("sscan");

$rows = ['a', 'b', 'c', 'd', 'e', 'f'];
$score = 1;
foreach($rows as $k0) {
  foreach($rows as $k1) {
    foreach($rows as $k2) {
      $key = "{$k0}{$k1}{$k2}";

      //单节点
      $redis->set($key, $key);
      $redis->hset("hscan", $key, $key);
      $redis->zadd("zscan", $score, $key);
      $redis->sadd("sscan", $key);

      //主从
      $redisSenti->set($key, $key);
      $redisSenti->hset("hscan", $key, $key);
      $redisSenti->zadd("zscan", $score, $key);
      $redisSenti->sadd("sscan", $key);

      //集群
      $redisCluster->set($key, $key);
      $redisCluster->hset("hscan", $key, $key);
      $redisCluster->zadd("zscan", $score, $key);
      $redisCluster->sadd("sscan", $key);
    }
  }
}

print_r("----------------------scan测试开始-------------------------\n\n");
print_r("redis单节点\n\n");
$it=null;
while($keys = $redis->scan($it, 'a*', 10)) var_dump("{$it}".json_encode($keys));
print_r("redis主从\n\n");
$it=null;
while($keys = $redisSenti->scan($it, 'a*', 10)) var_dump("{$it}".json_encode($keys));
print_r("redis集群\n\n");
$it=null;
while($keys = $redisCluster->scan($it, 'a*', 10)) var_dump("{$it}".json_encode($keys));
print_r("----------------------scan测试结束-------------------------\n\n");

$pagesize = 10;
foreach(['hscan', 'zscan', 'sscan'] as $key) {
    print_r("----------------------{$key}测试开始-------------------------\n\n");
    print_r("redis单节点");
    $it=null;
    while($keys = $redis->$key($key, $it, 'a*', $pagesize)) var_dump("{$it}".json_encode($keys));
    print_r("\n\nredis主从");
    $it=null;
    while($keys = $redisSenti->$key($key, $it, 'a*', $pagesize)) var_dump("{$it}".json_encode($keys));
    print_r("\n\nredis集群");
    $it=null;
    while($keys = $redisCluster->$key($key, $it, 'a*', $pagesize)) var_dump("{$it}".json_encode($keys));
    print_r("----------------------{$key}测试结束-------------------------\n\n");
}

print_r("-----------------------------------------------------\n\n");
