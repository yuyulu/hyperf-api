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

use Hyperf\HttpServer\Router\Router;

Router::addRoute(['GET', 'POST', 'HEAD'], '/', 'App\Controller\IndexController@index');

Router::post('/user/login', 'App\Controller\Auth\LoginController@login');
Router::post('/user/register', 'App\Controller\Auth\RegisterController@register');

//用户管理 必须验证TOKEN
Router::addGroup('/user/', function () {
	Router::get('details','App\Controller\UserController@details');
	Router::get('loginHistory','App\Controller\UserController@loginHistory');
    Router::post('logout', 'App\Controller\Auth\LoginController@logout');
    Router::get('registerLink','App\Controller\UserController@registerLink');
    Router::post('updateAvatar','App\Controller\UserController@updateAvatar');
    Router::post('resetPassword','App\Controller\UserController@resetPassword');
    Router::post('resetPaymentPassword','App\Controller\UserController@resetPaymentPassword');
    Router::post('forgetPassword','App\Controller\UserController@forgetPassword');
    Router::post('createPaymentPassword','App\Controller\UserController@createPaymentPassword');
    Router::post('phoneBind','App\Controller\UserController@phoneBind');
    Router::post('emailBind','App\Controller\UserController@emailBind');
    Router::post('createGoogleSecret','App\Controller\UserController@createGoogleSecret');
    Router::post('authenticatorBind','App\Controller\UserController@authenticatorBind');
    Router::post('googleVerifyStart','App\Controller\UserController@googleVerifyStart');
    Router::get('recommends','App\Controller\UserController@recommends');
    
}, [
    'middleware' => [App\Middleware\JwtAuthMiddleware::class]
]);

//合约交易 必须验证TOKEN
Router::addGroup('/contract/', function () {
    Router::post('createOrder','App\Controller\ContractController@createOrder');
    Router::post('closePosition','App\Controller\ContractController@closePosition');
    Router::post('closePositionAll','App\Controller\ContractController@closePositionAll');
    Router::post('setProfitOrLoss','App\Controller\ContractController@setProfitOrLoss');
    Router::post('cancelOrder','App\Controller\ContractController@cancelOrder');
    Router::get('transData','App\Controller\ContractController@transData');
    Router::get('orderList','App\Controller\ContractController@orderList');
    Router::get('statistics','App\Controller\ContractController@statistics');
}, [
    'middleware' => [App\Middleware\JwtAuthMiddleware::class]
]);

//实名认证 必须验证TOKEN
Router::addGroup('/real-name/', function () {
    Router::post('primary','App\Controller\AuthenticationController@primaryCertification');
    Router::post('advanced','App\Controller\AuthenticationController@advancedCertification');
}, [
    'middleware' => [App\Middleware\JwtAuthMiddleware::class]
]);

//用户资产 必须验证TOKEN
Router::addGroup('/assets/', function () {
    Router::get('assetInfo','App\Controller\UserAssetsController@assetInfo');
    Router::get('userMoneyLog','App\Controller\UserAssetsController@userMoneyLog');
    Router::get('commissionDetails','App\Controller\UserAssetsController@commissionDetails');
}, [
    'middleware' => [App\Middleware\JwtAuthMiddleware::class]
]);

//网站信息 不验证TOKEN
Router::addGroup('/software/', function () {
    Router::get('content','App\Controller\SoftwareController@content');
    Router::get('systemInformation','App\Controller\SoftwareController@systemInformation');
    Router::get('systemPosts','App\Controller\SoftwareController@systemPosts');
});

//TOKEN可传可不传
Router::addGroup('/user/', function () {
	Router::post('sendEmail','App\Controller\UserController@sendEmail');
	Router::post('sendSms','App\Controller\UserController@sendSms');
}, [
    'middleware' => [App\Middleware\JwtNotMandatoryMiddleware::class]
]);

