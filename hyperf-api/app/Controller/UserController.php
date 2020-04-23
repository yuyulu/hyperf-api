<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

namespace App\Controller;

use App\JsonRpc\SendSmsService;
use App\JsonRpc\SendEmailService;

class UserController extends AbstractController
{

    /**
     * 查询用户信息 包含了 身份认证和个人配置
     * @return [type] [description]
     */
    public function details()
    {
        $user = $this->request->getAttribute('user');
        $user_config = $this->request->getAttribute('user_config');
        $authentication = Db::table('authentications')
        ->where('uid',$user->id)
        ->where('status',1)
        ->first();

        if (empty($authentication)) {
            $user->refuse_reason = '';
            $user->card_id = '';
        } else {
            $user->refuse_reason = $authentication->refuse_reason;
            $user->card_id = $authentication->card_id;
        }

        $user->assets = Db::table('user_assets')
        ->where('uid',$user->id)
        ->first();

        $user->config = $user_config;

        return $this->success($user,__('success.get_success'));
    }

    /**
     * 用户登录历史记录
     * @return [type] [description]
     */
    public function loginHistory(){
        $page = $this->request->input('page',1);
        $user = $this->request->getAttribute('user');

        $return['count'] = Db::table('user_login_history')
            ->where('uid',$user->id)
            ->count();

        $return['logs'] = Db::table('user_login_history')
            ->where('uid',$user->id)
            ->orderBy('id','desc')
            ->offset(($page - 1) * 10)
            ->limit(10)
            ->get();

        return $this->success($return,__('success.get_success'));
    }

    /**
     * 用户发送短信
     * @param  SendSmsService $service [description]
     * @return [type]                  [description]
     */
    public function sendSms(SendSmsService $service)
    {
        $user = $this->request->getAttribute('user');
        if (!empty($user)) {
            $phone = $user->phone;
        } else {
            $phone = $this->request->input('phone');
        }
    	$result = $service->sendSms($phone,$area_code,$locale,$sign);

        if($result['code'] == 200) {
            return $this->success('',__('success.sent_successfully'));
        } else {
            return $this->failed(__('failed.failed_to_send'));
        }
    }

    /**
     * 用户发送邮件
     * @param  SendEmailService $service [description]
     * @return [type]                    [description]
     */
    public function sendEmail(SendEmailService $service)
    {
        $user = $this->request->getAttribute('user');
        if (!empty($user)) {
            $email = $user->email;
        } else {
            $email = $this->request->input('email');
        }

        $locale = $this->translator->getLocale();
        $sign = env('SMS_SIGN');
    	$result = $service->sendEmail($email,$locale,$sign);

        if($result['code'] == 200) {
            return $this->success('',__('success.sent_successfully'));
        } else {
            return $this->failed(__('failed.failed_to_send'));
        }
    }

    /**
     * 用户推荐链接+二维码
     * @return [type] [description]
     */
    public function registerLink()
    {
        $user = $this->request->getAttribute('user');

        $url = env('USER_REGISTER_URL');
        $querys = '';
        $querys .= '?recommend=' . $user->account;

        $qrcode = '';

        $data['account'] = $user->account;
        $data['url'] = $url . $querys;
        $data['qrcode'] = 'data:image/png;base64,' . base64_encode($qrcode);

        return $this->success($data,__('success.get_success'));
    }

    /**
     * 更新头像
     * @param  \League\Flysystem\Filesystem $filesystem [文件系统]
     * @return [type]                                   [description]
     */
    public function updateAvatar(\League\Flysystem\Filesystem $filesystem)
    {
        $user = $this->request->getAttribute('user');

        if ($this->request->hasFile('avatar')) {
            $avatar_upload = $this->request->file('avatar');
            $avatar_upload_result = $this->upload($avatar_upload);
        }

        if(preg_match('/^(data:\s*image\/(\w+);base64,)/', $this->request->input('avatar'), $result)){
            $avatar_upload_result = $this->base64Upload($this->request->input('avatar'));
        }

        if ($avatar_upload_result['code'] != 200) {
            return $this->failed($avatar_upload_result['msg']);
        }

        $avatar = $avatar_upload_result['data'];

        if(!$avatar){
            return $this->failed(__('please_upload_avatar'));
        }

        // Check if a file exists
        if ($filesystem->has($user->avatar)) {
            // Delete Files
            $filesystem->delete($user->avatar);
        }

        $user->avatar = $avatar;
        $user->save();
        
        $this->success('',__('success.successfully_modified'));

    }

    /**
     * 验证原密码是否正确
     * @return [type] [description]
     */
    public function verifyPassword()
    {
        $user = $this->request->getAttribute('user');
        if(!in_array($this->request->input('type'),['password','payment'])){
            return $this->failed(__('messages.wrong_modification_type'));
        }

        if ($this->request->input('type') === 'password') {
            if (!Hash::check($this->request->input('old_password'), $user->password)) {
                return $this->failed(__('messages.original_password_is_incorrect'));
            }
        }

        if ($this->request->input('type') === 'payment') {
            if (!Hash::check($this->request->input('old_password'), $user->payment_password)) {
                return $this->failed(__('messages.original_password_is_incorrect'));
            }
        }

        return $this->success('', __('success.successful_verification'));

    }

    /**
     * 重置用户登录密码
     * @return [type] [description]
     */
    public function resetPassword()
    {
        $user = $this->request->getAttribute('user');
        $validator = $this->validationFactory->make(
            $this->request->all(),
            [
                'old_password' => 'required',
                'password' => 'required',
                'password_confirmation' => 'required'
            ],
            [],
            [
                'old_password' => __('keys.original_password'),
                'password' => __('keys.password'),
                'password_confirmation' => __('keys.password_confirmation'),
            ]
        );

        if ($validator->fails()) {
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }

        //解析原密码
        $decrypt_old_password = $this->privateDecrypt($this->request->input('old_password'));
        $old_password = $decrypt_old_password['data'];
        if($decrypt_old_password['code'] != 200){
            return $this->failed($decrypt_old_password['msg']);
        }

        if (!Hash::check($old_password, $user->password)) {
            return $this->failed(__('Original password'));
        }

        $decrypt_password = $this->privateDecrypt($this->request->input('password'));

        if($decrypt_password['code'] != 200){
            return $this->failed($decrypt_password['msg']);
        }

        $decrypt_password_confirmation = $this->privateDecrypt($this->request->input('password_confirmation'));

        if($decrypt_password_confirmation['code'] != 200){
            return $this->failed($decrypt_password_confirmation['msg']);
        }

        $password = $decrypt_password['data'];
        $password_confirmation = $decrypt_password_confirmation['data'];

        if($password != $password_confirmation){
            return $this->failed(__('Passwords are inconsistent'));
        }

        if($old_password == $password){
            return $this->failed(__('Original password').__('Password confirmation').__('Cannot be the same'));
        }

        $new_pass = password_hash($password);
        $user->password = $new_pass;
        $user->save();

        return $this->success('', __('Successfully modified'));
    }

    public function resetPaymentPassword()
    {
        $user = $this->request->getAttribute('user');
        $validator = $this->validationFactory->make(
            $this->request->all(),
            [
                'code' => 'required|min:6|max:10',
                'old_password' => 'required',
                'password' => 'required',
                'password_confirmation' => 'required'
            ],
            [],
            [
                'code' => __('Code'),
                'old_password' => __('Original password'),
                'password' => __('Password'),
                'password_confirmation' => __('Password confirmation'),
            ]
        );

        if ($validator->fails()) {
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }
        $decrypt_old_password = $this->privateDecrypt($this->request->input('old_password'));

        if($decrypt_old_password['code'] != 200){
            return $this->failed($decrypt_old_password['msg']);
        }
        $old_password = $decrypt_old_password['data'];

        if (!Hash::check($old_password, $user->payment_password)) {
            return $this->failed(__('Original password'));
        }

        $decrypt_password = $this->privateDecrypt($this->request->input('password'));

        if($decrypt_password['code'] != 200){
            return $this->failed($decrypt_password['msg']);
        }

        $decrypt_password_confirmation = $this->privateDecrypt($this->request->input('password_confirmation'));

        if($decrypt_password_confirmation['code'] != 200){
            return $this->failed($decrypt_password_confirmation['msg']);
        }

        $password = $decrypt_password['data'];
        $password_confirmation = $decrypt_password_confirmation['data'];

        if($password != $password_confirmation){
            return $this->failed(__('Passwords are inconsistent'));
        }

        if($old_password == $password){
            return $this->failed(__('Original password').__('Password confirmation').__('Cannot be the same'));
        }

        ///检测验证码
        $result = $this->checkSmsCode($user->phone,$this->request->input('code'));
        if($result['code'] != 200){
            return $this->failed($this->errStatus,$result['msg']);
        }

        $new_pass = Hash::make($password);

        $user->payment_password = $new_pass;
        $user->save();

        return $this->success('', __('Successfully modified'));
    }

    public function forgetPassword()
    {
        $validator = $this->validationFactory->make(
            $this->request->all(),
            [
                'phone' => 'sometimes|required|regex:/^1[3456789][0-9]{9}$/',
                'email' => 'sometimes|required|email',
                'code' => 'required',
                'password' => 'required',
                'password_confirmation' => 'required'
            ],
            [],
            [
                'phone' => __('keys.phone'),
                'email' => __('keys.email'),
                'code' => __('keys.code'),
                'old_password' => __('keys.original_password'),
                'password' => __('keys.password'),
                'password_confirmation' => __('keys.password_confirmation'),
            ]
        );

        if ($validator->fails()) {
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }

        $decrypt_password = $this->privateDecrypt($this->request->input('password'));

        if ($decrypt_password['code'] != 200) {
            return $this->failed($decrypt_password['msg']);
        }

        $decrypt_password_confirmation = $this->privateDecrypt($this->request->input('password_confirmation'));

        if ($decrypt_password_confirmation['code'] != 200) {
            return $this->failed($decrypt_password_confirmation['msg']);
        }

        $password = $decrypt_password['data'];
        $password_confirmation = $decrypt_password_confirmation['data'];

        if($password != $password_confirmation){
            return $this->failed(__('messages.two_password_is_inconsistent'));
        }

        //如果发送手机验证码
        if($this->request->input('phone')) {
            $user = User::where('phone', $this->request->input('phone'))->first();
            if (empty($user)) {
                return $this->failed(__('Phone').__('does not exist'));
            }
            //检测验证码
            $result = $this->checkSmsCode($user->phone,$this->request->input('code'));
            if($result['code'] != 200){
                return $this->failed($this->errStatus,$result['msg']);
            }
        }

        //如果发送邮箱验证码
        if($this->request->input('email')) {
            $user = User::where('email', $this->request->input('email'))->first();
            if (empty($user)) {
                return $this->failed(__('Email').__('does not exist'));
            }
            //检测验证码
            $result = $this->checkEmailCode($user->email,$this->request->input('code'));
            if($result['code'] != 200){
                return $this->failed($this->errStatus,$result['msg']);
            }
        }

        //如果没有找到用户
        if(empty($user)){
            return $this->failed(__('Missing phone number or email'));
        }

        $new_pass = bcrypt($password);
        $user->password = $new_pass;
        $user->save();

        return $this->success('', __('Set up successfully'));


    }

    public function createPaymentPassword()
    {
        $user_config = $this->request->getAttribute('user_config');
        if($user_config->payment_password_set === 1){
            return $this->failed(__('Payment password').__('Already set'));
        }

        $validator = $this->validationFactory->make(
            $this->request->all(),
            [
                'code' => 'required',
                'password' => 'required',
                'password_confirmation' => 'required'
            ],
            [],
            [
                'code' => __('Code'),
                'password' => __('Password'),
                'password_confirmation' => __('Password confirmation'),
            ]
        );
        if ($validator->fails()) {
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }

        $user = $this->request->getAttribute('user');

        $decrypt_password = $this->privateDecrypt($this->request->input('password'));

        if($decrypt_password['code'] != 200){
            return $this->failed($decrypt_password['msg']);
        }

        $decrypt_password_confirmation = $this->privateDecrypt($this->request->input('password_confirmation'));

        if($decrypt_password_confirmation['code'] != 200){
            return $this->failed($decrypt_password_confirmation['msg']);
        }

        $password = $decrypt_password['data'];
        $password_confirmation = $decrypt_password_confirmation['data'];

        if($password != $password_confirmation){
            return $this->failed(__('Passwords are inconsistent'));
        }

        //检测验证码
        $result1 = $this->checkSmsCode($user->phone,$this->request->input('code'));
        $result2 = $this->checkEmailCode($user->email,$this->request->input('code'));
        if($result1['code'] != 200 && $result2['code'] != 200){
            return $this->failed($this->errStatus,__('Code'));
        }

        $new_pass = Hash::make($password);
        $user->payment_password = $new_pass;
        $user->save();
        $user_config->payment_password_set = 1;
        $user_config->save();
        return $this->success('', __('Set up successfully'));

    }

    public function phoneBind()
    {
        $validator = $this->validationFactory->make(
            $this->request->all(),
            [
                'code' => 'required',
                'phone' => 'required',
            ],
            [],
            [
                'code' => __('Code'),
                'phone' => __('Phone'),
            ]
        );
        if ($validator->fails()) {
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }

        $user_config = $this->request->getAttribute('user_config');
        $user = $this->request->getAttribute('user');

        if ($user_config->phone_bind) {
            return $this->failed(__('Phone').__('Already bound'));
        }

        $post = $this->request->post();
        if (!isset($post['code'])) {
            return $this->failed(__('Code').__('Can not be empty'));
        }

        if (!isset($post['phone'])) {
            return $this->failed(__('Phone').__('Can not be empty'));
        }

        $phone = $post['phone'];
        $bind = User::where('phone',$phone)->first();
        if(!empty($bind)){
            return $this->failed( __('Phone').__('Already bound'));
        }

        // 验证验证码
        $result = $this->checkSmsCode($phone, $post['code']);
        if ($result['code'] == 200) {
            $user_config->phone_verify_at = now();
            $user_config->phone_bind = 1;
            $user_config->save();

            $user->phone = $phone;
            $user->save();

            if($user_config->email_bind && $user_config->phone_bind){
                $user_config->security_level += 1;
                $user_config->save();
            }

            return $this->success('', __('Bind successfully'));
        } else {
            return $this->failed($result['msg']);
        }
    }

    public function emailBind()
    {
        $user = $this->request->getAttribute('user');
        $user_config = $this->request->getAttribute('user_config');
        if ($user_config->email_bind) {
            return $this->failed(__('Email').__('Already bound'));
        }
        $post = $this->request->all();
        if (!isset($post['code'])) {
            return $this->failed(__('Code').__('Can not be empty'));
        }

        if (!isset($post['email'])) {
            return $this->failed(__('Email').__('Can not be empty'));
        }
        $email = $post['email'];
        $exists = User::where('email',$email)->first();
        if(!empty($exists)){
            return $this->failed(__('Email').__('Already bound'));
        }

        // 验证验证码
        $result = $this->checkEmailCode($email, $post['code']);
        if ($result['code'] == 200) {
            $user_config->email_verify_at = now();
            $user_config->email_bind = 1;
            $user_config->save();

            $user->email = $email;
            $user->save();

            if($user_config->email_bind && $user_config->phone_bind){
                $user_config->security_level += 1;
                $user_config->save();
            }
            return $this->success('', __('Bind successfully'));
        } else {
            return $this->failed($result['msg']);
        }
    }

    /**
     * 创建谷歌验证码
     * @return [type] [description]
     */
    public function createGoogleSecret()
    {
        $createSecret = $this->doCreateSecret();
        // 自定义参数，随表单返回
        $parameter = [];
        return $this->success(
            $this->successStatus,
            __('Get success'),
            ['createSecret' => $createSecret, "parameter" => $parameter]
        );
    }

    public function authenticatorBind()
    {
        $validator = $this->validationFactory->make(
            $this->request->all(),
            [
                'code' => 'required',
                'google_code' => 'required',
                'google_secret' => 'required',
            ],
            [],
            [
                'code' => __('Code'),
                'google_code' => __('keys.google_code'),
                'google_secret' => __('keys.google_secret'),
            ]
        );
        if ($validator->fails()) {
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }

        $user = $this->request->getAttribute('user');
        $user_config = $this->request->getAttribute('user_config');
        if ($user_config->google_bind) {
            return $this->failed(__('Already bound'));
        }

        $post = $this->request->all();
        if (empty($post['google_code']) && strlen($post['google_code']) != 6) {
            return $this->failed(__('messages.google_code_error'));
        }

        //检测验证码
        $result1 = $this->checkSmsCode($user->phone,$this->request->input('code'));
        $result2 = $this->checkEmailCode($user->email,$this->request->input('code'));
        if($result1['code'] != 200 && $result2['code'] != 200){
            return $this->failed(__('messages.google_code_error'));
        }

        $google = $post['google_secret'];
        // 验证验证码和密钥是否相同
        if ($this->CheckCode($google, $post['google_code'])) {
            $user_config->google_secret = $post['google_secret'];
            $user_config->google_bind = 1;
            $user_config->google_verify = 1;
            $user_config->save();
            return $this->success('', __('Bind successfully'));
        } else {
            return $this->failed(__('messages.google_code_error'));
        }
    }


    public function googleVerifyStart()
    {
        $user_config = $this->request->getAttribute('user_config');
        $user = $this->request->getAttribute('user');
        //验证是否绑定
        if ($user_config->google_bind == 0) {
            return $this->failed(__('Not yet bound').__('keys.google_code'));
        }

        // 验证验证码和密钥是否相同
        if (!$this->CheckCode($user_config->google_secret, $this->request->input('google_code'))) {
            return $this->failed(__('messages.google_code_error'));
        }

        if($this->request->input('key') == 'stop'){
            //检测验证码
            $result1 = $this->checkSmsCode($user->phone,$this->request->input('code'));
            $result2 = $this->checkEmailCode($user->email,$this->request->input('code'));
            if($result1['code'] != 200 && $result2['code'] != 200){
                return $this->failed($this->errStatus,__('Code'));
            }
        }

        //开启谷歌验证
        if ($this->request->input('key') == 'start') {
            $user_config->google_verify = 1;
            $user_config->save();
            return $this->success('', __('Open successfully'));
        }

        //关闭谷歌验证
        if ($this->request->input('key') == 'stop') {
            $user_config->google_verify = 0;
            $user_config->save();
            return $this->success('', __('Closed successfully'));
        }
    }


    public function recommends()
    {
        $user = $this->request->getAttribute('user');
        $recommends = User::where('recommend_id',$user->id)
            ->paginate(10);

        return $this->success('', __('success.get_success'),$recommends);
    }

    public function recommendInfo(){
        $user = $this->request->getAttribute('user');
        $data['recommends'] = User::where('recommend_id',$user->id)
            ->count();
        $assets = UserAssets::where('uid',$user->id)->first();
        $data['commission'] = $assets->total_commission;

        return $this->success(__('success.get_success'),$data);
    }

    public function test()
    {
        echo __('passwords.password');
    }




}