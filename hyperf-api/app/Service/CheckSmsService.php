<?php

declare(strict_types=1);

namespace App\Service;

use Carbon\Carbon;
use Hyperf\Di\Annotation\Inject;

class CheckSmsService
{

    /**
     * 检测短信验证码
     * @param  [type] $phone #手机号
     * @param  [type] $type  #发送类型
     * @param  [type] $code  #验证码
     * @return [type]        #array
     */
	public function checkSmsCode($phone,$code,$type=1)
	{
        return  [
            'code' => 200,
            'msg' => '验证成功',
        ];
		$sms_log = SmsLog::query()
             ->where('phone', $phone)
             ->where('code', $code)
             ->where('type', $type)
             ->first();
             
         if (!$sms_log) {
             return  [
                 'code' => 500,
                 'msg' => '验证码错误',
             ];
         }
         if ($sms_log->used == 1) {
             return  [
                 'code' => 500,
                 'msg' => '验证码已失效, 请重新获取',
             ];
         }

         if (Carbon::now()->modify('-15 minutes')->gt($sms_log->created_at)) {
             return  [
                 'code' => 500,
                 'msg' => '验证码已过期, 请重新获取',
             ];
         }
         $sms_log->used = 1;
         $sms_log->save();
        return  [
            'code' => 200,
            'msg' => '验证成功',
        ];
	}

}