<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class UserPositions extends Model
{
    protected $table = 'user_positions';
    protected $guarded = ['id'];
}
