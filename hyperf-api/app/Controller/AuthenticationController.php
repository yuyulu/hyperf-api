<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\DbConnection\Db;
use App\Model\Authentication;

class AuthenticationController extends AbstractController
{
    public function primaryCertification()
    {
    	$validator = $this->validationFactory->make(
            $this->request->all(),
            [
                'name' => 'required|max:16',
                'card_id' => 'required|max:32',
            ],
            [],
            [
                'name' => __('keys.name'),
                'card_id' => __('keys.card_id'),
            ]
        );

        if ($validator->fails()){
            // Handle exception
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }

        $user = $this->request->getAttribute('user');
        $input = $this->request->all();
        $card = Db::table('authentications')
        ->where('card_id',$input['card_id'])
        ->where('status','>=',Authentication::PRIMARY_CHECK)
        ->first();

        if(!empty($card)){
            return $this->failed(__('messages.id_number_has_been_certified'));
        }

        $repeat = Db::table('authentications')
        ->where('uid',$user->id)
        ->where('status','>=',Authentication::PRIMARY_CHECK)
        ->first();

        if(!empty($repeat)){
            return $this->failed(__('messages.id_number_has_been_certified'));
        }

        Authentication::create([
        	'uid' => $user->id,
        	'name' => $input['name'],
        	'card_id' => $input['card_id'],
        ]);

        //初级认证成功 更新用户信息
        $user->authentication = Authentication::PRIMARY_CHECK;
        $user->name = $input['name'];
        $user->save();

        return $this->success('',__('success.real-name_authentication_succeeded'));
    }

    public function advancedCertification(\League\Flysystem\Filesystem $filesystem)
    {
    	$user = $this->request->getAttribute('user');

    	$card = Authentication::query()
    	->where('uid',$user->id)
        ->where('status','>=',Authentication::PRIMARY_CHECK)
        ->first();

        //如果没有初级认证
        if(empty($card)){
            return $this->failed(__('messages.preliminary_certification_first'));
        }

        //如果已经认证过了
        if($card->status == Authentication::ADVANCED_CHECK_AGREE){
            return $this->failed(__('messages.already_certified'));
        }

        //如果正在审核中
        if($card->status == Authentication::ADVANCED_WAIT_CHECK){
            return $this->failed(__('messages.under_review'));
        }

        //上传正面照
    	if ($this->request->hasFile('front_img')) {
			$front_img_upload = $this->request->file('front_img');
			$front_img_upload_result = $this->upload($front_img_upload, $filesystem);
		}
		if ($this->request->input('front_img')) {
			if (preg_match('/^(data:\s*image\/(\w+);base64,)/', $this->request->input('front_img'), $result)) {
				$front_img_upload_result = $this->base64Upload($this->request->input('front_img'), $filesystem);
			}
		}

		if (!isset($front_img_upload_result['code'])) {
			return $this->failed(__('messages.lack_of_front_img'));
		}

		if ($front_img_upload_result['code'] != 200) {
			return $this->failed($front_img_upload_result['msg']);
		}
		$front_img = $front_img_upload_result['data'];

        //上传反面照
		if ($this->request->hasFile('back_img')) {
			$back_img_upload = $this->request->file('back_img');
			$back_img_upload_result = $this->upload($back_img_upload, $filesystem);
		}

		if ($this->request->input('back_img')) {
			if (preg_match('/^(data:\s*image\/(\w+);base64,)/', $this->request->input('back_img'), $result)) {
				$back_img_upload_result = $this->base64Upload($this->request->input('back_img'), $filesystem);
			}
		}

		if (!isset($back_img_upload_result['code'])) {
			return $this->failed(__('messages.lack_of_back_img'));
		}

		if ($back_img_upload_result['code'] != 200) {
			return $this->failed($back_img_upload_result['msg']);
		}
		$back_img = $back_img_upload_result['data'];

		//上传手持照
		if ($this->request->hasFile('handheld_img')) {
			$handheld_img_upload = $this->request->file('handheld_img');
			$handheld_img_upload_result = $this->upload($handheld_img_upload, $filesystem);
		}

		if ($this->request->input('handheld_img')) {
			if (preg_match('/^(data:\s*image\/(\w+);base64,)/', $this->request->input('handheld_img'), $result)) {
				$handheld_img_upload_result = $this->base64Upload($this->request->input('handheld_img'), $filesystem);
			}
		}

		if (!isset($handheld_img_upload_result['code'])) {
			return $this->failed(__('messages.lack_of_handheld_img'));
		}

		if ($handheld_img_upload_result['code'] != 200) {
			return $this->failed($handheld_img_upload_result['msg']);
		}
		$handheld_img = $handheld_img_upload_result['data'];

		//更新认证表和会员表状态
        $card->status = Authentication::ADVANCED_WAIT_CHECK;
        $card->front_img = $front_img;
        $card->back_img = $back_img;
        $card->handheld_img = $handheld_img;
        $card->save();

        $user->authentication = Authentication::ADVANCED_WAIT_CHECK;
        $user->save();

        return $this->success('',__('messages.successful_submission_pending'));
    }
}
