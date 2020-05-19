<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class UserEntrusts extends Model
{
    protected $table = 'user_entrusts';
    protected $guarded = ['id'];
}
