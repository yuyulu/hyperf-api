<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Fbtrans extends Model
{
	const ORDER_PENDING = 1;
    const ORDER_PAID = 2;
    const ORDER_OVER = 3;
    const ORDER_APPEAL = 4;
    const ORDER_CANCEL = 5;

    protected $table = 'fb_trans';
    protected $guarded = ['id'];

    public function user() {
        return $this->belongsTo(User::class, 'uid','id')->select(['id','avatar','phone','name']);
    }

    public function createSN()
    {
        return 'FBTRANS' . date('YmdHis') . $this->id . mt_rand(1000, 9999);
    }
}
