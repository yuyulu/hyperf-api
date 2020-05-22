<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\Recharge;
use Hyperf\DbConnection\Db;

class RechargeController extends AbstractController
{
    public function index()
    {
    	$user = $this->request->getAttribute('user');
        $type = $this->request->input('type','');
        $page = $this->request->input('page',1);
        $query = Recharge::query();

        if ($type) {
        	$query->where('type',$type);
        }

        $query->where('uid',$user->id);

        $total_size = $query->count();

        $details = $query->orderBy('id','desc')
        ->offset(($page - 1) * 10)
        ->limit(10)
        ->get();

        $return['total_size'] = $total_size;
        $return['total_page'] = ceil($total_size / 10);
        $return['details'] = $details;

        return $this->success($return,__('success.get_success'));
    }

    public function walletRecharge()
    {
    	$qrcode = new \Endroid\QrCode\QrCode('http://baidu.com');
        $data['address'] = "http://www.baidu.com";
        $data['qrcode'] = 'data:image/png;base64,'.base64_encode($qrcode->writeString());

        return $this->success($data,__('success.get_success'));

    	$user = $this->request->getAttribute('user');
        $type = $this->request->input('type',1);

        $find = Db::table('user_address')
        ->where('uid',$user->id)
        ->where('type',$type)
        ->first();

        if (!empty($find)) {
        	$address = $find->address;
        } else {

        }
    }



}
