<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class UserPositions extends Model
{
    protected $table = 'user_positions';

    public function createSN()
    {
        return date('YmdHis') . $this->id . mt_rand(1000, 9999);
    }
}
