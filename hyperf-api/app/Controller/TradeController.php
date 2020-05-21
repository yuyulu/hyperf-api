<?php

declare(strict_types=1);

namespace App\Controller;

use Carbon\Carbon;
use App\Model\User;
use App\Model\Fbsell;
use App\Model\Fbtrans;
use App\Model\Fbbuying;
use App\Model\Fbappeal;
use App\Model\UserAssets;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\RedisFactory;
use Hyperf\Di\Annotation\Inject;
use App\Service\AutoConfirmationService;
use App\Service\WriteUserMoneyLogService;

class TradeController extends AbstractController
{
	/**
     * @Inject
     * @var AutoConfirmationService
     */
    protected $AutoConfirmationService;

    /**
     * @Inject
     * @var WriteUserMoneyLogService
     */
    protected $WriteMoneyLog;

    /**
     * 交易大厅
     * @return [type] [description]
     */
    public function trading()
    {
    	$type = $this->request->input('type',1);
    	$page = $this->request->input('page', 1);
    	if ($type == 1) {
    		$query = Fbsell::query();
    	} else {
    		$query = Fbbuying::query();
    	}

    	if ($type == 1) {
    		$query->orderBy('id','desc');
    	} else {
    		$query->orderBy('id','asc');
    	}

    	$total_size = $query->count();

    	$details = $query->with('user')
    	->where('status',1)
        ->whereRaw('trans_num > deals_num')
    	->offset(($page - 1) * 10)
        ->limit(10)
    	->get();

    	foreach ($details as $detail) {
    		$detail->amount = number_format($detail->trans_num - $detail->deals_num,2,'.','');
    		$detail->rate = number_format(($detail->deals_num / $detail->trans_num) * 100,2,'.','').'%';//完成率
    		$detail->total_price = $detail->amount * $detail->price;
    	}

    	$redis = $this->container->get(RedisFactory::class)->get('price');

    	$exrate = json_decode($redis->get('vb:indexTickerAll:usd2cny'),true);
    	$return['usdt_cny'] = number_format($exrate['USDT'],2,'.','');
    	$return['total_size'] = $total_size;
        $return['total_page'] = ceil($total_size / 10);
        $return['details'] = $details;

        return $this->success($return,__('success.get_success'));
    }

    /**
     * 交易大厅下单
     * @return [type] [description]
     */
    public function createOrder()
    {
    	$user = $this->request->getAttribute('user');
    	if($user->fbtrans){
            return $this->failed(__('messages.no_trading'));
        }

        $position = Db::table('user_positions')
        ->where('uid',$user->id)
        ->first();

        $entrusts = Db::table('user_entrusts')
        ->where('uid',$user->id)
        ->first();

        if (!empty($position) || !empty($entrusts)) {
        	return $this->failed(__('messages.outstanding_contract_transaction_order'));
        }

        $validator = $this->validationFactory->make(
            $this->request->all(),
            [
                'type' => 'required',
                'total_num' => 'required|numeric|min:1',
                'order_id' => 'required|numeric|min:1',
                'payment_password' => 'required',
            ],
            [],
            [
                'type' => __('keys.buyprice'),
                'total_num' => __('keys.total_num'),
                'order_id' => __('keys.order_id'),
                'payment_password' => __('keys.payment_password'),
            ]
        );

        if ($validator->fails()){
            // Handle exception
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }

        //撤单次数
        // $cancel_count = Fbtrans::query()->where(function ($query) use ($user){
        // 	$query->where('chu_uid',$user->id)->orWhere('gou_uid',$user->id);
        // })->where('status',Fbtrans::ORDER_CANCEL)
        // ->count();

        // if ($cancel_count >= 3) {
        // 	return $this->failed(__('messages.greater_than_the_number_of_cancellations'));
        // }

        $post = $this->request->all();

        if (!$user->payment_password) {
            return $this->failed(__('keys.payment_password') . __('messages.not_set'));
        }

        $decrypt_payment_password = $this->privateDecrypt($post['payment_password']);

        if ($decrypt_payment_password['code'] != 200) {
            return $this->failed($decrypt_payment_password['msg']);
        }
        $payment_password = $decrypt_payment_password['data'];

        if (!password_verify($payment_password, $user->payment_password)) {
            return $this->failed(__('messages.payment_password_is_incorrect'));
        }

        //检测有没有支付方式
        if ($post['type'] == 2) {
        	$payList = Db::table('fb_pay')->where('uid',$user->id)->first();
        	if (empty($payList)) {
        		return $this->failed(__('messages.please_add_payment_method_first'));
        	}
        }

        if ($post['type'] == 1) {
        	$query = Fbsell::query();
        } else {
        	$query = Fbbuying::query();
        }

        $order = $query->where('id',$post['order_id'])
        ->where('uid','!=',$user->id)
        ->whereRaw('trans_num > deals_num')
        ->where('status',1)
        ->first();

        if (empty($order)) {
        	return $this->failed(__('messages.order_not_exists'));
        }

        if ($post['total_num'] > ($order->trans_num - $order->deals_num)) {
        	return $this->failed(__('messages.insufficient_quantity'));
        }

        $total_price = number_format($post['total_num'] * $order->price, 2, '.', '');

        //获取手续费
        $trade_rate = Db::table('admin_config')->where('name','fb.trade_rate')->value('value');

        $fee = number_format($post['total_num'] * ($trade_rate * 0.01), 6, '.', '');

        Db::beginTransaction();
		try{
	        if ($post['type'] == 2) {
	        	//查询余额
		        $asset = UserAssets::query()
		        ->where('uid',$user->id)
		        ->lockForUpdate()
		        ->first();
	        	if ($asset->balance < ($post['total_num'] + $fee)) {
	        		Db::rollBack();
	        		return $this->failed(__('messages.insufficient_balance'));
	        	}
	        }

	        if ($post['type'] == 1) {
	        	//下单人是买家
	        	$insert['chu_uid'] = $order->uid;
	        	$insert['gou_uid'] = $user->id;
	        } else {
	        	//下单人是卖家
	        	$insert['chu_uid'] = $user->id;
                $insert['gou_uid'] = $order->uid;
	        }

	        $insert['pay_wx'] = $order->pay_wx;
            $insert['pay_alipay'] = $order->pay_alipay;
            $insert['pay_bank'] = $order->pay_bank;
            $insert['jy_order'] = $order->order_no;
            $insert['price'] = $order->price;
            $insert['total_num'] = $post['total_num'];
            $insert['total_price'] = $total_price;
            $insert['refer'] = mt_rand(1000, 9999);
            $insert['type'] = $post['type'];
            $insert['min_price'] = $order->min_price;
            $insert['max_price'] = $order->max_price;
            $insert['sxfee'] = $fee;

            $Fbtrans = Fbtrans::create($insert);

            $Fbtrans->order_no = $Fbtrans->createSN();
            $bool = $Fbtrans->save();

            unset($insert);

            if (!$bool) {
            	Db::rollBack();
		        return $this->failed(__('failed.create_order_failed'));
            }

            //增加成交数量
            $bool = $query->where('id',$order->id)->increment('deals_num',$post['total_num']);

            if (!$bool) {
            	Db::rollBack();
		        return $this->failed(__('failed.create_order_failed'));
            }

            unset($query);

            if ($post['type'] == 1) {
                $query = Fbsell::query();
            } else {
                $query = Fbbuying::query();
            }

            //判断是否完成
            $find = $query->where('id',$order->id)->first();

            if ($find->deals_num >= $find->trans_num) {
            	$query->where('id',$order->id)->update(['status'=> 2]);
            }


            //消耗手续费
            if ($fee > 0) {
            	$bool = $query->where('id',$order->id)->decrement('sxfee',$fee);

            	if (!$bool) {
            		Db::rollBack();
            		return $this->failed(__('failed.create_order_failed'));
            	}
            }

            if ($post['type'] == 2) {
            	$bool1 = $this->WriteMoneyLog->writeBalanceLog($asset, $order->id, 'USDT', $post['total_num'] * (-1), 18, 'trade_sell_order-deduct_balance');
            	if (!$bool1) {
            		Db::rollBack();
            		return $this->failed(__('failed.create_order_failed'));
            	}

            	if ($fee > 0) {
	            	$bool2 = $this->WriteMoneyLog->writeBalanceLog($asset, $order->id, 'USDT', $fee * (-1), 19, 'trade_sell_order-fee');

	            	if (!$bool2) {
	            		Db::rollBack();
	            		return $this->failed(__('failed.create_order_failed'));
	            	}
	            }

            }

            //自动取消时间-分钟
            $fb_time = Db::table('admin_config')->where('name','fb.fb_time')->value('value');

            $params['trans_id'] = $Fbtrans->id;
            $params['type'] = 2;//1自动确认 2自动取消

            $push = $this->AutoConfirmationService->push($params,$fb_time * 60);

            if ($push) {
            	Db::commit();
	            return $this->success('',__('success.create_order_successfully'));
            } else {
            	Db::rollBack();
		        return $this->failed(__('failed.create_order_failed'));
            }
	        
		} catch(\Throwable $ex) {
		    Db::rollBack();
		    return $this->failed(__('failed.create_order_failed').$ex->getMessage().$ex->getLine());
		}

    }

    /**
     * 订单详情
     * @return [type] [description]
     */
    public function orderDetail()
    {
    	$user = $this->request->getAttribute('user');
    	$order_id = $this->request->input('order_id');
    	$order = Db::table('fb_trans')->where('id',$order_id)->first();
    	if (empty($order)) {
    		return $this->failed(__('messages.order_not_exists'));
    	}

    	if ($order->chu_uid == $user->id) {
    		$other = User::find($order->gou_uid);
    	} elseif ($order->gou_uid == $user->id) {
    		$other = User::find($order->chu_uid);
    	} else {
    		return $this->failed(__('messages.order_not_exists'));
    	}

    	$backData['oop_account'] = $user->account;//对方编号
        $backData['oop_name'] = name_format($user->name);//对方姓名
        $backData['oop_mobile'] = $user->phone;//对方手机号
        $backData['pan_reason'] = '';
        $backData['command'] = '';

        //1待付款 2已付款 3已确认完成 4 申述中 5取消 6冻结
        if ($order->status == 4) {
        	$appeal = Db::table('fb_appeal')
        	->where('order_no',$order->order_no)
        	->select('pan_reason','command')
        	->first();
        	if (!empty($appeal)) {
        		$backData['pan_reason'] = $appeal->pan_reason;
                $backData['command'] = $appeal->command;
        	}
        }

        $backData['order_no'] = $order->order_no;
        $backData['total_num'] = $order->total_num;
        $backData['price'] = $order->price;//单价
        $backData['total_price'] = $order->total_price;//总计
        $backData['refer'] = $order->refer;//付款参考号
        $backData['created_at'] = Carbon::parse($order->created_at)->toDateTimeString();
        $backData['status'] = $order->status;//1未确认待付款 2已付款 3已确认完成 4 申述中 5取消
        $backData['pay_at'] = $order->pay_at; //付款时间

        //自动取消时间-分钟
        $fb_time = Db::table('admin_config')->where('name','fb.fb_time')->value('value');

        $created_at = Carbon::parse($order->created_at)->timestamp;
        $remaining = $created_at + $fb_time * 60 - time();
        if ($remaining <= 0) {
        	$remaining = 0;
        }
        //自动取消剩余时间
        $backData['down_time'] = $remaining;
        //自动确认剩余时间
        $qr_time = Db::table('admin_config')->where('name','fb.qr_time')->value('value');
        $pay_at = Carbon::parse($order->pay_at)->timestamp;

        if ($order->status == 2) {
        	$qr_time = $pay_at + $qr_time * 60 -time();
        	$qr_time = $qr_time <= 0 ? 0 : $qr_time;
        }
        $backData['qr_time'] = $qr_time;

        if ($order->type == 1) {
        	$notes = Db::table('fb_sell')->where('order_no',$order->jy_order)->value('notes');
        } else {
        	$notes = Db::table('fb_buying')->where('order_no',$order->jy_order)->value('notes');
        }
        $backData['notes'] = $notes;

        $pay_list = Db::table('fb_pay')
        ->where('uid', $order->chu_uid)
        ->where('status',1)
        ->get();

        $backData['pay_list'] = $pay_list;
        return $this->success($backData,__('success.get_success'));
    }

    /**
     * 标记付款
     */
    public function setOrderStatus()
    {
    	$user = $this->request->getAttribute('user');
    	$order_id = $this->request->input('order_id');

    	Db::beginTransaction();
		try{
		    $order = Fbtrans::query()
		    ->where('id',$order_id)
	    	->where('gou_uid',$user->id)
	    	->where('status',Fbtrans::ORDER_PENDING)
	    	->first();

	    	if (empty($order)) {
                Db::rollBack();
	    		return $this->failed(__('messages.order_not_exists'));
	    	}

	    	$order->status = Fbtrans::ORDER_PAID;
	    	$order->pay_at = Carbon::now();
	    	$bool = $order->save();

	    	if (!$bool) {
                Db::rollBack();
	    		return $this->failed(__('failed.mark_failed'));
	    	}

	    	//自动确认剩余时间
	        $qr_time = Db::table('admin_config')->where('name','fb.qr_time')->value('value');

	        $params['trans_id'] = $order->id;
            $params['type'] = 1;//1自动确认 2自动取消

	        $push = $this->AutoConfirmationService->push($params,$qr_time * 60);

	        if (!$push) {
	        	Db::rollBack();
		        return $this->failed(__('failed.mark_failed'));
	        } 
	        Db::commit();
	        return $this->success('',__('success.mark_successfully'));
		} catch(\Throwable $ex){
		    Db::rollBack();
		    return $this->failed(__('failed.mark_failed'));
		}

    }

    /**
     * 确认订单
     * @return [type] [description]
     */
    public function confirm()
    {
    	$user = $this->request->getAttribute('user');
    	$order_id = $this->request->input('order_id');
    	$payment_password = $this->request->input('payment_password');

    	if (!$user->payment_password) {
            return $this->failed(__('keys.payment_password') . __('messages.not_set'));
        }

        $decrypt_payment_password = $this->privateDecrypt($payment_password);

        if ($decrypt_payment_password['code'] != 200) {
            return $this->failed($decrypt_payment_password['msg']);
        }
        $payment_password = $decrypt_payment_password['data'];

        if (!password_verify($payment_password, $user->payment_password)) {
            return $this->failed(__('messages.payment_password_is_incorrect'));
        }

        Db::beginTransaction();
		try{

		    $order = Fbtrans::query()
		    ->where('id',$order_id)
		    ->where('chu_uid',$user->id)
		    ->where('status',Fbtrans::ORDER_PAID)
		    ->first();

		    if (empty($order)) {
		    	Db::rollBack();
		    	return $this->failed(__('messages.order_not_exists'));
		    }

		    $order->status = Fbtrans::ORDER_OVER;
		    $order->checked_at = Carbon::now();
		    $bool = $order->save();

		    if (!$bool) {
		    	Db::rollBack();
		    	return $this->failed(__('failed.confirmation_failed'));
		    }

		    //给购买人加余额
	        $GouAsset = UserAssets::query()
	        ->where('uid',$order->gou_uid)
	        ->lockForUpdate()
	        ->first();

	        if (empty($GouAsset)) {
	        	Db::rollBack();
	        	return $this->failed(__('failed.confirmation_failed'));
	        }

	        $bool1 = $this->WriteMoneyLog->writeBalanceLog($GouAsset, $order->id, 'USDT', $order->total_num, 20, 'trade_buying_order-increase_balance');

	        if (!$bool1) {
	        	Db::rollBack();
	        	return $this->failed(__('failed.confirmation_failed'));
	        }

		    Db::commit();
		    return $this->success(__('success.confirmation_successfully'));
		} catch(\Throwable $ex){
		    Db::rollBack();
		    return $this->failed(__('failed.confirmation_failed'));
		}
    }

    /**
     * 订单申诉
     * @return [type] [description]
     */
    public function appeal()
    {
    	$user = $this->request->getAttribute('user');

    	$validator = $this->validationFactory->make(
            $this->request->all(),
            [
                'refer' => 'required',
                'order_id' => 'required',
                'reason' => 'required',
            ],
            [],
            [
                'refer' => __('keys.buyprice'),
                'order_id' => __('keys.order_id'),
                'reason' => __('keys.reason'),
            ]
        );

        if ($validator->fails()){
            // Handle exception
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }

        $post = $this->request->all();

        Db::beginTransaction();
		try{

		    $order = Fbtrans::query()
	        ->where('id',$post['order_id'])
	        ->where('status',Fbtrans::ORDER_PAID)
	        ->first();

	        if (empty($order)) {
	        	Db::rollBack();
	        	return $this->failed(__('messages.order_not_exists'));
	        }

	        if ($order->chu_uid != $user->id && 
	        	$order->gou_uid != $user->id) {
	        	Db::rollBack();
	        	return $this->failed(__('messages.no_right_to_appeal_this_order'));
	        }

	        $Fbappeal = Fbappeal::create([
	        	'order_no' => $order->order_no,
	        	'command' => mt_rand(1000,9999),
	        	'refer' => $post['refer'],
	        	'appeal_uid' => $user->id,
	        	'be_appeal_uid' => $user->id == $order->chu_uid ? $order->gou_uid:$order->chu_uid,
	        	'type' => $order->type,
	        	'reason' => $post['reason'],
	        	'order_status' => $order->status
	        ]);

	        $order->status = Fbtrans::ORDER_APPEAL;
	        $bool = $order->save();
	        if (!$bool) {
	        	Db::rollBack();
	            return $this->failed(__('failed.appeal_failed'));
	        }

		    Db::commit();
		    return $this->success('',__('success.appeal_successfully'));
		} catch(\Throwable $ex){
		    Db::rollBack();
            var_dump($ex->getMessage());
		    return $this->failed(__('failed.appeal_failed'));
		}
    }

    /**
     * 取消订单
     * @return [type] [description]
     */
    public function cancelOrder()
    {
    	$user = $this->request->getAttribute('user');
    	$order_id = $this->request->input('order_id');

    	Db::beginTransaction();
		try{

		    $order = Fbtrans::query()
		    ->where('id',$order_id)
		    ->where('status',Fbtrans::ORDER_PENDING)
		    ->first();

		    if (empty($order)) {
		    	Db::rollBack();
		    	return $this->failed(__('messages.order_not_exists'));
		    }

		    if ($order->gou_uid != $user->id) {
		    	Db::rollBack();
		    	return $this->failed(__('messages.no_right_to_cancel_this_order'));
		    }

		    $order->status = Fbtrans::ORDER_CANCEL;
		    $order->cancel_at = Carbon::now();
		    $bool = $order->save();

		    if (!$bool) {
		    	Db::rollBack();
		    	return $this->failed(__('failed.cancel_order_failed'));
		    }

		    $ChuAsset = UserAssets::query()
	        ->where('uid',$order->chu_uid)
	        ->lockForUpdate()
	        ->first();

		    if ($order->type == 2) {
		    	//给卖家加余额
		    	$bool1 = $this->WriteMoneyLog->writeBalanceLog($ChuAsset, $order->id, 'USDT', $order->total_num + $order->sxfee, 21, 'trade_cancel_order-increase_balance');

		        if (!$bool1) {
		        	Db::rollBack();
		        	return $this->failed(__('failed.cancel_order_failed'));
		        }
		    }

		    if ($order->type == 1) {
		    	$query = Fbsell::query();
		    } else {
		    	$query = Fbbuying::query();
		    }

		    //减成交数量
		    $bool2 = $query->where('order_no',$order->jy_order)->decrement('deals_num',$order->total_num);

		    if (!$bool2) {
		    	Db::rollBack();
		        return $this->failed(__('failed.cancel_order_failed'));
		    }

		    if ($order->sxfee > 0) {
		    	$bool3 = $query->where('order_no',$order->jy_order)->increment('sxfee',$order->sxfee);
		    	if (!$bool3) {
		    		Db::rollBack();
		            return $this->failed(__('failed.cancel_order_failed'));
		    	}
		    }

		    Db::commit();
		    return $this->success('',__('success.cancel_order_successfully'));
		} catch(\Throwable $ex){
		    Db::rollBack();
		    return $this->failed(__('failed.cancel_order_failed'));
		}
    }

    /**
     * 我的订单
     * @return [type] [description]
     */
    public function myOrderList()
    {
    	$user = $this->request->getAttribute('user');
    	$type = $this->request->input('type');
    	$page = $this->request->input('page', 1);

    	$Fbquery = Fbtrans::query();

    	if ($type == 1) {
    		$Fbquery->where('gou_uid',$user->id);
    	} elseif ($type == 2) {
    		$Fbquery->where('chu_uid',$user->id);
    	} else {
    		$Fbquery->where(function ($query) use ($user){
                $query->where('chu_uid',$user->id)->orWhere('gou_uid',$user->id);
            });
    	}

    	$total_size = $Fbquery->count();

    	$details = $Fbquery
            ->select('id', 'chu_uid', 'gou_uid', 'order_no','type','status','price','total_num','total_price','created_at')
            ->offset(($page - 1) * 10)
            ->limit(10)
            ->orderBy('id', 'desc')
            ->get();

        $return['total_size'] = $total_size;
        $return['total_page'] = ceil($total_size / 10);
        $return['details'] = $details;

        return $this->success($return,__('success.get_success'));
    }

}
