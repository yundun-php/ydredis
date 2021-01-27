YdRedis
---- redis库的封装，主要是加了日志，增加了 redis/sentinel/cluster 配置的支持

说明
--------
本库是基于redis扩展，进行了业务使用上的封装
1. 统一 单节点/sentinel/cluster 连接的配置
2. 增加日志，如不配置则写到php的默认日志；也可以写到指定的日志文件。
3. 每个redis可以指定自己的日志(setLogger)，也可以指定全局的日志(setDefaultLogger)

Copyright & License
-------------------
YdRedis, redis扩展的二次封装, 版权所有 2018- 菁武.
代码遵守 MIT 协议, 见 LICENSE 文件。

Versions & Requirements
-----------------------
0.4.0, PHP >=5.4.0

Usage
-----
Add ``yd/ydredis`` as a dependency in your project's ``composer.json`` file (change version to suit your version of Elasticsearch):
```json
    {
        "require": {
            "yd/ydredis": "1.0.0"
        }
    }
```

配置 redis.conf
```
;[demo]
;; [可选] redis连接的db, 最好设置，每个db一个连接, cluster不需要此项
;db       = 0
;; [可选], 值on/off，默认off，执行命令写入日志
;cmdlog   = off
;; [必选] redis连接超时设置
;timeout  = 0
;; [可选] redis密码，不配置内不验证
;password = pi2paUAEDrTwfD9MzDnkTGDIm-QB0FLH

;; 单节点 redis 配置方式
;; [address] redis节点连接地址, 格式：<host>:<port>, 不支持多地址
;address  = 127.0.0.1:6379

;; sentinel主从部署方式
;; [sentinel_address] sentinel节点连接地址, 格式：<host>:<port>, 多以逗号(,)分隔
;sentinel_address = 127.0.0.1:26379, 127.0.0.1:26380
;; [sentinel_mastername] sentinel主节点的名字
;sentinel_mastername = mymaster

;; cluster集熟部署
;; [cluster_address] cluster连接地址, 格式：<host>:<port>, 多以逗号(,)分隔
;cluster_address = 127.0.0.1:6379, 127.0.0.1:6380

;; rename-command-#原始命令# 对一些有风险的命令做重命名
;;rename-command-keys   = b4248aafd0da14d021e9555a0f63b204


[default]
db       = 0
cmdlog   = on
timeout  = 0
password = redisadmin
address  = 127.0.0.1:6379
rename-command-keys   = b4248aafd0da14d021e9555a0f63b204

[senti]
db       = 0
cmdlog   = off
timeout  = 0
password = redisadmin
sentinel_address = 127.0.0.1:26380, 127.0.0.1:26381, 127.0.0.1:26382
sentinel_mastername = mymaster

[cluster]
db       = 0
timeout  = 0
cmdlog   = off
password = redisadmin
cluster_address = 127.0.0.1:6390, 127.0.0.1:6391, 127.0.0.1:6392,  127.0.0.1:6393, 127.0.0.1:6394, 127.0.0.1:6395
```

示例
```
<?php
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

//库中自带日志功能，如有需要，可以指定全局日志, 实例对象的日志
//全局日志
YdRedis::setDefaultLogger($logger);
//实力对象日志
//$ydredis->setLogger($logger);

print("连接到master, 使用全局logger\n");
$redis = YdRedis::ins();
$result = $redis->set('a', 'jwtest'.date('Y-m-d H:i:s'));
var_dump($result);
var_dump($redis->get('a'));
var_dump("lastError: ".$redis->lastError());
print("\n\n");
var_dump($redis->get('a'));
//重连
$redis->reconn();

print("连接到sentinel, 使用实例对象日志\n");
//$redisSenti = YdRedis::ins('senti');
$cfgs = parse_ini_file('./redis.conf', true);
$redisSenti = new YdRedis('senti', $cfgs['senti']);
$redisSenti->setLogger($loggerSentinel);
$result = $redisSenti->set('a', 'jwtest'.date('Y-m-d H:i:s'));
var_dump($result);
var_dump($redisSenti->get('a'));
var_dump("lastError: ".$redisSenti->lastError());
print("\n\n");
//重连
$redisSenti->reconn();

print("连接到cluster, 使用实例对象日志\n");
$redisCluster = YdRedis::ins('cluster');
$redisCluster->setLogger($loggerCluster);
$result = $redisCluster->set('a', 'jwtest'.date('Y-m-d H:i:s'));
var_dump($result);
var_dump($redisCluster->get('a'));
var_dump("lastError: ".$redisCluster->lastError());
print("\n\n");
//重连
$redisCluster->reconn();
?>
```

日志示例
```
[2020-07-07 17:15:32] ydredis.INFO: default set ["a","jwtest2020-07-07 17:15:32"] [] []
[2020-07-07 17:15:32] ydredis.INFO: default get ["a"] [] []
[2020-07-07 17:15:32] ydredis.ERROR: senti sentinel[127.0.0.1:26380, 127.0.0.1:26381, 127.0.0.1:26382] get-master-addr-by-name mastername[mymasters]未找到可用的节点！ [] []
[2020-07-07 17:18:51] ydredis.INFO: default set ["a","jwtest2020-07-07 17:18:51"] [] []
[2020-07-07 17:18:51] ydredis.INFO: default get ["a"] [] []
[2020-07-07 17:18:51] ydredis.INFO: senti set ["a","jwtest2020-07-07 17:18:51"] [] []
[2020-07-07 17:18:51] ydredis.INFO: senti get ["a"] [] []
[2020-07-07 17:18:51] ydredis.INFO: cluster set ["a","jwtest2020-07-07 17:18:51"] [] []
[2020-07-07 17:18:51] ydredis.INFO: cluster get ["a"] [] []
[2020-07-07 17:19:17] ydredis.INFO: default set ["a","jwtest2020-07-07 17:19:17"] [] []
[2020-07-07 17:19:17] ydredis.INFO: default get ["a"] [] []
[2020-07-07 17:19:17] ydredis.INFO: senti set ["a","jwtest2020-07-07 17:19:17"] [] []
[2020-07-07 17:19:17] ydredis.INFO: senti get ["a"] [] []
[2020-07-07 17:19:17] ydredis.ERROR: cluster connect cluster[127.0.0.1:6390, 127.0.0.1:6391, 127.0.0.1:6392,  127.0.0.1:6393, 127.0.0.1:6394, 127.0.0.1:6395] 失败！Couldn't map cluster keyspace using any provided seed [] []
[2020-07-07 17:19:44] ydredis.INFO: default set ["a","jwtest2020-07-07 17:19:44"] [] []
[2020-07-07 17:19:44] ydredis.INFO: default get ["a"] [] []
[2020-07-07 17:19:44] ydredis.INFO: senti set ["a","jwtest2020-07-07 17:19:44"] [] []
[2020-07-07 17:19:44] ydredis.INFO: senti get ["a"] [] []
[2020-07-07 17:19:44] ydredis.INFO: cluster set ["a","jwtest2020-07-07 17:19:44"] [] []
[2020-07-07 17:19:44] ydredis.INFO: cluster get ["a"] [] []
```

测试Redis环境
```
##测试环境已经使用docker构建好，启动环境，执行下面的命令
cd demo
make start

##停止环境
make stop
```

更新日志
--------
```
20201125 1.0.0 增加对 rename-command 的支持; 修正cmdlog, 全局logger无效问题
```

