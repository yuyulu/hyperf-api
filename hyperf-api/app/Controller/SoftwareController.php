<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\DbConnection\Db;

class SoftwareController
{
	/**
	 * 网站名称、客服、联系方式等
	 * @return [type] [description]
	 */
    public function content()
    {
    	$key = $this->request->input('key');
    	$content = Db::table('admin_config')
    	->where('name','site.'.$key)
    	->value('value');

    	return $this->success($content,__('success.get_success'));
    }

    /**
     * 平台资讯
     * @return [type] [description]
     */
    public function systemInformation()
    {
    	$page = $this->request->input('page', 1);
    	$locale = $this->translator->getLocale();

    	$total_size = Db::table('xy_blocks_msg')
    	->where('lang',$locale)->count();

    	$blocks = Db::table('xy_blocks_msg')
    	->where('lang',$locale)
        ->select('id','bm_title as title','pic_addr','content','issue_time as created_at')
        ->orderBy('created_at','desc')
        ->offset(($page - 1) * 10)
		->limit(10)
		->get();

		$return['total_size'] = $total_size;
        $return['total_page'] = ceil($total_size / 10);
        $return['blocks'] = $blocks;

        return $this->success($return, __('success.get_success'));
    }

    /**
     * 平台公告
     * @return [type] [description]
     */
    public function systemPosts()
    {
    	$page = $this->request->input('page', 1);
    	$type = $this->request->input('type', '');
    	$locale = $this->translator->getLocale();

    	$total_size = Db::table('system_posts')
    	->where('type',$type)
        ->where('locale',$locale)
    	->count();

    	$posts = Db::table('system_posts')
    	->where('type',$type)
        ->where('locale',$locale)
        ->orderBy('created_at','desc')
        ->offset(($page - 1) * 10)
		->limit(10)
        ->get();

        $return['total_size'] = $total_size;
        $return['total_page'] = ceil($total_size / 10);
        $return['posts'] = $posts;

        return $this->success($return, __('success.get_success'));
    }

    public function postsInfo(){
    	$type = $this->request->input('type', 1);
    	$posts_id = $this->request->input('posts_id', '');

    	if ($type == 2) {
    		$content = Db::table('xy_blocks_msg')
    		->where('id',$posts_id)
    		->select('id','bm_title as title','pic_addr','content','issue_time as created_at')
    		->first();
    	} else {
    		$content = Db::table('system_posts')->where('id',$posts_id)
    		->first();
    	}

        return $this->success($content, __('success.get_success'));
    }

    public function slides(){
    	$locale = $this->translator->getLocale();
    	$position = $this->request->input('position', '');

        $slides = Db::table('slides')
        ->where('position',$position)
        ->where('locale',$locale)
        ->get();
        return $this->success($slides, __('success.get_success'));
    }

    public function systemAgree()
    {
    	$type = $this->request->input('type', '');
    	$locale = $this->translator->getLocale();
        $content = SystemAgree::where('type',$type)
            ->where('locale',$locale)
            ->first();
        return $this->success($content, __('success.get_success'));
    }

    /**
     * 下载链接
     */
    public function downloadLink(){
        $config = DB::table('admin_config')
            ->where('name','site.software_link')
            ->first();
        $link = $config->value;
        $qrcode = QrCode::encoding('UTF-8')->format('png')->size(368)->margin(0)
            ->generate($link);
        $data['qrcode'] = 'data:image/png;base64,' . base64_encode($qrcode);
        $data['link'] = $link;
        $data['update_at'] = $config->updated_at;
        return __return($this->successStatus,'获取成功',$data);
    }

    public function softwareUpdate(){
    	$clientVersion = $this->request->input('version', '');
    	$type = $this->request->input('type', '');

        if (!in_array($type, array(1, 2))) {
            return $this->failed(__('messages.update_range_error'));
        }
        $version = Db::table('software_versions')
        ->where('type', $type)
        ->orderBy('id', 'desc')
        ->first();

        if(is_null($version)){
            return $this->failed(__('messages.version_is_the_latest'));
        }
        if ($version->vercode != $clientVersion) {
            return $this->success($version,__('messages.new_version'));
        } else {
            return $this->failed(__('messages.version_is_the_latest'));
        }
    }
}
