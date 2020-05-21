<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Fbsell extends Model
{
    protected $table = 'fb_sell';
    protected $guarded = ['id'];

    public function user() {
        return $this->belongsTo(User::class, 'uid','id')->select(['id','avatar','phone','name']);
    }

    public function createSN()
    {
        return 'FBSELL' . date('YmdHis') . $this->id . mt_rand(1000, 9999);
    }
}
