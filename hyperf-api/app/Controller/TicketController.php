<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\Products;
use Hyperf\DbConnection\Db;
use Hyperf\Redis\RedisFactory;

class TicketController extends AbstractController
{

	/**
	 * K线历史
	 * @return [type] [description]
	 */
    public function kline()
    {
        $pageSize = $this->request->input('pageSize');
        $goodsType = $this->request->input('goodsType','minute1');
        $code = $this->request->input('code','btc_usdt');

        $pageSize = $pageSize > 500 ? 500 : $pageSize;

        switch ($goodsType) {
        	case 'minute1':
        		$table = 'xy_1min_info';
        		break;
        	case 'minute5':
        		$table = 'xy_5min_info';
        		break;
        	case 'minute15':
        		$table = 'xy_15min_info';
        		break;
        	case 'minute30':
        		$table = 'xy_30min_info';
        		break;
        	case 'minute60':
        		$table = 'xy_60min_info';
        		break;
        	case 'hour4':
        		$table = 'xy_4hour_info';
        		break;
        	case 'day':
        		$table = 'xy_dayk_info';
        		break;
        	case 'week':
        		$table = 'xy_week_info';
        		break;
        	case 'month':
        		$table = 'xy_month_info';
        		break;
        	
        	default:
        		$table = 'xy_1min_info';
        		break;
        }

        $details = Db::table($table)->where('code',$code)
        ->limit($pageSize)
        ->get();

        return $this->success($details,__('success.get_success'));
    }

    /**
     * 行情列表
     * @return [type] [description]
     */
    public function getPro()
    {
    	$code = $this->request->input('code');
    	$query = Products::query();
    	if ($code) {
    		$query->where('code',1);
    	}

    	$products = $query->where('state',Products::DIS_TYPE)
    	->select('pid','pname as name','code','image')
    	->orderBy('sort','asc')
    	->get();

    	$details = [];
    	$redis = $this->container->get(RedisFactory::class)->get('price');
    	foreach ($products as $product) {
    		$ticker = json_decode($redis->get('vb:ticker:newitem:'.$product->code),true);

    		if (empty($ticker)) {
    			$ticker = [
                    'code' => $product->code,
                    'image' => $product->image,
                    'name' => $product->name,
                    'date' => date('Y-m-d'),
                    'time' => date('H:i:s'),
                    'price' => 0,
                    'cnyPrice' => 0,
                    'open' => 0,
                    'close' => 0,
                    'high' => 0,
                    'low' => 0,
                    'volume' => 0,
                    'change' => 0,
                    'changeRate' => 0,
                    'buy' => 0,
                    'sell' => 0,
                    'type' => 'ticker',
                ];
    		}

    		$ticker['image'] = $product->image;
    		$ticker['cnyPrice'] = round($ticker['cnyPrice'],2);
    		$details[] = $ticker;
    	}

    	return $this->success($details,__('success.get_success'));
    }

    /**
     * 盘口深度
     * @return [type] [description]
     */
    public function getDepth()
    {
    	$code = $this->request->input('code','btc_usdt');
    	$type = $this->request->input('type');

    	switch ($type) {
    		case 'depth':
    			$key = 'vb:depth:newitem:';
    			break;
    		case 'pct':
    			$key = 'vb:pct:newitem:';
    			break;
    		
    		default:
    			$key = 'vb:depth:newitem:';
    			break;
    	}
    	$redis = $this->container->get(RedisFactory::class)->get('price');

    	try{
		    $data = json_decode($redis->get($key.$code),true);
		    return $this->success($data,__('success.get_success'));
		} catch(\Throwable $ex){
			return $this->failed(__('failed.get_failed'));
		}
    	
    	
    }
}
