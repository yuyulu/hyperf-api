<?php
declare(strict_types=1);

namespace App\Process;

use Hyperf\DbConnection\Db;
use App\Traits\BurstProcess;
use Hyperf\Redis\RedisFactory;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\Annotation\Process;
use Hyperf\Contract\StdoutLoggerInterface;

/**
 * @Process(name="btc_burst_process")
 */
class BtcBurstProcess extends AbstractProcess
{
    use BurstProcess;

    public function handle(): void
    {
        $logger = $this->container->get(StdoutLoggerInterface::class);
        $redis = $this->container->get(RedisFactory::class)->get('price');
        while (true) {
            Db::table('user_positions')
            ->where('code', 'btc_usdt')
            ->select('id','uid')
            ->chunkById(100, function ($positions) use ($redis,$logger) {
                foreach ($positions as $position) {
                    if ($redis->exists($position->uid . ':bc_lock')) {
                        $logger->info('REDIS 已上锁 uid '.$position->uid);
                        $logger->info('REDIS 已上锁 id '.$position->id);
                        continue;
                    }

                    $bool = $this->compute($position->uid,$redis,$logger);

                    if ($bool) {
                        continue;
                    } else {
                        break;
                    }
                }
            });

            $logger->info('btc_burst_process success');

            sleep(1);
        }
    }
}
