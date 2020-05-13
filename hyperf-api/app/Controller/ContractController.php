<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace App\Controller;

use App\Model\UserTrans;
use App\Model\UserPositions;
use Hyperf\Validation\Rule;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\RedisFactory;
use App\JsonRpc\ContractService;
use Hyperf\Di\Annotation\Inject;
use App\Service\WriteUserMoneyLogService;

class ContractController extends AbstractController
{

    /**
     * @Inject
     * @var WriteUserMoneyLogService
     */
    protected $WriteMoneyLog;

    /**
     * 用户创建订单
     * @param  ContractService $contract [description]
     * @return [type]                    [description]
     */
    public function createOrder(ContractService $contract)
    {
        // 交易状态 1为开放 2为关闭
        $status = Db::table('admin_config')
        ->where('name','trans.status')
        ->value('value');

        if ($status == 2) {
            return $this->failed(__('messages.service_maintenance'));
        }

        $method = $this->request->getMethod();

        if (!$this->request->isMethod('post')) {
            return $this->failed(__('messages.method_not_allowed'));
        }

        $validator = $this->validationFactory->make(
            $this->request->all(),
            [
                'buynum' => 'required|numeric|min:0.1',
                'type' => ['required',Rule::in([1, 2])],//1市价 2限价
                'otype' => ['required',Rule::in([1, 2])],//1买涨 2买跌
                'code' => 'required|string',
                'leverage' => 'required|integer|min:1',//杠杆
            ],
            [],
            [
                'buynum' => __('keys.buynum'),
                'type' => __('keys.type'),
                'otype' => __('keys.otype'),
                'code' => __('keys.code'),
                'leverage' => __('keys.leverage'),
            ]
        );

        if ($validator->fails()){
            // Handle exception
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }

        $input = $this->request->all();

        $state = Db::table('products')->where('code',$position->code)->value('state');
        if (!$state) {
            return $this->failed(__('messages.not_released'));
        }

    	try {
			return $contract->createOrder($input);
    	} catch (\Throwable $throwable) {
    		return $this->failed($throwable->getMessage());
    	}
        
    }

    /**
     * 用户单个平仓
     * @param  ContractService $contract [description]
     * @return [type]                    [description]
     */
    public function closePosition(ContractService $contract)
    {
        $user = $this->request->getAttribute('user');
        $order_id = $this->request->input('order_id');
        $position = Db::table('user_positions')->where('id',$order_id)->first();
        if (empty($position)) {
            return $this->failed(__('messages.order_not_exists'));
        }

        $state = Db::table('products')->where('code',$position->code)->value('state');
        if (!$state) {
            return $this->failed(__('messages.not_released'));
        }

        try {
            return $contract->closePosition((int)$user->id, (int)$order_id);
        } catch (\Throwable $throwable) {
            return $this->failed($throwable->getMessage());
        }
    }

    /**
     * 用户一键全平
     * @param  ContractService $contract [description]
     * @return [type]                    [description]
     */
    public function closePositionAll(ContractService $contract)
    {
        $user = $this->request->getAttribute('user');
        $position = Db::table('user_positions')
        ->where('uid',$user->id)->first();
        if (empty($position)) {
            return $this->failed(__('messages.order_not_exists'));
        }

        try {
            return $contract->closePositionAll((int)$user->id);
        } catch (\Throwable $throwable) {
            return $this->failed($throwable->getMessage());
        }
    }

    /**
     * 用户设置止盈止损
     * @param ContractService $contract [description]
     */
    public function setProfitOrLoss(ContractService $contract)
    {
        $user = $this->request->getAttribute('user');
        $hold_id = $this->request->input('hold_id');
        $profit = (float)$this->request->input('profit',0);
        $loss = (float)$this->request->input('loss',0);

        if (!$profit && !$loss) {
            return $this->failed(__('messages.parameter_error'));
        }

        //查询订单是否存在
        $position = Db::table('user_positions')->where('uid',$user->id)
        ->where('id',$hold_id)
        ->first();

        if (empty($position)) {
            return $this->failed(__('messages.order_not_exists'));
        }

        $redis = $this->container->get(RedisFactory::class)->get('price');

        $newprice = $redis->get('vb:ticker:newprice:'.$position->code);

        if (!$newprice) {
            return $this->failed(__('messages.abnormal_data'));
        }

        //做多时：止损不能高于现价，止盈不能低于现价。做空时：止损不能低于现价，止盈不能高于现价
        // 1 做多  2做空
        if ($position->otype == 1) {
            if ($profit != 0 && $profit < $newprice) {
                return __return($this->errStatus, __('messages.profit_must_be_greater_than_newprice'));
            }
            if ($loss != 0 && $loss > $newprice) {
                return __return($this->errStatus, __('messages.loss_must_be_less_than_newprice'));
            }
        } else {
            if ($profit != 0 && $profit > $newprice) {
                return __return($this->errStatus, __('messages.profit_must_be_less_than_newprice'));
            }
            if ($loss != 0 && $loss < $newprice) {
                return __return($this->errStatus, __('messages.loss_must_be_greater_than_newprice'));
            }
        }

        $bool = Db::table('user_positions')
        ->where('uid', $userInfo->id)
        ->where('id', $hold_id)
        ->update([
            'stopwin'   => $profit,
            'stoploss'  => $loss,
            ]);

        if ($bool) {
            return $this->success('',__('success.set_up_successfully'));
        } else {
            return $this->failed(__('failed.set_up_failed'));
        }
    }

    /**
     * 用户取消委托单
     * @param  ContractService $contract [description]
     * @return [type]                    [description]
     */
    public function cancelOrder(ContractService $contract)
    {
        $user = $this->request->getAttribute('user');
        $order_id = $this->request->input('order_id');

        $entrust = Db::table('user_entrusts')
        ->where('uid',$user->id)
        ->where('id',$order_id)
        ->where('status',1)
        ->first();

        if (empty($entrust)) {
            return $this->failed(__('messages.order_not_exists'));
        }

        Db::beginTransaction();
        try{
            //查询余额
            $asset = Db::table('user_assets')
            ->where('uid',$user->id)
            ->lockForUpdate()
            ->first();

            $money = $entrust->totalprice + $entrust->fee;

            //流水
            $bool1 = $this->WriteMoneyLog->writeBalanceLog($asset,$entrust->id, 'USDT', $money, 3, 'contract_cancel_order');

            if (!$bool1) {
                return $this->failed(__('faild.cancel_order_failed'));
            }

            $bool2 = Db::table('user_entrusts')
            ->where('uid',$user->id)
            ->where('id',$order_id)
            ->where('status',1)
            ->update(['status' => 3]);

            if (!$bool2) {
                return $this->failed(__('faild.cancel_order_failed'));
            }

            Db::commit();
            return $this->success('',__('success.cancel_order_success'));
        } catch(\Throwable $ex) {
            Db::rollBack();
            return $this->failed(__('faild.cancel_order_failed'));
        }
    }

    /**
     * 用户持仓、委托数据
     * @param  ContractService $contract [description]
     * @return [type]                    [description]
     */
    public function positionsData(ContractService $contract)
    {
        $user = $this->request->getAttribute('user');
        $code = $this->request->input('code','');
        $type  = $request->get('data_type', 1);//1持仓2委托

        if ($type == 1) {
            $query = UserPositions::query();
        } else {
            $query = UserEntrusts::where('status',1)->query();
        }

        if ($code != '') {
            $query->where('code', $code);
        }

        $positions = $query->where('uid',$user->id)
        ->orderBy('id','desc')
        ->get();

        return $this->success($statistics, __('success.get_success'));
    }

    /**
     * 用户持仓数据
     * @return [type] [description]
     */
    public function statistics()
    {
        $user = $this->request->getAttribute('user');
        //账户余额
        $balance = Db::table('user_assets')->where('uid',$user->id)->value('balance');
        //计算浮动盈亏
        $positions = Db::table('user_positions')->where('uid',$user->id)->get();
        //浮动盈亏
        $totalprofit = 0;
        //保证金
        $totalmoney = 0;
        foreach ($positions as $position) {
            $redis = $this->container->get(RedisFactory::class)->get('price');

            $newprice = $redis->get('vb:ticker:newprice:'.$position->code);
            if ($position->otype == 1) {
                $yingkui = ($newprice - $position->buyprice) * $position->buynum;
            } else {
                $yingkui = ($position->buyprice - $newprice) * $position->buynum;
            }
            $totalprofit += $yingkui;
            $totalmoney += $position->totalprice;
        }
        $totalmoney = round($totalmoney, 4);
        //动态权益
        $totalusdt = $totalprofit + $totalmoney + $balance;
        //风险率 动态权益 / 保证金
        $risk = number_format(($totalusdt / $totalmoney),2,'.','');

        $return = [
            'balance' => $balance,
            'totalprofit' => $totalprofit,
            'totalmoney' => $totalmoney,
            'totalusdt' => $totalusdt,
            'risk' => $risk.'%'
        ];

        return $this->success($statistics, __('success.get_success'));
    }

    /**
     * 用户全部订单
     * @param  ContractService $contract [description]
     * @return [type]                    [description]
     */
    public function orderList(ContractService $contract)
    {
        $page = $this->request->input('page', 1);
        $user = $this->request->getAttribute('user');
        $code = $this->request->input('code','');
        $start_time = $this->request->input('start_time','');
        $end_time = $this->request->input('end_time','');
        $query = UserTrans::query();

        if ($code) {
            $query->where('code',$code);
        }

        if ($start_time) {
            $query->where('created_at','>=', $start_time);
        }

        if ($end_time) {
            $query->where('created_at','<=', $end_time);
        }

        $orders = $query->where('uid', $user->id)
            ->orderBy('id', 'desc')
            ->offset(($page - 1) * 10)
            ->limit(10)
            ->get();

        return $this->success($orders, __('success.get_success'));
    }
}