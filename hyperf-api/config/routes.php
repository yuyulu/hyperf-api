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
    Router::post('logout', 'App\Controller\Auth\LoginController@logout');
    Router::get('registerLink','App\Controller\UserController@registerLink');
    
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

