<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class ShopApply extends Model
{
	const SHOP_APPLY_CHECK = 1;//申请待审核
    const SHOP_APPLY_AGREE = 2;//申请同意
    const SHOP_APPLY_REFUSE = 3;//申请拒绝
    const SHOP_CANCEL_CHECK = 4;//取消待审核
    const SHOP_CANCEL_AGREE = 5;//取消同意
    const SHOP_CANCEL_REFUSE = 6;//取消拒绝

    const SHOP_ACTION = 1;//申请商家操作
    const SHOP_ACTION_CANCEL = 2;//取消商家操作

    protected $table = 'shop_apply';
    protected $guarded = ['id'];

    public function user() {
        return $this->belongsTo(User::class, 'uid','id');
    }
}
