<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Model;

/**
 * @property int $id
 * @property string $mobile
 * @property string $realname
 */
class User extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'account','phone', 'email', 'password','recommend_id','staff_id','agent_id','unit_id','center_id','deep',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'payment_password','recommend_id','staff_id','agent_id','unit_id','center_id','relationship','deep'
    ];

    public function config() {
        return $this->hasOne(UserConfig::class, 'uid', 'id');
    }
    
    public function assets() {
        return $this->hasOne(UserAssets::class, 'uid', 'id');
    }


}
