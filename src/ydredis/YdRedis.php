<?php
namespace Yd;

class YdRedisException extends \Exception
{
}

class YdRedis {

    private $_cfg = null;
    private $_errors = [];
    private $_lastError = '';
    private $_logger = null;
    private $_insKey = null;
    private $_instance = null;
    public static $cfgs;
    public static $logger = null;
    public static $instances = [];

    public static function jEncode($params = []) {
        return json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function setCfgs($cfgs = []) {
        foreach($cfgs as &$cfg) $cfg = self::parseCfg($cfg);
        self::$cfgs = $cfgs;
    }

    public static function setDefaultLogger($logger) {
        self::$logger = $logger;
    }

    public static function loadConf($confFile) {
        $cfgs = parse_ini_file($confFile, true);
        foreach($cfgs as &$cfg) $cfg = self::parseCfg($cfg);
        self::$cfgs =$cfgs;
    }

    public function __construct($insKey, $cfg) {
        $this->_insKey = $insKey;
        $this->_cfg = self::parseCfg($cfg);
        $this->connectAuto();
    }

    public function setCfg($cfg) {
        $this->_cfg = self::parseCfg($cfg);
    }

    public function setLogger($logger) {
        $this->_logger = $logger;
    }

    public function _logger() {
        if($this->_logger != null) {
            return $this->_logger;
        } else if(self::$logger != null) {
            return self::$logger;
        } else {
            return null;
        }
    }

    public static function parseCfg($cfg = []) {
        if(isset($cfg['address'])) {
            $tmp = explode(':', $cfg['address']);
            $cfg['host'] = $tmp[0];
            $cfg['port'] = isset($tmp[1]) ? $tmp[1] : 6379;
        }
        if(isset($cfg['sentinel_address'])) {
            $cfg['sentis'] = [];
            $sentis = explode(',', $cfg['sentinel_address']);
            foreach($sentis as $row) {
                $row = trim($row);
                $tmp = explode(':', $row);
                if(count($tmp) != 2) {
                    //日志
                    continue;
                }
                $cfg['sentis'][] = [
                    'host' => $tmp[0],
                    'port' => $tmp[1],
                ];
            }
        }
        if(isset($cfg['cluster_address'])) {
            $cfg['clusters'] = [];
            $clusters = explode(',', $cfg['cluster_address']);
            foreach($clusters as $row) {
                $cfg['clusters'][] = trim($row);
            }
        }
        if(isset($cfg['db'])) $cfg['db'] = intval($cfg['db']);
        $cfg['timeout'] = isset($cfg['timeout']) ? intval($cfg['timeout']) : 0;
        $cfg['read_timeout'] = isset($cfg['read_timeout']) ? intval($cfg['read_timeout']) : 0;
        if(isset($cfg['cmdlog'])) {
            if($cfg['cmdlog'] != 1) {
                $cfg['cmdlog'] = 0;
            }
        } else {
            $cfg['cmdlog'] =  0;
        }
        $renameCommand = [];
        foreach($cfg as $key => $newCommand) {
            if(substr($key, 0, 15) == 'rename-command-') {
                $rawCommand = trim(substr($key, 15));
                if(!$rawCommand || !ctype_alnum($rawCommand)) continue;
                if(!$newCommand || !ctype_alnum($newCommand)) continue;
                $renameCommand[strtoupper($rawCommand)] = $newCommand;
            }
        }
        $cfg['rename-command'] = $renameCommand;
        return $cfg;
    }

    public static function ins($key = 'default') {
        if(!isset(self::$cfgs[$key])) {
            throw new YdRedisException(" {$key} 配置不存在！");
        }
        if(!isset(self::$instances[$key])) {
            $cfg = self::$cfgs[$key];
            //连接Redis
            self::$instances[$key] = new self($key, $cfg);
        }
        return self::$instances[$key];
    }

    //连接重连，均调用此方法
    public function connectAuto() {
        $logger = $this->_logger();
        if(isset($this->_cfg['sentis'])) {
            $redis = new \Redis();
            $isConnect = false;
            foreach($this->_cfg['sentis'] as $senti) {
                if($isConnect) break;
                try {
                    $result = $redis->connect($senti['host'], $senti['port']);
                } catch (\Exception $e) {       //异常，跳过
                    $msg = " sentinel[{$senti['host']}:{$senti['port']}] 连接失败 ".$e->getMessage();
                    $this->_error($msg);
                    $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$this->_insKey} {$msg}");
                    continue;
                }
                if($result) $isConnect = true;
            }
            if(!$isConnect) {
                $msg = "{$this->_insKey} sentinel[{$this->_cfg['sentinel_address']}] 连接失败！";
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
                throw new YdRedisException($msg);
            }
            try {
                $master = $redis->rawcommand('SENTINEL', 'get-master-addr-by-name', $this->_cfg['sentinel_mastername']);
            } catch (\Exception $e) {
                $msg = "{$this->_insKey} sentinel[{$this->_cfg['sentinel_address']}] get-master-addr-by-name 失败 ".$e->getMessage();
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
                throw new YdRedisException($msg);
            }
            if(!$master) {
                $msg = "{$this->_insKey} sentinel[{$this->_cfg['sentinel_address']}] get-master-addr-by-name mastername[{$this->_cfg['sentinel_mastername']}]未找到可用的节点！";
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
                throw new YdRedisException($msg);
            }
            try {
                //host: string. can be a host, or the path to a unix domain socket. Starting from version 5.0.0 it is possible to specify schema port: int, optional
                //timeout: float, value in seconds (optional, default is 0 meaning unlimited)
                //reserved: should be NULL if retry_interval is specified
                //retry_interval: int, value in milliseconds (optional)
                //read_timeout: float, value in seconds (optional, default is 0 meaning unlimited)
                $result = $redis->connect($master[0], $master[1], $this->_cfg['timeout'], null, 100, $this->_cfg['read_timeout']);
            } catch (\Exception $e) {
                $msg = "{$this->_insKey} connect {$master[0]}:{$master[1]} 失败 ".$e->getMessage();
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
                throw new YdRedisException($msg);
            }
            $this->_instance = $redis;
        }
        if(isset($this->_cfg['address'])) {
            $redis = new \Redis();
            try {
                //host: string. can be a host, or the path to a unix domain socket. Starting from version 5.0.0 it is possible to specify schema port: int, optional
                //timeout: float, value in seconds (optional, default is 0 meaning unlimited)
                //reserved: should be NULL if retry_interval is specified
                //retry_interval: int, value in milliseconds (optional)
                //read_timeout: float, value in seconds (optional, default is 0 meaning unlimited)
                $redis->connect($this->_cfg['host'], $this->_cfg['port'], $this->_cfg['timeout'], null, 100, $this->_cfg['read_timeout']);
            } catch(\RedisException $e) {
                $msg = "{$this->_insKey} connect {$this->_cfg['host']}:{$this->_cfg['port']} 失败！".$e->getMessage();
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
                throw new YdRedisException($msg);
            }
            $this->_instance = $redis;
        }
        if($this->_instance) {
            try {
                if(!empty($this->_cfg['password'])) {
                    $result = $this->_instance->auth($this->_cfg['password']);
                    if($result === false) {
                        $msg = "{$this->_insKey} fail cmd: auth params: {$this->_cfg['password']} result: ".self::jEncode($result)." Error: ".$this->_instance->getLastError();
                        $this->_error($msg);
                        $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
                        throw new YdRedisException($msg);
                    }
                }
            } catch(\Exception $e) {
                $msg = "{$this->_insKey} auth[{$this->_cfg['password']}] 失败！".$e->getMessage();
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
                throw new YdRedisException($msg);
            }
            try {
                if(isset($this->_cfg['db'])) {
                    $result = $this->_instance->select($this->_cfg['db']);
                    if($result === false) {
                        $msg = "{$this->_insKey} fail cmd: select params: {$this->_cfg['db']} result: ".self::jEncode($result)." Error: ".$this->_instance->getLastError();
                        $this->_error($msg);
                        $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
                        throw new YdRedisException($msg);
                    }
                }
            } catch(\Exception $e) {
                $msg = "{$this->_insKey} select[{$this->_cfg['db']}] 失败！".$e->getMessage();
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
                throw new YdRedisException($msg);
            }
        }
        if(isset($this->_cfg['cluster_address'])) {
            try {
                $redis = new \RedisCluster(NUll,$this->_cfg['clusters'], $this->_cfg['timeout'], $this->_cfg['read_timeout'], true, $this->_cfg['password']);
            } catch(\Exception $e) {
                $msg = "{$this->_insKey} connect cluster[{$this->_cfg['cluster_address']}] 失败！".$e->getMessage();
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
                throw new YdRedisException($msg);
            }
            $this->_instance = $redis;
        }
        return true;
    }

    public function reconn() {
        $this->_instance = null;
        $this->connectAuto();
    }

    public function __call($name, $params) {
        $logger = $this->_logger ? $this->_logger : self::$logger;
        if($this->_instance == null) {
            $msg = "{$this->_insKey} 未连接到redis！";
            $this->_error($msg);
            $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            throw new YdRedisException($msg);
        }
        $nameUpper = strtoupper($name);
        if(isset($this->_cfg['rename-command'][$nameUpper])) {
            array_unshift($params, $this->_cfg['rename-command'][$nameUpper]);
            $name = 'rawCommand';
        }
        if(method_exists($this->_instance, $name)) {
            try {
                $result = call_user_func_array([$this->_instance, $name], $params);
                $resultMsg = $result === false ? 'fail' : 'ok';
                $lastError = $this->_instance->getLastError();
                $msg = "{$this->_insKey} {$resultMsg} cmd: {$name} params: ".self::jEncode($params)." result: ".self::jEncode($result).($lastError == null ? "" : " Error: {$lastError}");
                if($this->_cfg['cmdlog'] && $lastError !== null) {
                    $this->_error($msg);
                    $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
                } else if($this->_cfg['cmdlog'] && $lastError === null) {
                    if($logger != null) $logger->info("{$msg}");
                } else if(!$this->_cfg['cmdlog'] && $lastError !== null) {
                    $this->_error($msg);
                    $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
                } else {
                }
                return $result;
            } catch(\Exception $e) {
                $msg = "{$this->_insKey} cmd: {$name} params: ".self::jEncode($params)." 执行失败 ".$e->getMessage();
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
                throw new YdRedisException($msg);
            }
        } else {
            $msg = "{$this->_insKey} 没有找到方法 {$name}！";
            $this->_error($msg);
            $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            throw new YdRedisException($msg);
        }
    }

    protected function _error($msg) {
        $this->_lastError = $msg;
        if(count($this->_errors) >= 200) {
            array_shift($this->_errors);
        }
        array_push($this->_errors, $msg);
    }

    public function lastError() {
        return $this->_lastError;
    }

    //scan sscan hscan zscan 有参数需要传引用，所以另外单写
    public function scan(&$cursor, $pattern = null, $count = null, $type = null) {
        $name = "scan";
        $params = [$cursor, $pattern, $count, $type];
        $logger = $this->_logger ? $this->_logger : self::$logger;
        if($this->_instance == null) {
            $msg = "{$this->_insKey} 未连接到redis！";
            $this->_error($msg);
            $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            throw new YdRedisException($msg);
        }
        try {
            if($type) {
                $result = $this->_instance->scan($cursor, $pattern, $count, $type);
            } else {
                $result = $this->_instance->scan($cursor, $pattern, $count);
            }
            $lastError = $this->_instance->getLastError();
            $resultMsg = $result === false ? 'fail' : 'ok';
            $msg = "{$this->_insKey} {$resultMsg} cmd: {$name} params: ".self::jEncode($params)." result: ".self::jEncode($result).($lastError == null ? "" : " Error: {$lastError}");
            if($this->_cfg['cmdlog'] && $lastError !== null) {
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            } else if($this->_cfg['cmdlog'] && $lastError === null) {
                if($logger != null) $logger->info("{$msg}");
            } else if(!$this->_cfg['cmdlog'] && $lastError !== null) {
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            } else {
            }
            return $result;
        } catch(\Exception $e) {
            $msg = "{$this->_insKey} cmd: {$name} params: ".self::jEncode($params)." 执行失败 ".$e->getMessage();
            $this->_error($msg);
            $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            throw new YdRedisException($msg);
        }
    }
    public function sscan($key, &$cursor, $pattern = null, $count = null) {
        $name = "sscan";
        $params = [$key, $cursor, $pattern, $count];
        $logger = $this->_logger ? $this->_logger : self::$logger;
        if($this->_instance == null) {
            $msg = "{$this->_insKey} 未连接到redis！";
            $this->_error($msg);
            $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            throw new YdRedisException($msg);
        }
        try {
            $result =  $this->_instance->sscan($key, $cursor, $pattern, $count);
            $lastError = $this->_instance->getLastError();
            $resultMsg = $result === false ? 'fail' : 'ok';
            $msg = "{$this->_insKey} {$resultMsg} cmd: {$name} params: ".self::jEncode($params)." result: ".self::jEncode($result).($lastError == null ? "" : " Error: {$lastError}");
            if($this->_cfg['cmdlog'] && $lastError !== null) {
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            } else if($this->_cfg['cmdlog'] && $lastError === null) {
                if($logger != null) $logger->info("{$msg}");
            } else if(!$this->_cfg['cmdlog'] && $lastError !== null) {
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            } else {
            }
            return $result;
        } catch(\Exception $e) {
            $msg = "{$this->_insKey} cmd: {$name} params: ".self::jEncode($params)." 执行失败 ".$e->getMessage();
            $this->_error($msg);
            $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            throw new YdRedisException($msg);
        }
    }
    public function hscan($key, &$cursor, $pattern = null, $count = null) {
        $name = "hscan";
        $params = [$key, $cursor, $pattern, $count];
        $logger = $this->_logger ? $this->_logger : self::$logger;
        if($this->_instance == null) {
            $msg = "{$this->_insKey} 未连接到redis！";
            $this->_error($msg);
            $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            throw new YdRedisException($msg);
        }
        try {
            $result =  $this->_instance->hscan($key, $cursor, $pattern, $count);
            $lastError = $this->_instance->getLastError();
            $resultMsg = $result === false ? 'fail' : 'ok';
            $msg = "{$this->_insKey} {$resultMsg} cmd: {$name} params: ".self::jEncode($params)." result: ".self::jEncode($result).($lastError == null ? "" : " Error: {$lastError}");
            if($this->_cfg['cmdlog'] && $lastError !== null) {
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            } else if($this->_cfg['cmdlog'] && $lastError === null) {
                if($logger != null) $logger->info("{$msg}");
            } else if(!$this->_cfg['cmdlog'] && $lastError !== null) {
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            } else {
            }
            return $result;
        } catch(\Exception $e) {
            $msg = "{$this->_insKey} cmd: {$name} params: ".self::jEncode($params)." 执行失败 ".$e->getMessage();
            $this->_error($msg);
            $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            throw new YdRedisException($msg);
        }
    }
    public function zscan($key, &$cursor, $pattern = null, $count = null) {
        $name = "zscan";
        $params = [$key, $cursor, $pattern, $count];
        $logger = $this->_logger ? $this->_logger : self::$logger;
        if($this->_instance == null) {
            $msg = "{$this->_insKey} 未连接到redis！";
            $this->_error($msg);
            $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            throw new YdRedisException($msg);
        }
        try {
            $result =  $this->_instance->zscan($key, $cursor, $pattern, $count);
            $lastError = $this->_instance->getLastError();
            $resultMsg = $result === false ? 'fail' : 'ok';
            $msg = "{$this->_insKey} {$resultMsg} cmd: {$name} params: ".self::jEncode($params)." result: ".self::jEncode($result).($lastError == null ? "" : " Error: {$lastError}");
            if($this->_cfg['cmdlog'] && $lastError !== null) {
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            } else if($this->_cfg['cmdlog'] && $lastError === null) {
                if($logger != null) $logger->info("{$msg}");
            } else if(!$this->_cfg['cmdlog'] && $lastError !== null) {
                $this->_error($msg);
                $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            } else {
            }
            return $result;
        } catch(\Exception $e) {
            $msg = "{$this->_insKey} cmd: {$name} params: ".self::jEncode($params)." 执行失败 ".$e->getMessage();
            $this->_error($msg);
            $logger == null ? trigger_error("YdRedis {$msg}", E_USER_WARNING) : $logger->error("{$msg}");
            throw new YdRedisException($msg);
        }
    }

}

