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

use Hyperf\Di\Annotation\Inject;
use Psr\Container\ContainerInterface;
use Hyperf\Contract\TranslatorInterface;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;

abstract class AbstractController
{
    /**
     * @Inject
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @Inject
     * @var RequestInterface
     */
    protected $request;

    /**
     * @Inject
     * @var ResponseInterface
     */
    protected $response;

     /**
     * @Inject
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @Inject()
     * @var ValidatorFactoryInterface
     */
    protected $validationFactory;

    public function success($data, $message = 'success')
    {
        $code = $this->response->getStatusCode();
        return ['msg' => $message, 'code' => $code, 'data' => $data];
    }
    
    public function failed($message = 'Request format error!')
    {
        return ['msg' => $message, 'code' => 500, 'data' => ''];
    }

    public function upload($file,$filesystem) {
        // 1.是否上传成功
        if (! $file->isValid()) {
            return ['code' => 500,'msg' => __('failed.upload_failed')];
        }


        // 2.是否符合文件类型 getClientOriginalExtension 获得文件后缀名
        $fileExtension = $file->getExtension();
        if(! in_array($fileExtension, ['png','PNG', 'jpg','JPG', 'gif','GIF','JPEG','jpeg'])) {
            return ['code' => 500,'msg' => __('messages.file_format_is_incorrect')];
        }

        // 3.判断大小是否符合 2M
        $tmpFile = $file->getRealPath();
        if (filesize($tmpFile) >= 2048000) {
            return ['code' => 500,'msg' => __('messages.file_larger_than').'2048000Bytes'];
        }

        // 5.每天一个文件夹,分开存储, 生成一个随机文件名
        $fileName = 'images/'.date('Y_m_d').'/'.md5($tmpFile) .mt_rand(0,9999).'.'. $fileExtension;

        // Process Upload
        $file = $this->request->file('upload');
        $stream = fopen($tmpFile, 'r+');
        $filesystem->writeStream(
            $fileName,
            $stream
        );

        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($filesystem->has($fileName)) {
            return ['code' => 200,'data' => $fileName];
        } else {
            return ['code' => 500,'msg' => __('falied.upload_failed')];
        }

    }

    public function base64Upload($base64_img, $filesystem){
        if(preg_match('/^(data:\s*image\/(\w+);base64,)/', $base64_img, $result)){
            $type = $result[2];
            if(!in_array($type,array('pjpeg','jpeg','jpg','gif','bmp','png'))){
                return ['code' => 500,'msg' => __('messages.file_format_is_incorrect')];
            }
            $fileName = 'images/'.date('Y_m_d').'/'.md5(time()) .mt_rand(0,9999).'.'. $type;

            // Process Upload
            $filesystem->writeStream(
                $fileName,
                base64_decode(str_replace($result[1], '', $base64_img))
            );
            fclose($stream);

            if ($filesystem->has($fileName)) {
                return ['code' => 200,'data' => $fileName];
            } else {
                return ['code' => 500,'msg' => __('falied.upload_failed')];
            }

        } else {
            return ['code' => 500,'msg' => __('falied.upload_failed')];
        }
    }

    public function privateDecrypt($password){

        return ['code' => 200,'data' => $password];
        try{
            $private_key = config('system.RSA.RSA_PRIVATE_KEY');

            $encrypt_data = base64_decode($password);

            openssl_private_decrypt($encrypt_data, $decrypt_data, $private_key);

            if(!$decrypt_data){
                return ['code' => 500,'msg' => __('messages.password_resolution_failed')];
            }

            return ['code' => 200,'data' => $decrypt_data];
        }catch (\Throwable $throwable){
            return ['code' => 500,'msg' => __('messages.password_resolution_failed')];
        }

    }


}
