<?php

declare(strict_types=1);

namespace App\Job;

use Carbon\Carbon;
use App\Model\Fbsell;
use App\Model\Fbtrans;
use App\Model\Fbbuying;
use App\Model\UserAssets;
use App\Model\UserMoneyLog;
use Hyperf\AsyncQueue\Job;
use Hyperf\DbConnection\Db;

class AutoConfirmationJob extends Job
{
    public $params;

    public function __construct($params)
    {
        // 这里最好是普通数据，不要使用携带 IO 的对象，比如 PDO 对象
        $this->params = $params;
    }

    public function handle()
    {
        $params = $this->params;
        // 根据参数处理具体逻辑
        // 通过具体参数获取模型等
        // var_dump($this->params);
        $order_id = $params['trans_id'];
        $type = $params['type'];

        $order = Fbtrans::find($order_id);

        if (empty($order)) {
            return;
        }

        if ($type == 1) {
            if ($order->status != Fbtrans::ORDER_PAID) {
                return;
            }
            $this->confirm($order);
        }

        if ($type == 2) {
            if ($order->status != Fbtrans::ORDER_PENDING) {
                return;
            }
            $this->cancel($order);
        }
    }

    public function confirm($order)
    {
        Db::beginTransaction();
        try{
            //购买人加余额
            $GoAsset = UserAssets::query()
            ->where('uid',$order->gou_uid)
            ->lockForUpdate()
            ->first();

            $ymoney = $GoAsset->balance;
            $GoAsset->balance = $GoAsset->balance + $order->total_num;
            $bool1 = $GoAsset->save();

            if (!$bool1) {
                Db::rollBack();
                return;
            }
            $nmoney = $GoAsset->balance;

            $bool2 = UserMoneyLog::create([
                'uid' => $GoAsset->uid,
                'order_id' => $order->id,
                'ptype' => 'USDT',
                'ymoney' => $ymoney,
                'money' => $order->total_num,
                'nmoney' => $nmoney,
                'type' => 21,
                'mark' => 'trade_auto_confirm-increase_balance',
                'wt' => 1,
            ]);

            if (!$bool2) {
                Db::rollBack();
                return;
            }

            $order->status = Fbtrans::ORDER_OVER;
            $order->save();

            Db::commit();
        } catch(\Throwable $ex){
            Db::rollBack();
            var_dump($ex->getMessage().$ex->getLine());
        }
    }

    public function cancel($order)
    {
        Db::beginTransaction();
        try{
            if ($order->type == 1) {
                $query = Fbsell::query();
            } else {
                $query = Fbbuying::query();
            }
            $bool1 = $query->where('order_no',$order->jy_order)->decrement('deals_num',$order->total_num);

            if (!$bool1) {
                Db::rollBack();
                return;
            }

            //判断是否完成
            $find = $query->where('order_no',$order->jy_order)->first();

            if ($find->deals_num < $find->trans_num) {
                $query->where('id',$find->id)->update(['status' => 1]);
            }

            if ($order->sxfee > 0) {
                $bool2 = $query->where('order_no',$order->jy_order)->increment('sxfee',$order->sxfee);

                if (!$bool2) {
                    Db::rollBack();
                    return;
                }
            }

            if ($order->type == 2) {
                //返回出售人金额
                $ChuAsset = UserAssets::query()
                ->where('uid',$order->chu_uid)
                ->lockForUpdate()
                ->first();

                $ymoney = $ChuAsset->balance;
                $ChuAsset->balance = $ChuAsset->balance + $order->total_num;

                if ($order->total_num > 0) {
                    $bool1 = $ChuAsset->save();

                    if (!$bool1) {
                        Db::rollBack();
                        return;
                    }
                }
                
                $nmoney = $ChuAsset->balance;

                $bool2 = UserMoneyLog::create([
                    'uid' => $ChuAsset->uid,
                    'order_id' => $order->id,
                    'ptype' => 'USDT',
                    'ymoney' => $ymoney,
                    'money' => $order->total_num,
                    'nmoney' => $nmoney,
                    'type' => 22,
                    'mark' => 'trade_auto_cancel-increase_balance',
                    'wt' => 1,
                ]);

                if (!$bool2) {
                    Db::rollBack();
                    return;
                }
            }

            $order->status = Fbtrans::ORDER_CANCEL;
            $order->cancel_uid = $order->gou_uid;
            $order->cancel_at = Carbon::now();
            $order->save();

            Db::commit();
        } catch(\Throwable $ex){
            Db::rollBack();
            var_dump($ex->getMessage().$ex->getLine());
        }
    }
}
