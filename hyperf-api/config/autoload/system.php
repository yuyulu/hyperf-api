<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://doc.hyperf.io
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf-cloud/hyperf/blob/master/LICENSE
 */

return [
    'RSA' => [
        'RSA_PRIVATE_KEY' => 'PRIVATE_KEY',
    ],
    'decimal_places' => [
    	'btc_usdt' => 2,
        'eth_usdt' => 2,
        'xrp_usdt' => 5,
        'ltc_usdt' => 2,
        'bch_usdt' => 2,
        'eos_usdt' => 4,
        'etc_usdt' => 4,
        'trx_usdt' => 6,
        'usdt'     => 8
    ],
    'user_money_log_type' => [
    	1 => '合约交易手续费',
        2 => '合约交易扣款',
        3 => '合约交易撤单',
        4 => '平仓手续费',
        5 => '平仓返还',
        6 => '推荐返佣',
        7 => '利息',
        8 => '用户提币手续费',
        9 => '用户提币',
        10 => '用户提币手续费退回',
        11 => '提币金额退回',
        12 => '客户充值',
        13 => '成为商家-扣除金额',
        14 => '成为商家-增加冻结',
        15 => '交易出售发单-扣除余额',
        16 => '交易出售发单-手续费',
        17 => '出售下单撤单-增加余额',
        18 => '出售下单-减少余额',
        19 => '出售下单-手续费',
        20 => '交易购买-增加余额',
        21 => '系统自动确认-增加余额',
        22 => '系统自动取消-增加余额',
    ],

];