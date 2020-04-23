<?php

include_once "./vendor/autoload.php";

use sethink\swooleRedis\CoRedis;
use sethink\swooleRedis\RedisPool;

class HttpServer
{
    protected $server;
    protected $RedisPool;

    public function __construct()
    {

        $this->server = new Swoole\Http\Server("0.0.0.0", 9502);
        $this->server->set(array(
            'worker_num'      => 4,
            'max_request'     => 50000,
            'reload_async'    => true,
            'max_wait_time'   => 30
        ));
        $this->server->on('Start', function ($server) {});
        $this->server->on('ManagerStart', function ($server) {});
        $this->server->on('WorkerStart', array($this, 'onWorkerStart'));
        $this->server->on('WorkerStop', function ($server, $worker_id) {});
        $this->server->on('open', function ($server, $request) {});
        $this->server->on('Request', array($this, 'onRequest'));
        $this->server->start();
    }

    public function onWorkerStart($server, $worker_id)
    {
        $config          = [
            'host'            => '127.0.0.1',
            'port'            => 6379,
            'auth'            => '000000',
            'poolMin'         => 5,   //空闲时，保存的最大链接，默认为5
            'poolMax'         => 1000,    //地址池最大连接数，默认1000
            'clearTime'       => 60000,   //清除空闲链接的定时器，默认60s
            'clearAll'        => 300000,  //空闲多久清空所有连接,默认300s
            'setDefer'        => true, //设置是否返回结果
            //options设置
            'connect_timeout' => 1, //连接超时时间，默认为1s
            'timeout'         => 1, //超时时间，默认为1s
            'serialize'       => false, //自动序列化，默认false
            'reconnect'       => 1  //自动连接尝试次数，默认为1次
        ];
        $this->RedisPool = new RedisPool($config);
        unset($config);
        
        //定时器，清除空闲连接
        $this->RedisPool->clearTimer($this->server);
    }

    public function onRequest($request, $response)
    {
        $rs1 = CoRedis::init($this->RedisPool)->set('sethink', 'sethink');
        var_dump($rs1);
        $rs2 = CoRedis::init($this->RedisPool)->get('sethink');
        var_dump($rs2);
    }
}

new HttpServer();