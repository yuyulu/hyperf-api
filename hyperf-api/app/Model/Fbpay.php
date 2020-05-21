<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Fbpay extends Model
{
    protected $table = 'fb_pay';
    protected $guarded = ['id'];

    public function user() {
        return $this->belongsTo(User::class, 'uid','id')->select(['id','avatar','phone','name']);
    }
}
