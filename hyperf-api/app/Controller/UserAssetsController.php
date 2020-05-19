<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\FeeRebates;
use App\Model\UserMoneyLog;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\RedisFactory;

class UserAssetsController extends AbstractController
{
	/**
	 * 获取账户余额信息
	 * @return [type] [description]
	 */
	public function assetInfo()
	{
		$user = $this->request->getAttribute('user');
		$assets = Db::table('user_assets')->where('uid',$user->id)->first();
		$redis = $this->container->get(RedisFactory::class)->get('price');
		$exrate = json_decode($redis->get('vb:indexTickerAll:usd2cny'),true);
		$assets->rmb = number_format($assets->balance * $exrate['USDT'],2,'.','');

		return $this->success($assets,__('success.get_success'));
	}

	/**
	 * 用户资金明细
	 * @return [type] [description]
	 */
    public function userMoneyLog()
    {
        $user = $this->request->getAttribute('user');
        $type = $this->request->input('type', '');
        $page = $this->request->input('page', 1);
        $query = UserMoneyLog::query();

        if ($type) {
        	$query->where('type',$type);
        }

        $total_size = $query->where('uid',$user->id)->count();

        $logs = $query->where('uid',$user->id)
        ->offset(($page - 1) * 10)
        ->limit(10)
        ->get();

        $return['total_size'] = $total_size;
        $return['total_page'] = ceil($total_size / 10);
        $return['logs'] = $logs;

        return $this->success($return,__('success.get_success'));
    }

    /**
     * 用户佣金明细
     * @return [type] [description]
     */
    public function commissionDetails(){
        $user = $this->request->getAttribute('user');
        $page = $this->request->input('page', 1);

        $query = FeeRebates::query();

        $total_size = $query->where('recommend_id',$user->id)->count();

        $details = $query->where('recommend_id',$user->id)
        ->select('from_uid','fee','recommend_yongjin','created_at')
        ->with('from')
        ->offset(($page - 1) * 10)
        ->limit(10)
        ->get();

        $return['total_size'] = $total_size;
        $return['total_page'] = ceil($total_size / 10);
        $return['logs'] = $logs;

        return $this->success($return,__('success.get_success'));
    }
}
