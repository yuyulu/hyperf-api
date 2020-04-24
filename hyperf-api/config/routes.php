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

//必须验证TOKEN
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

//TOKEN可传可不传
Router::addGroup('/user/', function () {
	Router::post('sendEmail','App\Controller\UserController@sendEmail');
	Router::post('sendSms','App\Controller\UserController@sendSms');
}, [
    'middleware' => [App\Middleware\JwtNotMandatoryMiddleware::class]
]);

