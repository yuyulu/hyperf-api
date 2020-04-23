<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class UserConfig extends Model
{
    protected $table = 'user_config';

    protected $fillable = ['uid'];

}
