<?php
declare(strict_types = 1);


namespace App\Controller\Auth;

use App\Model\User;
use Phper666\JwtAuth\Jwt;
use Hyperf\DbConnection\Db;
use App\Controller\AbstractController;
use Hyperf\Di\Annotation\Inject;

class LoginController extends AbstractController
{
    /**
     * @Inject
     *
     * @var Jwt
     */
    protected $jwt;

    /**
     * 用户登录
     * @return [type] [description]
     */
    public function login()
    {
        $validator = $this->validationFactory->make(
            $this->request->all(),
            [
                'phone' => 'required',
                'password' => 'required',
                'login_type' => 'required'
            ],
            [],
            [
                'phone' => __('keys.phone'),
                'password' => __('keys.password'),
                'login_type' => __('keys.login_type'),
            ]
        );

        if ($validator->fails()) {
            // Handle exception
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }

        $login_type = $this->request->input('login_type');

        if (!in_array($login_type, [1,2])) {
            return $this->failed(__('keys.login_type').__('messages.does_not_exist'));
        }

        $user = User::query()
        ->where('phone', $this->request->input('phone'))
        ->first();

        if (empty($user)) {
            return $this->failed(__('messages.user_not_exists'));
        }

        //验证用户账户密码
        if (password_verify($this->request->input('password'), $user->password)) {
            Db::table('user_config')
            ->where('uid',$user->id)
            ->update(['login_type' => $login_type]);

            $userData = [
                'uid'       => $user->id,
                'account'  => $user->account,
            ];
            
            $token = $this->jwt->getToken($userData);
            $data  = [
                'token' => (string) $token,
                'exp'   => $this->jwt->getTTL(),
            ];
            return $this->success($data);
        }

        return $this->failed(__('messages.login_failed'));
    }

    /**
     * 用户登出
     * @return [type] [description]
     */
    public function logout()
    {
        if ($this->jwt->logout()) {
            return $this->success('','退出登录成功');
        };
        return $this->failed('退出登录失败');
    }
}