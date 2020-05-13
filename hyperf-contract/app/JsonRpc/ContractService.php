<?php
namespace App\JsonRpc;

use Hyperf\Di\Annotation\Inject;
use Hyperf\DbConnection\Db;
use Hyperf\RpcServer\Annotation\RpcService;

/**
 * @RpcService(name="ContractService", protocol="jsonrpc-http", server="jsonrpc-http")
 */
class ContractService
{

    public function createOrder(array $order) :array
    {
        return [
        	'code' => 500,
        	'messages' => 'not_released',
            'method' => 'createOrder',
            'order' => $order,

        ];
    }

    public function closePosition(int $uid, int $order_id) :array
    {
        return [
        	'code' => 500,
        	'messages' => 'close error',
            'method' => 'closePosition',
            'uid' => $uid,
            'order_id' => $order_id,
        ];
    }

    public function closePositionAll(int $uid) :array
    {
        return [
        	'code' => 500,
        	'messages' => 'close error',
            'method' => 'closePositionAll',
            'uid' => $uid,
        ];
    }
}