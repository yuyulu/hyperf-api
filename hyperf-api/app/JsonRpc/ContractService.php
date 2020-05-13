<?php
declare(strict_types=1);

namespace App\JsonRpc;

use Hyperf\RpcClient\AbstractServiceClient;

/**
 * 服务消费者
 */
class ContractService extends AbstractServiceClient
{
    /**
     * 定义对应服务提供者的服务名称
     * @var string
     */
    protected $serviceName = 'ContractService';
    
    /**
     * 定义对应服务提供者的服务协议
     * @var string
     */
    protected $protocol = 'jsonrpc-http';

    public function createOrder(array $order): array
    {
        return $this->__request(__FUNCTION__, compact('order'));
    }

    public function closePosition(int $uid, int $order_id): array
    {
        return $this->__request(__FUNCTION__, compact('uid', 'order_id'));
    }

    public function closePositionAll(int $uid): array
    {
        return $this->__request(__FUNCTION__, compact('uid'));
    }


}