<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Authentication extends Model
{
	const PRIMARY_CHECK = 1;//初级认证
    const ADVANCED_WAIT_CHECK = 2;//高级认证待审核
    const ADVANCED_CHECK_AGREE = 3;//高级认证通过
    const ADVANCED_CHECK_REFUSE = 4;//高级认证拒绝
    
    protected $table = 'authentications';
    protected $guarded = ['id'];
}
