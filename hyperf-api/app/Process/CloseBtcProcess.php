<?php
declare(strict_types=1);

namespace App\Process;

use Hyperf\DbConnection\Db;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\Annotation\Process;
use Hyperf\Contract\StdoutLoggerInterface;

/**
 * @Process(name="close_btc_process")
 */
class CloseBtcProcess extends AbstractProcess
{
    public function handle(): void
    {
        while (true) {

            $user = Db::table('users')->first();

            // var_dump($user->name);

            sleep(1);
        }
    }
}
