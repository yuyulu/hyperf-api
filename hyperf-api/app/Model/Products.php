<?php

declare(strict_types=1);

namespace App\Model;

use Hyperf\DbConnection\Model\Model;

class Products extends Model
{
	const HIDE_TYPE = 0; // state 不显示
    const DIS_TYPE  = 1; // state 显示
    
    protected $table = 'products';
    protected $primaryKey = 'pid';
    public $timestamps = false;

}
