<?php

declare(strict_types=1);

if (! function_exists('format_price')) {
	function format_price($price,$code = 'usdt')
	{
	    $config = config('system.decimal_places');
	    if (isset($config[$code])) {
	        $fix = $config[$code];
	    } else {
	        $fix = 8;
	    }

	    return number_format($price, $fix, '.', '');

	}
}

if (! function_exists('name_format')) {
	function name_format($name)
	{
    $strlen = mb_strlen($name, 'utf-8');
    //如果字符串长度小于2，不做任何处理
    if ($strlen < 2) {
        return $name;
    } else {
        //mb_substr — 获取字符串的部分
        $firstStr = mb_substr($name, 0, 1, 'utf-8');
        $lastStr  = mb_substr($name, -1, 1, 'utf-8');
        //str_repeat — 重复一个字符串
        return $strlen == 2 ? $firstStr . str_repeat('*', mb_strlen($name, 'utf-8') - 1) : $firstStr . str_repeat("*", $strlen - 2) . $lastStr;
    }

	}
}
