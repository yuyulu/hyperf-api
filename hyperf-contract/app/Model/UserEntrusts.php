<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class UserEntrusts extends Model
{
    protected $table = 'user_entrusts';

    public function createSN()
    {
        return 'ENNUM' . date('YmdHis') . $this->id . mt_rand(1000, 9999);
    }
}
