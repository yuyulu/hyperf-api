<?php

declare(strict_types=1);

namespace App\Service;

use Carbon\Carbon;
use Hyperf\Di\Annotation\Inject;

class CheckEmailService
{

	public function checkEmailCode($email,$code,$type = 1)
	{
        return  [
            'code' => 200,
            'msg' => '验证成功',
        ];
	}

}