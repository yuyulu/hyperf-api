<?php

declare(strict_types=1);

namespace App\Controller;

use Carbon\Carbon;
use App\Model\Fbpay;
use App\Model\Fbsell;
use App\Model\Fbbuying;
use App\Model\ShopApply;
use App\Model\UserAssets;
use Hyperf\DbConnection\Db;
use App\Model\Authentication;
use Hyperf\Di\Annotation\Inject;
use App\Service\WriteUserMoneyLogService;

class ShopController extends AbstractController
{
	/**
     * @Inject
     * @var WriteUserMoneyLogService
     */
    protected $WriteMoneyLog;

    /**
     * 申请成为商家
     * @return [type] [description]
     */
    public function shopApply()
    {
    	$user = $this->request->getAttribute('user');
    	if ($user->authentication != Authentication::ADVANCED_CHECK_AGREE) {
            return $this->failed(__('messages.advanced_certification_first'));
        }

        //法币交易商家 1提交审核 2同意 3拒绝 4撤销审核 5同意 6拒绝
        if ($user->fbshop == ShopApply::SHOP_APPLY_CHECK) {
            return $this->failed(__('messages.under_review'));
        }

        if ($user->fbshop == ShopApply::SHOP_APPLY_AGREE ||
            $user->fbshop == ShopApply::SHOP_CANCEL_REFUSE) {
        	return $this->failed(__('messages.already_a_merchant'));
        }

        if ($user->fbshop == ShopApply::SHOP_CANCEL_CHECK) {
        	return $this->failed(__('messages.please_wait_for_review'));
        }

        $position = Db::table('user_positions')->where('uid',$user->id)->first();

        if (!empty($position)) {
        	return $this->failed(__('messages.outstanding_contract_transaction_order'));
        }
        //成为商家费用
        $fbshop_money = Db::table('admin_config')->where('name','fb.fbshop_money')->value('value');

        Db::beginTransaction();
		try{
			//查询余额
	        $asset = UserAssets::query()
	        ->where('uid',$user->id)
	        ->lockForUpdate()
	        ->first();

	        if (empty($asset)) {
	        	Db::rollBack();
	        	return $this->failed(__('messages.account_abnormal'));
	        }

	        if ((float)$fbshop_money > $asset->balance) {
	        	return $this->failed(__('messages.insufficient_balance'));
	        }

	        //生成申请成为商家的订单
            $apply = ShopApply::create([
                'uid' => $user->id,
                'money' => $fbshop_money,
            ]);

	        //扣除费用 写入财务记录
	        $bool1 = $this->WriteMoneyLog->writeBalanceLog($asset, $apply->id, 'USDT', $fbshop_money * (-1), 13, 'apply_shop-deduct_balance');

	        if (!$bool1) {
	        	return $this->failed(__('failed.apply_shop_failed'));
	        }

	        Db::commit();
	        return $this->success('',__('messages.successful_submission_pending'));
		} catch(\Throwable $ex) {
		    Db::rollBack();
		    return $this->failed(__('failed.apply_shop_failed'));
		}
    }

    /**
     * 申请撤销商家
     * @return [type] [description]
     */
    public function shopCancel()
    {
    	$user = $this->request->getAttribute('user');

    	if ($user->fbshop == ShopApply::SHOP_APPLY_CHECK) {
    		return $this->failed(__('messages.please_wait_for_review'));
        }
        //审核通过才能撤销商家
        if($user->fbshop == ShopApply::SHOP_APPLY_REFUSE ){
        	return $this->failed(__('messages.not_yet_a_merchant'));
        }
        if ($user->fbshop == ShopApply::SHOP_CANCEL_CHECK) {
        	return $this->failed(__('messages.please_wait_for_review'));
        }
        if ($user->fbshop == ShopApply::SHOP_CANCEL_AGREE) {
            return $this->failed(__('messages.not_yet_a_merchant'));
        }

        if ($user->fbshop != ShopApply::SHOP_APPLY_AGREE &&
            $user->fbshop != ShopApply::SHOP_CANCEL_REFUSE) {
        	return $this->failed(__('messages.not_yet_a_merchant'));
        }

    	$buying = Db::table('fb_buying')->where('status',1)->first();
    	$sell = Db::table('fb_sell')->where('status',1)->first();

    	if (!empty($buying) || !empty($sell)) {
    		return $this->failed(__('messages.outstanding_trade_order'));
    	}

    	Db::beginTransaction();
		try{
	        ShopApply::create([
                'uid' => $user->id,
                'action' => ShopApply::SHOP_ACTION_CANCEL,
                'status' => ShopApply::SHOP_CANCEL_CHECK
            ]);

            $user->fbshop = ShopApply::SHOP_CANCEL_CHECK;
            $user->save();

	        Db::commit();
	        return $this->success('',__('messages.successful_submission_pending'));
		} catch(\Throwable $ex) {
		    Db::rollBack();
		    return $this->failed(__('failed.apply_cancel_shop_failed'));
		}
    }

    /**
     * 求购、出售发单
     * @return [type] [description]
     */
    public function postOrder()
    {
    	$validator = $this->validationFactory->make(
            $this->request->all(),
            [
                'type' => 'required',//1sell 2buying
                'min_price' => 'required|min:0',//最小交易额
                'max_price' => 'required|min:0',//最大交易额
                'trans_num' => 'required|min:0',//发布数量
                'price' => 'required|min:0',//单价
                'pay_bank' => 'required',
                'pay_alipay' => 'required',
                'pay_wx' => 'required',
                'payment_password' => 'required'
            ],
            [],
            [
                'type' => __('keys.type'),
                'min_price' => __('keys.min_price'),
                'max_price' => __('keys.max_price'),
                'trans_num' => __('keys.trans_num'),
                'price' => __('keys.price'),
                'pay_bank' => __('keys.pay_bank'),
                'pay_alipay' => __('keys.pay_alipay'),
                'pay_wx' => __('keys.pay_wx'),
                'payment_password' => __('keys.payment_password'),
            ]
        );

        if ($validator->fails()) {
            // Handle exception
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }

        $user = $this->request->getAttribute('user');
        $post = $this->request->all();

        if ($user->fbshop != ShopApply::SHOP_APPLY_AGREE &&
            $user->fbshop != ShopApply::SHOP_CANCEL_REFUSE) {
        	return $this->failed(__('messages.not_yet_a_merchant'));
        }

        if (!in_array($post['type'], [1,2])) {
        	return $this->failed(__('messages.wrong_post_type'));
        }

        if ($post['type'] == 1) {
        	$position = Db::table('user_positions')->where('uid',$user->id)->first();

	        if (!empty($position)) {
	        	return $this->failed(__('messages.outstanding_contract_transaction_order'));
	        }

	        if ($user->fbtrans == 1) {
	        	return $this->failed(__('messages.no_trading'));
	        }

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

			Db::beginTransaction();
			try{
				if ($post['type'] == 1) {
					//查询手续费
					$fb_rate = Db::table('admin_config')->where('name','fb.fb_rate')->value('value');

					$fee = number_format($post['trans_num'] * ($fb_rate * 0.01), 4, '.', '');

					//查询余额
			        $asset = UserAssets::query()
			        ->where('uid',$user->id)
			        ->lockForUpdate()
			        ->first();

			        if (empty($asset)) {
			        	Db::rollBack();
			        	return $this->failed(__('messages.account_abnormal'));
			        }
			        $money = $post['trans_num'] + $fee;

			        if ($money > $asset->balance) {
		                DB::rollBack();
		                return $this->failed(__('messages.insufficient_balance'));
		            }
				}

                $insert = [
                    'uid' => $user->id,
                    'trans_num' => $post['trans_num'],
                    'price' => $post['price'],
                    'totalprice' => $post['trans_num'] * $post['price'],
                    'sxfee' => $fee,
                    'min_price' => $post['min_price'],
                    'max_price' => $post['max_price'],
                    'pay_bank' => $post['pay_bank'],
                    'pay_alipay' => $post['pay_alipay'],
                    'pay_wx' => $post['pay_wx'],
                    'notes' => isset($post['notes']) ? $post['notes'] : '',
                ];

				if ($post['type'] == 1) {
					$Fbsell = Fbsell::create($insert);

					$Fbsell->order_no = $Fbsell->createSN();
					$Fbsell->save();

					$bool1 = $this->WriteMoneyLog->writeBalanceLog($asset, $Fbsell->id, 'USDT', $post['trans_num'] * (-1), 15, 'post_order-deduct_balance');

					$bool2 = $this->WriteMoneyLog->writeBalanceLog($asset, $Fbsell->id, 'USDT', $sxfee * (-1), 16, 'post_order-fee');

					if (!$bool1 || !$bool2) {
						Db::rollBack();
						return $this->failed(__('failed.post_sell_order_failed'));
					}

					Db::commit();
					return $this->success('',__('success.post_sell_order_successfully'));
				}
				$Fbbuying = Fbbuying::create($insert);
				$Fbbuying->order_no = $Fbbuying->createSN();
				$Fbbuying->save();

		        Db::commit();
		        return $this->success('',__('success.post_buying_order_successfully'));
			} catch(\Throwable $ex) {
			    Db::rollBack();
			    return $this->failed(__('failed.post_order_failed'));
			}
        }
    }

    /**
     * 我发布的出售、求购单
     * @return [type] [description]
     */
    public function orderList()
    {
    	$user = $this->request->getAttribute('user');
    	$type = $this->request->input('type',1);
    	$page = $this->request->input('page', 1);

    	if ($type == 1) {
    		$query = Fbsell::query();
    	} else {
    		$query = Fbbuying::query();
    	}

    	$total_size = $query->where('uid',$user->id)->where('status',1)->count();

    	$details = $query->where('uid',$user->id)
        ->where('status',1)
        ->orderBy('id', 'desc')
        ->offset(($page - 1) * 10)
        ->limit(10)
        ->get();

        foreach ($details as $detail) {
        	$detail->quota = $detail->min_price . '-' . $detail->max_price;
        }

        $return['total_size'] = $total_size;
        $return['total_page'] = ceil($total_size / 10);
        $return['details'] = $details;

        return $this->success($return,__('success.get_success'));
    }

    /**
     * 发布的求购、出售撤单
     * @return [type] [description]
     */
    public function cancelOrder()
    {
        $user = $this->request->getAttribute('user');
        $type = $this->request->input('type',1);
        $order_id = $this->request->input('order_id');

        if ($type == 1) {
            $query = Fbsell::query();
        } else {
            $query = Fbbuying::query();
        }

        $order = $query->where('id',$order_id)
        ->where('uid',$user->id)
        ->where('status',1)
        ->first();

        if (empty($order)) {
            return $this->failed(__('messages.order_not_exists'));
        }

        $trans = Db::table('fb_trans')
        ->where('jy_order',$order->order_no)
        ->where('status','<>', 3)
        ->where('status','<>', 5)
        ->first();

        if (!empty($trans)) {
            return $this->failed(__('messages.outstanding_order'));
        }

        Db::beginTransaction();
        try{
            $order->status = 3;
            $order->cancel_at = Carbon::now();
            $bool1 = $order->save();
            var_dump($bool1);
            if (!$bool1) {
                Db::rollBack();
                return $this->failed(__('failed.cancel_order_failed'));
            }

            if ($type == 1) {
                //查询余额
                $asset = UserAssets::query()
                ->where('uid',$user->id)
                ->lockForUpdate()
                ->first();

                if (empty($asset)) {
                    Db::rollBack();
                    return $this->failed(__('messages.account_abnormal'));
                }

                $inc = ($order->trans_num - $order->deals_num)  + $order->sxfee;
                $dec = ($order->trans_num - $order->deals_num)  * (-1);

                $bool2 = $this->WriteMoneyLog->writeBalanceLog($asset, $order->id, 'USDT', $inc, 17, 'shop_cancel_order-increase_balance');

                if (!$bool2) {
                    Db::rollBack();
                    return $this->failed(__('failed.cancel_order_failed'));
                }
            }

            Db::commit();
            return $this->success('',__('success.cancel_order_successfully'));
        } catch(\Throwable $ex) {
            Db::rollBack();
            return $this->failed(__('failed.cancel_order_failed').$ex->getMessage());
        }
    }

    /**
     * 添加、编辑支付方式
     * @param  \League\Flysystem\Filesystem $filesystem [description]
     * @return [type]                                   [description]
     */
    public function shopPay(\League\Flysystem\Filesystem $filesystem)
    {
        $user = $this->request->getAttribute('user');
        $type = $this->request->input('type');
        $act = $this->request->input('act','add');
        $payment_password = $this->request->input('payment_password');
        $bank = $this->request->input('bank');
        $branch = $this->request->input('branch');
        $card_num = $this->request->input('card_num');
        //1银行卡 2支付宝 3微信
        if (!in_array($type, [1,2,3])) {
            return $this->failed(__('messages.parameter_error'));
        }
        if ($user->authentication != Authentication::ADVANCED_CHECK_AGREE) {
            return $this->failed(__('messages.advanced_certification_first'));
        }

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

        $pay = Db::table('fb_pay')->where('uid',$user->id)->where('type',$type)->first();

        if ($act == 'add' && !empty($pay)) {
             return $this->failed(__('messages.this_payment_method_has_been_added'));
        }

        if ($act == 'edit' && empty($pay)) {
             return $this->failed(__('messages.this_payment_method_has_not_been_added'));
        }

        $data['uid'] = $user->id;
        $data['type'] = $type;
        $data['name']    = $user->name;

        if ($type == 1) {
            $data['bank']     = $bank;
            $data['branch']   = $branch;
            $data['card_num'] = $card_num;
            $data['mark'] = '银行卡';
            $data['type'] = $type;
        } else {
            if ($this->request->hasFile('qrcode')) {
                $qrcode_upload = $this->request->file('qrcode');
                $qrcode_upload_result = $this->upload($qrcode_upload, $filesystem);
            }

            if ($this->request->input('qrcode')) {
                if (preg_match('/^(data:\s*image\/(\w+);base64,)/', $this->request->input('qrcode'), $result)) {
                    $qrcode_upload_result = $this->base64Upload($this->request->input('qrcode'), $filesystem);
                }
            }

            if ($qrcode_upload_result['code'] != 200) {
                return $this->failed($qrcode_upload_result['msg']);
            }

            $qrcode = $qrcode_upload_result['data'];

            if (!$qrcode) {
                return $this->failed(__('messages.missing_file'));
            }

            if ($act == 'edit') {
                // Check if a file exists
                if ($filesystem->has($user->qrcode)) {
                    // Delete Files
                    $filesystem->delete($user->avatar);
                }
            }

            $data['qrcode'] = $qrcode;
            $data['card_num'] = $card_num;
            $data['mark'] = $type == 2 ? '支付宝':'微信';
        }

        if ($act == 'add') {
            Fbpay::create($data);
            return $this->success('',__('success.added_successfully'));
        } else {
            Fbpay::query()
            ->where('uid',$user->id)
            ->where('type',$type)
            ->update($data);
            return $this->success('',__('success.successfully_modified'));
        }
    }

    /**
     * 支付方式列表
     * @return [type] [description]
     */
    public function payList()
    {
        $user = $this->request->getAttribute('user');
        $list = Db::table('fb_pay')
        ->where('uid',$user->id)
        ->get();

        return $this->success($list,__('success.get_success'));
    }

    public function setPayStatus()
    {
        $user = $this->request->getAttribute('user');
        $type = $this->request->input('type');
        $status = $this->request->input('status');
        $result = Fbpay::query()
        ->where('uid',$user->id)
        ->where('type',$type)
        ->update(['status' => $status]);

        if ($result) {
            return $this->success($pay,__('success.successfully_modified'));
        } else {
            return $this->failed($pay,__('success.modified_failed'));
        }
    }

}
