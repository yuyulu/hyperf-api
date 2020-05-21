<?php
namespace App\JsonRpc;

use Hyperf\DbConnection\Db;
use App\Model\UserAssets;
use App\Model\UserEntrusts;
use App\Model\UserPositions;
use Hyperf\Redis\RedisFactory;
use Hyperf\Di\Annotation\Inject;
use Psr\Container\ContainerInterface;
use App\Service\WriteUserMoneyLogService;
use Hyperf\RpcServer\Annotation\RpcService;

/**
 * @RpcService(name="ContractService", protocol="jsonrpc-http", server="jsonrpc-http")
 */
class ContractService
{
    /**
     * @Inject
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @Inject
     * @var WriteUserMoneyLogService
     */
    protected $WriteMoneyLog;

    public function createOrder(array $input) :array
    {
        $uid = $input['uid'];//1市价 2 限价
        $type = $input['type'];//1市价 2 限价
        $otype = (int)$input['otype'];//1涨 2跌
        $buynum = (float)$input['buynum'];//买入数量
        $buyprice = (float)$input['buyprice']; //买入价格
        $code = $input['code']; //产品标识
        $leverage = $input['leverage']; // 产品杠杆

        $product = Db::table('products')
        ->where('code',$code)
        ->select('pname', 'code', 'leverage', 'min_order',
                'max_order', 'state', 'max_chicang', 'spread', 'var_price')
        ->first();

        if (!$product->state) {
            return ['msg' => __('messages.not_released'),'code' => 500, 'data' => ''];
        }

        $redis = $this->container->get(RedisFactory::class)->get('price');
        $newprice = $redis->get('vb:ticker:newprice:'.$code);

        if (!$newprice) {
            return ['msg' => __('messages.abnormal_data'), 'code' => 500, 'data' => ''];
        }

        $actprice = number_format($newprice, 4, '.', '');

        //点差
        $spread = $product->var_price * $product->spread;
        if ($type == 1) {  //市价
            if ($otype == 1) {
                $buyprice = $actprice + $spread;
            } else {
                $buyprice = $actprice - $spread;
            }
        }

        $num1 = Db::table('user_positions')
        ->where('uid', $uid)
        ->where('code', $product->code)
        ->sum('buynum');

        $num2 = Db::table('user_entrusts')
        ->where('uid', $uid)
        ->where('code', $product->code)
        ->where('status',1)
        ->sum('buynum');

        if ($buynum > ($product->max_chicang - $num1 - $num2)) {
            return ['msg' => __('keys.max_chicang').$product->max_chicang, 'code' => 500, 'data' => ''];
        }

        Db::beginTransaction();
        try{
            //查询余额
            $asset = UserAssets::query()
            ->where('uid',$uid)
            ->lockForUpdate()
            ->first();

            $trans_fee = 0.3;

            //总金额
            $money = ($newprice * $buynum) / $leverage;
            $fee = ($newprice * $buynum * $trans_fee) * 0.01;

            if ($asset->balance < ($money + $fee)) {
                Db::rollBack();
                return ['msg' => __('messages.insufficient_balance'), 'code' => 500, 'data' => ''];
            }

            $info = [
                'uid' => $uid,
                'name' => $product->pname,
                'code' => $product->code,
                'buyprice' => $buyprice,
                'buynum' => $buynum,
                'totalprice' => $money,
                'leverage' => $leverage,
                'otype' => $otype,
                'fee' => $fee,
                'spread' => $spread,
                'market_price' => $actprice,
            ];

            //市价单
            if ($type == 1) {
                //入MySQL
                $position = UserPositions::create($info);
                $position->hold_num = $position->createSN();
                $save = $position->save();
                $order_id = $position->id;
            }

            //限价单
            if ($type == 2) {
                //入MySQL
                $entrusts = UserEntrusts::create($info);
                $entrusts->en_num = $entrusts->createSN();
                $save = $entrusts->save();
                $order_id = $entrusts->id;
            }

            if (!$save) {
                Db::rollBack();
                return ['msg' => __('failed.create_order_failed'), 'code' => 500, 'data' => ''];
            }

            //扣除手续费
            if ($fee > 0) {
                $bool1 = $this->WriteMoneyLog->writeBalanceLog($asset,$order_id, 'USDT', $fee * (-1), 1, 'contract_create_order_fee');
                if (!$bool1) {
                    Db::rollBack();
                    return ['msg' => __('failed.create_order_failed'), 'code' => 500, 'data' => ''];
                }
            }

            //扣除保证金
            if ($money > 0) {
                $bool2 = $this->WriteMoneyLog->writeBalanceLog($asset,$order_id, 'USDT', $money * (-1), 2, 'contract_create_order_money');
                if (!$bool2) {
                    Db::rollBack();
                    return ['msg' => __('failed.create_order_failed'), 'code' => 500, 'data' => ''];
                }
            }

            Db::commit();
            return ['msg' => __('success.create_order_successfully'), 'code' => 200, 'data' => $info];
        } catch(\Throwable $ex) {
            Db::rollBack();
            return ['msg' => __('failed.create_order_failed'), 'code' => 500, 'data' => $ex->getMessage()];
        }


    }

    public function closePosition(int $uid, int $order_id) :array
    {
        $position = Db::table('user_positions')->where('id',$order_id)->first();
        if (empty($position)) {
            return ['msg' => __('messages.order_not_exists'), 'code' => 500, 'data' => ''];
        }

        $state = Db::table('products')->where('code',$position->code)->value('state');
        if (!$state) {
            return ['msg' => __('messages.not_released'), 'code' => 500, 'data' => ''];
        }

        $redis = $this->container->get(RedisFactory::class)->get('price');
        $newprice = $redis->get('vb:ticker:newprice:'.$position->code);

        if (!$newprice) {
            return ['msg' => __('messages.abnormal_data'), 'code' => 500, 'data' => ''];
        }

        $server = $this->container->get(RedisFactory::class)->get('server');

        $queue_data['pc_type']  = 1;
        $queue_data['price']    = $newprice;
        $queue_data['position'] = $position;
        $queue_data['memo']     = 'close_position_manually';

        $taskId = $server->lpush('positions_process', json_encode($queue_data));
        if ($taskId === false) {
            return ['msg' => __('failed.closed_position_failed'), 'code' => 500, 'data' => ''];
        } else {
            return ['msg' => __('success.closed_position_successfully'), 'code' => 200, 'data' => ''];
        }
    }

    public function closePositionAll(int $uid) :array
    {
        $positions = Db::table('user_positions')->where('uid',$uid)->get();
        if ($positions->count() < 1) {
            return ['msg' => __('messages.order_not_exists'), 'code' => 500, 'data' => ''];
        }
        $redis = $this->container->get(RedisFactory::class)->get('price');

        //  进平仓队列处理
        foreach ($positions as $position) {
            $product = Db::table('products')->where('code',$position->code)->value('pid');

            if(empty($product)){
                continue;
            }

            $newprice = $redis->get('vb:ticker:newprice:'.$position->code);

            if (!$newprice) {
                return ['msg' => __('failed.abnormal_data'), 'code' => 500, 'data' => ''];
            }

            $queue_data['pc_type']  = 1;
            $queue_data['price']    = $newprice;
            $queue_data['position'] = $position;
            $queue_data['memo']     = 'close_position_manually';

            $redis = $this->container->get(RedisFactory::class)->get('server');
        
            $tid = $redis->lpush('positions_process', json_encode($queue_data));
            if ($tid === false) {
                return ['msg' => __('failed.abnormal_data'), 'code' => 500, 'data' => ''];
            }
        }

        return ['msg' => __('success.closed_position_successfully'), 'code' => 200, 'data' => ''];
    }
}