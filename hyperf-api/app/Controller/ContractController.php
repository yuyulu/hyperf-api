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

use Hyperf\Validation\Rule;
use Hyperf\DbConnection\Db;
use Hyperf\Di\Annotation\Inject;
use App\JsonRpc\ContractService;
use App\Middleware\JwtAuthMiddleware;
use Hyperf\HttpServer\Annotation\Middleware;
use Hyperf\HttpServer\Annotation\Middlewares;
use Hyperf\HttpServer\Annotation\AutoController;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;

/**
 * @AutoController()
 */
class ContractController extends AbstractController
{
    /**
     * @Inject()
     * @var ValidatorFactoryInterface
     */
    protected $validationFactory;

	/**
     * @Middlewares({
     *     @Middleware(JwtAuthMiddleware::class)
     * })
     */
    public function createOrder(ContractService $contract)
    {
        // 交易状态 1为开放 2为关闭
        $status = Db::table('admin_config')
        ->where('name','trans.status')
        ->value('value');

        if ($status == 2) {
            return $this->failed(__('messages.service_maintenance'));
        }

        $method = $this->request->getMethod();

        if (!$this->request->isMethod('post')) {
            return $this->failed(__('messages.method_not_allowed'));
        }

        $validator = $this->validationFactory->make(
            $this->request->all(),
            [
                'buynum' => 'required|numeric|min:0.1',
                'type' => ['required',Rule::in([1, 2])],//1市价 2限价
                'otype' => ['required',Rule::in([1, 2])],//1买涨 2买跌
                'code' => 'required|string',
                'leverage' => 'required|integer|min:1',//杠杆
            ],
            [],
            [
                'buynum' => __('keys.buynum'),
                'type' => __('keys.type'),
                'otype' => __('keys.otype'),
                'code' => __('keys.code'),
                'leverage' => __('keys.leverage'),
            ]
        );

        if ($validator->fails()){
            // Handle exception
            $errorMessage = $validator->errors()->first();
            return $this->failed($errorMessage);
        }

        $input = $this->request->all();

    	try {
			return $contract->createOrder($input);
    	} catch (\Throwable $throwable) {
    		return $this->failed($throwable->getMessage());
    	}
        
    }

}