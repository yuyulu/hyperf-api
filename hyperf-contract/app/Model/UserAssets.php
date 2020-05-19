<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class UserAssets extends Model
{
    protected $table = 'user_assets';

    protected $fillable = ['uid'];
}
