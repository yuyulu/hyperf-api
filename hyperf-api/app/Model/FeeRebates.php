<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class FeeRebates extends Model
{
    protected $title = '手续费返佣';
	
    protected $table = 'fee_rebates';
    protected $guarded = ['id'];

    public function from() {
        return $this->belongsTo(User::class, 'from_uid','id')->select(['id','account','phone','email','name']);
    }

    public function recommend() {
        return $this->belongsTo(User::class, 'recommend_id','id');
    }
}
