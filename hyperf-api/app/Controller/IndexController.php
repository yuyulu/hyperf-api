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
use App\Service\AutoConfirmationService;

class IndexController extends AbstractController
{
	/**
     * @Inject
     * @var AutoConfirmationService
     */
    protected $AutoConfirmationService;

    public function index()
    {
        // $params['trans_id'] = 23;
        // $params['type'] = 1;//1自动确认 2自动取消

        // $push = $this->AutoConfirmationService->push($params,3);
    }


}
