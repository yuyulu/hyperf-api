<?php

declare(strict_types=1);

namespace App\Service;

use App\Job\AutoConfirmationJob;
use Hyperf\AsyncQueue\Driver\DriverFactory;
use Hyperf\AsyncQueue\Driver\DriverInterface;

class AutoConfirmationService
{
    /**
     * @var DriverInterface
     */
    protected $driver;

    public function __construct(DriverFactory $driverFactory)
    {
        $this->driver = $driverFactory->get('default');
    }

    /**
     * 生产消息.
     * @param $params 数据
     * @param int $delay 延时时间 单位秒
     */
    public function push($params, int $delay = 0): bool
    {
        // 这里的 `ExampleJob` 会被序列化存到 Redis 中，所以内部变量最好只传入普通数据
        // 同理，如果内部使用了注解 @Value 会把对应对象一起序列化，导致消息体变大。
        // 所以这里也不推荐使用 `make` 方法来创建 `Job` 对象。
        return $this->driver->push(new AutoConfirmationJob($params), $delay);
    }
}
