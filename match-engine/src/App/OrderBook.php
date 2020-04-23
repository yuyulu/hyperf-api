<?php

namespace MatchEngine;

class OrderBook
{
    private $orderRedis;
    private $precision = 4;    //数量的小数位数

    private $decimal = 1e8; //扩大float

    public function __construct($host, $port)
    {
        $this->orderRedis = new OrderRedis($host, $port);
    }

    /**
     * 订单处理
     * @param array $order 订单数据[order_id,user_id,market,price,quantity,side,type]
     * @return array|string
     */
    public function processOrder($order)
    {
        $order['price'] = intval($order['price'] * $this->decimal);
        if ($order['type'] == 'limit') {//限价单
            if (!$this->limitDataCheck($order)) {//数据校验
                return 'Invalid order';
            }
            $info = $this->processLimitOrder($order);
        } else {//市价单
            if (!$this->marketDataCheck($order)) {//数据校验
                return 'Invalid order';
            }
            $info = $this->processMarketOrder($order);
        }
        return $info;
    }

    /**
     * 盘口数据
     * @param string $type 订单类型
     * @param string $market 交易市场（交易对）
     * @param int $slice 盘口条数
     * @return array
     */
    public function getHandicap(string $market, int $slice = 10, string $type = 'limit')
    {
        $handicap = ['asks' => [], 'bids' => []];
        $bidArr = [];
        $askArr = [];
        $bidlist = $this->orderRedis->getOrderBooks($market, $type, 'bid');

        $bidlist = array_slice(array_reverse($bidlist, true), 0, $slice, true);

        // price => quantity
        foreach ($bidlist as $order_id => $price) {
            if (!isset($bidArr["$price"])) {
                $bidArr["$price"] = 0;
            }
            $order = $this->orderRedis->getOrder($order_id);
            $bidArr["$price"] += $order['quantity'];
        }
        //算出一个总价
        foreach ($bidArr as $key => $value) {
            $handicap['bids'][] = ['price' => $key / $this->decimal, 'totalSize' => $value, 'totalPrice' => $key * $value];
        }

        $asklist = $this->orderRedis->getOrderBooks($market, $type, 'ask');
        $asklist = array_slice($asklist, 0, $slice, true);

        foreach ($asklist as $key => $value) {
            if (!isset($askArr["$value"])) {
                $askArr["$value"] = 0;
            }
            $order = $this->orderRedis->getOrder($key);
            $askArr["$value"] += $order['quantity'];
        }
        foreach ($askArr as $key => $value) {
            $handicap['asks'][] = ['price' => $key / $this->decimal, 'totalSize' => $value, 'totalPrice' => $key * $value];
        }
        return $handicap;
    }

    /**
     * 清空redis所有数据
     * @param $db
     * @return string
     */
    public function empty($db)
    {
        $info = $this->orderRedis->cleanAll($db);
        if ($info) {
            return 'empty all data success';
        } else {
            return 'empty fail';
        }
    }

    /**
     * 取消订单
     * @param string $market 交易市场（交易对）
     * @param string $type 订单类型[limit,market]
     * @param string $side [买单bid,卖单ask]
     * @param $order_id
     * @return string
     */
    public function removeOrder(string $market, string $type, string $side, $order_id)
    {

        $orderInfo = $this->orderRedis->getOrder($order_id);
        if (!$orderInfo) {
            return 'orderInfo fail';
        }
//        if ($type == 'limit') {
        $info = $this->orderRedis->removeOrderBook($market, $type, $side, $order_id);//从盘口删除
//        }
        $info = $this->orderRedis->removeOrder($order_id);//从订单列表删除
        if ($info) {
            return 'success';
        } else {
            return 'fail';
        }
    }

    /**
     * 限价订单处理
     * @param array $order 订单数据[order_id,user_id,market,price,quantity,side,type,status,match_id]
     * @return array
     */
    private function processLimitOrder(array $order)
    {
        $updateArr = [];    //数量发送变化的订单
        $matchArr = [];    //撮合成功的订单
        $newArr = [];        //新增的订单
        $rstArr = [];

        $rawOrder = $order; //保留一个原始订单信息
//        echo 'process order：' . $order['order_id'] . PHP_EOL;
        if ($order['side'] == 'ask') {    //处理卖单
            //查找买盘是价格大于等于本价格的订单,价格降序排列
            $orderArea = $this->orderRedis->getPriceArea($order['market'], $order['type'], 'bid', $order['price'], 9999999 * 1e8);
            //array(2) { ["A100004"]=> float(101) ["A100003"]=> float(102) }
            if (count($orderArea) > 0) {    //有能撮合的订单->撮合
                $orderArea = array_reverse($orderArea, true);//数组反转
                foreach ($orderArea as $key => $value) {
                    $orderInfo = $this->orderRedis->getOrder($key); //根据orderid查找订单详情
                    if (!$orderInfo) {
                        echo 'order_id 不存在' . $key . PHP_EOL;
//                        $removeRes = $this->orderRedis->removeOrderBook($order['market'], 'limit', 'bid', $key);
                        continue;
                    }
                    if (round($order['quantity'], $this->precision) <= round($orderInfo['quantity'], $this->precision)) {    //本单可售数量充足
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'quantity', round($orderInfo['quantity'] - $order['quantity'], $this->precision));//修改本单可售数量
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'match_id', $orderInfo['match_id'] . $order['order_id'] . ',');//修改本单撮合的id
                        $sellout = 0;
                        if ($operaRes && round($order['quantity'], $this->precision) == round($orderInfo['quantity'], $this->precision)) {//若可售数量为0，从买盘撤单
                            $removeRes = $this->orderRedis->removeOrderBook($orderInfo['market'], 'limit', 'bid', $orderInfo['order_id']);
                            $removeKey = $this->orderRedis->removeOrder($orderInfo['order_id']);#删除 redis key
                            $sellout = 1;
                        }

                        //数量发生变化的订单
                        $updateOrder = [
                            'order_id' => $key,
                            'quantity' => round($order['quantity'], $this->precision),
                            'sellout' => $sellout
                        ];
                        $updateArr[] = $updateOrder;
                        //撮合成功的订单
                        $matchOrder = [
                            'sell_id' => $order['user_id'],    //卖家id
                            'buy_id' => $orderInfo['user_id'],//买家id
                            'price' => $orderInfo['price'],
                            'quantity' => round($order['quantity'], $this->precision),
                            'side' => 'ask',    //卖出
                            'market' => $order['market'],
                            'sell_order' => $order['order_id'],//卖单id
                            'buy_order' => $orderInfo['order_id'],//买单id
                        ];
                        $matchArr[] = $matchOrder;
                        $order['quantity'] = 0;//数量清0
                        break;
                    } else {    //本单可售数量不足
                        $order['quantity'] = round($order['quantity'] - $orderInfo['quantity'], $this->precision);//修改传入订单数量
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'quantity', 0);//修改本单可售数量为0
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'match_id', $orderInfo['match_id'] . $order['order_id'] . ',');//修改本单撮合的id
                        $removeRes = $this->orderRedis->removeOrderBook($orderInfo['market'], 'limit', 'bid', $orderInfo['order_id']);//将从买盘撤单
                        $removeKey = $this->orderRedis->removeOrder($orderInfo['order_id']);#删除 redis key

                        //数量发生变化的订单
                        $updateOrder = [
                            'order_id' => $key,
                            'quantity' => round($orderInfo['quantity'], $this->precision),
                            'sellout' => 1
                        ];
                        $updateArr[] = $updateOrder;
                        //撮合成功的订单
                        $matchOrder = [
                            'sell_id' => $order['user_id'],
                            'buy_id' => $orderInfo['user_id'],
                            'price' => $orderInfo['price'],
                            'quantity' => round($orderInfo['quantity'], $this->precision),
                            'side' => 'ask',    //卖出
                            'market' => $order['market'],
                            'sell_order' => $order['order_id'],    //卖单id
                            'buy_order' => $orderInfo['order_id'],    //买单id
                        ];
                        $matchArr[] = $matchOrder;
                        continue;    //继续撮合直到传入订单完成或没有撮合的买单
                    }
                }

                if ($order['quantity'] > 0) { //如果传入订单还有未撮合的数量->剩下的数量直接生成卖盘单
                    $newOrder = $this->orderRedis->newOrder($order['order_id'], $order);
                    //新生成订单
                    $newOrder = [
                        'order_id' => $order['order_id'],
                        'type' => $order['type'],
                        'side' => $order['side'],
                        'market' => $order['market'],
                        'quantity' => round($order['quantity'], $this->precision),
                        'price' => $order['price'],
                        'user_id' => $order['user_id']
                    ];
                    $newArr[] = $newOrder;


                    //数量发生变化的订单
                    $updateArr[] = [
                        'order_id' => $order['order_id'],
                        'quantity' => round($rawOrder['quantity'] - $order['quantity'], $this->precision),
                        'sellout' => 0
                    ];

                } else {
                    #撮合完了

                    //数量发生变化的订单
                    $updateArr[] = [
                        'order_id' => $order['order_id'],
                        'quantity' => round($rawOrder['quantity'], $this->precision),
                        'sellout' => 1
                    ];


                    // $order['avg_price'] = 0;
                    // $rstArr[]=$order;
                    $this->orderRedis->removeOrder($order['order_id']);
                }

            } else {    //没有可以撮合的订单->直接生成卖盘单
                $newOrder = $this->orderRedis->newOrder($order['order_id'], $order);
                //新生成订单
                $newOrder = [
                    'order_id' => $order['order_id'],
                    'type' => $order['type'],
                    'side' => $order['side'],
                    'market' => $order['market'],
                    'quantity' => round($order['quantity'], $this->precision),
                    'price' => $order['price'],
                    'user_id' => $order['user_id']
                ];
                $newArr[] = $newOrder;
            }
        } else {    //处理买单
            //查找卖盘是价格小于等于本价格的订单,价格升序排列
            $orderArea = $this->orderRedis->getPriceArea($order['market'], $order['type'], 'ask', 0, $order['price']);
            //array(2) { ["A100004"]=> float(101) ["A100003"]=> float(102) }
            if (count($orderArea) > 0) {    //有能撮合的订单->撮合
                foreach ($orderArea as $key => $value) {
                    $orderInfo = $this->orderRedis->getOrder($key); //根据orderid查找订单详情
                    if (!$orderInfo) {
                        echo 'order_id 不存在 ' . $key . PHP_EOL;
//                        $removeRes = $this->orderRedis->removeOrderBook($order['market'], 'limit', 'ask', $key);
                        continue;
                    }
                    if (round($order['quantity'], $this->precision) <= round($orderInfo['quantity'], $this->precision)) {    //本单可售数量充足
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'quantity', round($orderInfo['quantity'] - $order['quantity'], $this->precision));//修改本单可售数量
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'match_id', $orderInfo['match_id'] . $order['order_id'] . ',');//修改本单撮合的id
                        $sellout = 0;
                        if ($operaRes && round($order['quantity'], $this->precision) == round($orderInfo['quantity'], $this->precision)) {//若可售数量为0，从卖盘撤单
                            $removeRes = $this->orderRedis->removeOrderBook($orderInfo['market'], 'limit', 'ask', $orderInfo['order_id']);
                            $removeKey = $this->orderRedis->removeOrder($orderInfo['order_id']);#删除 redis key
                            $sellout = 1;
                        }
                        //数量发生变化的订单
                        $updateOrder = [
                            'order_id' => $key,
                            'quantity' => round($order['quantity'], $this->precision),
                            'sellout' => $sellout
                        ];
                        $updateArr[] = $updateOrder;
                        //撮合成功的订单
                        $matchOrder = [
                            'sell_id' => $orderInfo['user_id'],
                            'buy_id' => $order['user_id'],
                            'price' => $orderInfo['price'],
                            'quantity' => round($order['quantity'], $this->precision),
                            'side' => 'bid',    //买入
                            'market' => $order['market'],
                            'sell_order' => $orderInfo['order_id'],//卖单id
                            'buy_order' => $order['order_id'],//买单id
                        ];
                        $matchArr[] = $matchOrder;
                        $order['quantity'] = 0;//数量清0
                        break;
                    } else {    //本单可售数量不足
                        $order['quantity'] = round($order['quantity'] - $orderInfo['quantity'], $this->precision);//修改传入订单数量
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'quantity', 0);//修改本单可售数量为0
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'match_id', $orderInfo['match_id'] . $order['order_id'] . ',');//修改本单撮合的id
                        $removeRes = $this->orderRedis->removeOrderBook($orderInfo['market'], 'limit', 'ask', $orderInfo['order_id']);//将从卖盘撤单
                        $removeKey = $this->orderRedis->removeOrder($orderInfo['order_id']);#删除 redis key

                        //数量发送变化的订单
                        $updateOrder = [
                            'order_id' => $key,
                            'quantity' => round($orderInfo['quantity'], $this->precision),
                            'sellout' => 1
                        ];
                        $updateArr[] = $updateOrder;
                        //撮合成功的订单
                        $matchOrder = [
                            'sell_id' => $orderInfo['user_id'],
                            'buy_id' => $order['user_id'],
                            'price' => $orderInfo['price'],
                            'quantity' => round($orderInfo['quantity'], $this->precision),
                            'side' => 'bid',    //买入
                            'market' => $order['market'],
                            'sell_order' => $orderInfo['order_id'],//卖单id
                            'buy_order' => $order['order_id'],//买单id
                        ];
                        $matchArr[] = $matchOrder;
                        continue;    //继续撮合直到传入订单完成或没有撮合的卖单
                    }
                }

                if ($order['quantity'] != 0) { //如果传入订单还有未撮合的数量->剩下的数量直接生成买盘单
                    $newOrder = $this->orderRedis->newOrder($order['order_id'], $order);
                    //新生成订单
                    $newOrder = [
                        'order_id' => $order['order_id'],
                        'type' => $order['type'],
                        'side' => $order['side'],
                        'market' => $order['market'],
                        'quantity' => round($order['quantity'], $this->precision),
                        'price' => $order['price'],
                        'user_id' => $order['user_id']
                    ];
                    $newArr[] = $newOrder;

                    $updateArr[] = [
                        'order_id' => $order['order_id'],
                        'quantity' => round($rawOrder['quantity'] - $order['quantity'], $this->precision),
                        'sellout' => 0
                    ];

                } else {
                    #撮合完了   这里涉及

                    $updateArr[] = [
                        'order_id' => $order['order_id'],
                        'quantity' => round($rawOrder['quantity'], $this->precision),
                        'sellout' => 1
                    ];


                    // $order['avg_price'] = 0;
                    // $rstArr[]=$order;
                    $this->orderRedis->removeOrder($order['order_id']);
                }


            } else {    //没有可以撮合的订单->直接生成买盘单
//                echo 'no match', PHP_EOL;
                $newOrder = $this->orderRedis->newOrder($order['order_id'], $order);
                //新生成订单
                $newOrder = [
                    'order_id' => $order['order_id'],
                    'type' => $order['type'],
                    'side' => $order['side'],
                    'market' => $order['market'],
                    'quantity' => round($order['quantity'], $this->precision),
                    'price' => $order['price'],
                    'user_id' => $order['user_id']
                ];
                $newArr[] = $newOrder;
            }
        }
        return ['updateArr' => $updateArr, 'matchArr' => $matchArr, 'newArr' => $newArr, 'rstArr' => $rstArr];
    }

    /**
     * 市价订单处理
     * @param array $order 订单数据[order_id,user_id,market,price,quantity,side,type]
     * @return array
     */
    private function processMarketOrder(array $order)
    {
        $updateArr = [];    //数量发送变化的订单
        $matchArr = [];    //撮合成功的订单
        $newArr = [];        //新增的订单
        $rstArr = [];
        $rawOrder = $order; //保留一个原始订单信息
        echo 'process order：' . $order['order_id'] . PHP_EOL;
        if ($order['side'] == 'ask') {
            //处理市价卖
            $orderArea = $this->orderRedis->getOrderBooks($order['market'], 'limit', 'bid');
            if (count($orderArea) > 0) {
                foreach ($orderArea as $key => $value) {
                    $orderInfo = $this->orderRedis->getOrder($key); //根据orderid查找订单详情
                    if (!$orderInfo) {
                        echo 'order_id 不存在 ' . $key . PHP_EOL;
//                        $removeRes = $this->orderRedis->removeOrderBook($order['market'], 'limit', 'bid', $key);
                        continue;
                    }

                    if (round($order['quantity'], $this->precision) <= round($orderInfo['quantity'], $this->precision)) {
                        //本单可售数量充足
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'quantity', round($orderInfo['quantity'] - $order['quantity'], $this->precision));//修改本单可售数量
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'match_id', $orderInfo['match_id'] . $order['order_id'] . ',');//修改本单撮合的id
                        $sellout = 0;
                        if ($operaRes && round($order['quantity'], $this->precision) == round($orderInfo['quantity'], $this->precision)) {//若可售数量为0，从买盘撤单
                            $removeRes = $this->orderRedis->removeOrderBook($orderInfo['market'], 'limit', 'bid', $orderInfo['order_id']);
                            $removeKey = $this->orderRedis->removeOrder($orderInfo['order_id']);#删除 redis key
                            $sellout = 1;
                        }

                        //数量发生变化的订单
                        $updateOrder = [
                            'order_id' => $key,
                            'quantity' => round($order['quantity'], $this->precision),
                            'sellout' => $sellout
                        ];
                        $updateArr[] = $updateOrder;
                        //撮合成功的订单
                        $matchOrder = [
                            'sell_id' => $order['user_id'],    //卖家id
                            'buy_id' => $orderInfo['user_id'],//买家id
                            'price' => $orderInfo['price'],
                            'quantity' => round($order['quantity'], $this->precision),
                            'side' => 'ask',    //卖出
                            'market' => $order['market'],
                            'sell_order' => $order['order_id'],//卖单id
                            'buy_order' => $orderInfo['order_id'],//买单id
                        ];
                        $matchArr[] = $matchOrder;
                        $order['quantity'] = 0;//数量清0
                        break;
                    } else {
                        //本单可售数量不足
                        $order['quantity'] = round($order['quantity'] - $orderInfo['quantity'], $this->precision);//修改传入订单数量
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'quantity', 0);//修改本单可售数量为0
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'match_id', $orderInfo['match_id'] . $order['order_id'] . ',');//修改本单撮合的id
                        $removeRes = $this->orderRedis->removeOrderBook($orderInfo['market'], 'limit', 'bid', $orderInfo['order_id']);//将从买盘撤单
                        $removeKey = $this->orderRedis->removeOrder($orderInfo['order_id']);#删除 redis key

                        //数量发生变化的订单
                        $updateOrder = [
                            'order_id' => $key,
                            'quantity' => round($orderInfo['quantity'], $this->precision),
                            'sellout' => 1
                        ];
                        $updateArr[] = $updateOrder;
                        //撮合成功的订单
                        $matchOrder = [
                            'sell_id' => $order['user_id'],
                            'buy_id' => $orderInfo['user_id'],
                            'price' => $orderInfo['price'],
                            'quantity' => round($orderInfo['quantity'], $this->precision),
                            'side' => 'ask',    //卖出
                            'market' => $order['market'],
                            'sell_order' => $order['order_id'],    //卖单id
                            'buy_order' => $orderInfo['order_id'],    //买单id
                        ];
                        $matchArr[] = $matchOrder;
                        continue;    //继续撮合直到传入订单完成或没有撮合的买单
                    }
                }

                if ($order['quantity'] > 0) {
                    //如果传入订单还有未撮合的数量->剩下的数量直接生成卖盘单
                    //TODO一般不允许这种情况出现
                    echo '传入订单还有未撮合的数量', PHP_EOL;
                    die;

                } else {
                    #撮合完了
                    //数量发生变化的订单
                    $updateArr[] = [
                        'order_id' => $order['order_id'],
                        'quantity' => round($rawOrder['quantity'], $this->precision),
                        'sellout' => 1
                    ];

                    // $order['avg_price'] = 0;
                    // $rstArr[]=$order;
                    $this->orderRedis->removeOrder($order['order_id']);
                }
            } else {
                //市价单成交不了,没单子可吃
                //TODO一般不允许这种情况出现
                echo '市价单没单子可吃', PHP_EOL;
                die;
            }
        } else {
            //处理市价买
            $orderArea = $this->orderRedis->getOrderBooks($order['market'], 'limit', 'ask');
            if (count($orderArea) > 0) {
                foreach ($orderArea as $key => $value) {
                    $orderInfo = $this->orderRedis->getOrder($key); //根据orderid查找订单详情
                    if (!$orderInfo) {
                        echo 'order_id 不存在 ' . $key . PHP_EOL;
//                        $removeRes = $this->orderRedis->removeOrderBook($order['market'], 'limit', 'ask', $key);
                        continue;
                    }

                    if (round($order['quantity'], $this->precision) <= round($orderInfo['quantity'], $this->precision)) {    //本单可售数量充足
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'quantity', round($orderInfo['quantity'] - $order['quantity'], $this->precision));//修改本单可售数量
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'match_id', $orderInfo['match_id'] . $order['order_id'] . ',');//修改本单撮合的id
                        $sellout = 0;
                        if ($operaRes && round($order['quantity'], $this->precision) == round($orderInfo['quantity'], $this->precision)) {//若可售数量为0，从卖盘撤单
                            $removeRes = $this->orderRedis->removeOrderBook($orderInfo['market'], 'limit', 'ask', $orderInfo['order_id']);
                            $removeKey = $this->orderRedis->removeOrder($orderInfo['order_id']);#删除 redis key
                            $sellout = 1;
                        }
                        //数量发生变化的订单
                        $updateOrder = [
                            'order_id' => $key,
                            'quantity' => round($order['quantity'], $this->precision),
                            'sellout' => $sellout
                        ];
                        $updateArr[] = $updateOrder;
                        //撮合成功的订单
                        $matchOrder = [
                            'sell_id' => $orderInfo['user_id'],
                            'buy_id' => $order['user_id'],
                            'price' => $orderInfo['price'],
                            'quantity' => round($order['quantity'], $this->precision),
                            'side' => 'bid',    //买入
                            'market' => $order['market'],
                            'sell_order' => $orderInfo['order_id'],//卖单id
                            'buy_order' => $order['order_id'],//买单id
                        ];
                        $matchArr[] = $matchOrder;
                        $order['quantity'] = 0;//数量清0
                        break;
                    } else {    //本单可售数量不足
                        $order['quantity'] = round($order['quantity'] - $orderInfo['quantity'], $this->precision);//修改传入订单数量
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'quantity', 0);//修改本单可售数量为0
                        $operaRes = $this->orderRedis->updateOrder($orderInfo['order_id'], 'match_id', $orderInfo['match_id'] . $order['order_id'] . ',');//修改本单撮合的id
                        $removeRes = $this->orderRedis->removeOrderBook($orderInfo['market'], 'limit', 'ask', $orderInfo['order_id']);//将从卖盘撤单
                        $removeKey = $this->orderRedis->removeOrder($orderInfo['order_id']);#删除 redis key

                        //数量发送变化的订单
                        $updateOrder = [
                            'order_id' => $key,
                            'quantity' => round($orderInfo['quantity'], $this->precision),
                            'sellout' => 1
                        ];
                        $updateArr[] = $updateOrder;
                        //撮合成功的订单
                        $matchOrder = [
                            'sell_id' => $orderInfo['user_id'],
                            'buy_id' => $order['user_id'],
                            'price' => $orderInfo['price'],
                            'quantity' => round($orderInfo['quantity'], $this->precision),
                            'side' => 'bid',    //买入
                            'market' => $order['market'],
                            'sell_order' => $orderInfo['order_id'],//卖单id
                            'buy_order' => $order['order_id'],//买单id
                        ];
                        $matchArr[] = $matchOrder;
                        continue;    //继续撮合直到传入订单完成或没有撮合的卖单
                    }
                }

                if ($order['quantity'] != 0) {
                    //如果传入订单还有未撮合的数量->剩下的数量直接生成买盘单
                    //TODO一般不允许这种情况出现
                    echo '传入订单还有未撮合的数量', PHP_EOL;
                    die;
                } else {
                    #撮合完了
                    $updateArr[] = [
                        'order_id' => $order['order_id'],
                        'quantity' => round($rawOrder['quantity'], $this->precision),
                        'sellout' => 1
                    ];
                    $this->orderRedis->removeOrder($order['order_id']);
                }
            } else {
                //市价单成交不了,没单子可吃
                //一般不允许这种情况出现
                echo '市价单没单子可吃', PHP_EOL;
                die;
            }
        }
        return ['updateArr' => $updateArr, 'matchArr' => $matchArr, 'newArr' => $newArr, 'rstArr' => $rstArr];
    }

    /**
     * 限价订单数据校验
     * @param array $order
     * @return int
     */
    private function limitDataCheck(array $order)
    {
        if (!isset($order['user_id']) || !isset($order['quantity']) || !isset($order['price']) || !isset($order['order_id']) || !isset($order['side']) || !isset($order['type'])) {
            return 0;
        }
        if ($order['price'] <= 0 || $order['quantity'] <= 0 || !in_array($order['type'], ['limit', 'market']) || !in_array($order['side'], ['ask', 'bid'])) {
            return 0;
        }
        return 1;
    }

    /**
     * 市价订单数据校验
     * @param array $order
     * @return int
     */
    private function marketDataCheck(array $order)
    {
        if (!isset($order['user_id']) || !isset($order['quantity']) || !isset($order['price']) || !isset($order['order_id']) || !isset($order['side']) || !isset($order['type'])) {
            return 0;
        }
        if ($order['price'] != 0 || $order['quantity'] <= 0 || !in_array($order['type'], ['limit', 'market']) || !in_array($order['side'], ['ask', 'bid'])) {
            return 0;
        }
        return 1;
    }


}