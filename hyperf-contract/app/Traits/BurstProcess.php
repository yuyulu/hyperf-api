<?php
declare(strict_types=1);

namespace App\Traits;

use Hyperf\DbConnection\Db;
use Hyperf\Redis\RedisFactory;
use Hyperf\Di\Annotation\Inject;
use Psr\Container\ContainerInterface;

trait BurstProcess
{
	/**
     * @Inject
     * @var ContainerInterface
     */
    protected $container;

    public function compute(int $uid,object $redis, object $logger): bool
    {
    	$server = $this->container->get(RedisFactory::class)->get('server');

	    //计算浮动盈亏
	    $lists = Db::table('user_positions')
	    ->where('uid',$uid)
	    ->get();

	    $minprofit = 0;
	    $allprofit = 0;
	    $alldeposit = 0;
	    $minid = 0;

	    foreach ($lists as $list) {
	    	//得到最新价
            $newprice = $redis->get('vb:ticker:newprice:'.$list->code);

            if (!$newprice) {
                $logger->error('BurstProcess get newprice error');
                break;
            }

            if ($list->otype == 1) {
                $profit = ($newprice - $list->buyprice)  * $list->buynum;
            } else {
                $profit = ($list->buyprice - $newprice) * $list->buynum;
            }

            if ($profit < $minprofit) {
                $minprofit = $profit;
                $minid     = $list->id;
            }

            $allprofit += $profit;
            $alldeposit += $list->totalprice;
	    }

	    unset($lists);

        if($alldeposit <= 0){
            return false;
        }

        $balance = Db::table('user_assets')
        ->where('uid',$uid)
        ->value('balance');

        //计算爆仓率
        //（余额 + 保证金 + 浮动盈亏）/ 保证金
        $risk = round(($balance + $alldeposit + $allprofit) / $alldeposit, 2);

        //取到后台设置爆仓率
        //计算爆仓率 <= 爆仓率 触发 爆仓
        $trans_conf = DB::table('admin_config')
        ->where('name','like','trans.%')
        ->pluck('value','name');

        if (!count($trans_conf)) {
            $logger->error('取到后台设置爆仓率 ERROR');
            return false;
        }

        $bcRate = $trans_conf['trans.bc_rate'] ? : 0;

        if($risk > ($bcRate * 0.01)){
            return true;
        }

        $logger->info('uid '.$uid.'risk '.$risk);
        $logger->info('uid '.$uid.'bcRate '.$bcRate);

        $minPosition = Db::table('user_positions')
            ->where('id',$minid)
            ->first();

        if(empty($minPosition)){
            $minPosition = Db::table('user_positions')
                ->where('uid',$position->uid)
                ->first();

            if(empty($minPosition)){
                return true;
            }
        }

        //得到最新价
        $newprice = $redis->get('vb:ticker:newprice:'.$minPosition->code);
        if (!$newprice) {
            $logger->error('BurstProcess get newprice error');
            return false;
        }

        $queueData['pc_type']  = 4;
        $queueData['price']    = $newprice;
        $queueData['position'] = $minPosition;
        $queueData['memo']     = '系统强平';

        $tid = $server->lpush('positions_process', json_encode($queueData));
        unset($queueData);

        if ($tid === false) {
        	$logger->error('推入队列失败 '.$minPosition->id.'=='.$minPosition->code);
            return false;
        } else {
        	$logger->error('REDIS上锁== '.$minPosition->uid.'=='.$minPosition->id);
            $redis->setex($minPosition->uid . ':bc_lock',10,1);
        }
    }
}
