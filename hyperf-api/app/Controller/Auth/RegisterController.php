<?php
declare(strict_types = 1);

namespace App\Controller\Auth;

use Carbon\Carbon;
use App\Model\User;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use App\Controller\AbstractController;
use Hyperf\Utils\ApplicationContext;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;

class RegisterController extends AbstractController
{

    /**
     * @Inject()
     * @var ValidatorFactoryInterface
     */
    protected $validationFactory;

    /**
     * 用户注册.
     * @param  RequestInterface $request [description]
     * @return [type]                    [description]
     */
    public function register(RequestInterface $request)
    {
        $validator = $this->validationFactory->make(
            $request->all(),
            [
                'phone' => 'sometimes|required|regex:/^1[3456789][0-9]{9}$/|unique:users',
                'email' => 'sometimes|required|email|unique:users',
                'way' => 'required',
                'password' => 'required|string|min:6|max:12|confirmed',
                'password_confirmation' => 'required|string|min:6|max:12',
            ],
            [],
            [
                'phone' => __('keys.phone'),
                'email' => __('keys.email'),
                'way' => __('keys.way'),
                'password' => __('keys.password'),
                'password_confirmation' => __('keys.password_confirmation'),
            ]
        );

        if ($validator->fails()){
            // Handle exception
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }

        $input = $request->post();

        if(!isset($input['phone']) && !isset($input['email']))
        {
            return $this->failed(__('messages.please_enter').__('keys.phone'));
        }

        // Do something
        $hash = password_hash($request->input('password'), PASSWORD_DEFAULT);
        
        $container = ApplicationContext::getContainer();

        $account = mt_rand(1000,9999);

        $input['account'] = $account;

        $input['password'] = $hash;
        $user = User::create($input);

         if($user->phone){
            $user_config = [
                'uid' => $user->id,
                'phone_bind' => 1,
                'phone_verify_at' => Carbon::now(),
            ];
        }

        if($user->email){
            $user_config = [
                'uid' => $user->id,
                'email_bind' => 1,
                'email_verify_at' => Carbon::now(),
            ];
        }

        //写入个人配置信息
        $user->config()->create($user_config);

        $user->assets()->create([
            'uid' => $user->id
        ]);

        return $this->success('',__('messages.register_success'));
    }
}