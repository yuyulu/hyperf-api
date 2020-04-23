<?php
namespace App\JsonRpc;

use Hyperf\Di\Annotation\Inject;
use Hyperf\DbConnection\Db;
use Hyperf\RpcServer\Annotation\RpcService;

/**
 * @RpcService(name="ContractService", protocol="jsonrpc-http", server="jsonrpc-http")
 */
class ContractService {

    public function createOrder(array $order) :array
    {
        return [
        	'code' => 500,
        	'messages' => 'not_released',
            'method' => 'createOrder',
            'order' => $order,

        ];
    }
}