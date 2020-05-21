<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Fbappeal extends Model
{
    protected $table = 'fb_appeal';
    protected $guarded = ['id'];

}
