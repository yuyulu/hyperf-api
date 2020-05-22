<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Recharge extends Model
{
	const SYSTEM_RECHARGE = 1;//后台
    const ONLINE_RECHARGE = 2;//在线
    const WALLET_RECHARGE = 3;//钱包

    protected $table = 'recharges';
    protected $guarded = ['id'];

    public function user() {
        return $this->belongsTo(User::class, 'uid', 'id');
    }
}
