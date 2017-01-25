<?php

namespace console\controllers;

class ElasticController extends \yii\console\Controller
{
    protected $length = 5000;

    public function actionHello()
    {
        $db = \common\models\elastic\PromoCard::getDb();
        print_r($db);
    }

    public function actionTest()
    {
        // 创建索引
        //\common\models\elastic\PromoCard::createIndex();

        // 删除索引
        //\common\models\elastic\PromoCard::deleteIndex();
//        $model = new \common\models\elastic\PromoCard();
//        $data = [
//            'card_no' => 'DSGBTPKL7SDCLK',
//            'effect_params' => '10',
//            'status' => 'activated',
//            'enable_time' => 1480521600,
//            'expire_time' => 1480780799,
//            'retrieval_source' => 'magic_box',
//            'issue_time' => 1480521600,
//            'date' => '20161201'
//        ];
//        $model->setAttributes($data);
//        if ($model->save()) {
//            echo "yes\n";
//        } else {
//            echo "no";
//        }

        $length = 2;
        $bulk = \common\models\elastic\PromoCard::getDb()->createBulkCommand();
        $bulk->index = 'test';
        $bulk->type = 'test1';
        $query = ['index' => ['_index' => $bulk->index, '_type' => $bulk->type]];
        $data = $this->getData();
        $list = array_chunk($data, $length);
        foreach ($list as $column) {
            foreach ($column as $value) {
                $bulk->addAction($query, $value);
            }
            $bulk->execute();
            //@warning 该步骤必须，否则当前脚本会保存重复的文档
            $bulk->actions = [];
        }

        // 通过主键查询
        //$one = \common\models\elastic\PromoCard::findOne(['_id' => 'AVl0ZxYdxsSWn61a4dSf'])->toArray();
        //$one = \common\models\elastic\PromoCard::findOne('AVl0ZxYdxsSWn61a4dSf')->toArray();
        //print_r($one);
    }

    protected function getData()
    {
        return [
            [
                'card_no' => 'DSGBTPKL7SDCLK1',
                'effect_params' => '10',
                'status' => 'activated',
                'enable_time' => 1480521600,
                'expire_time' => 1480780799,
                'retrieval_source' => 'magic_box1',
                'issue_time' => 1480521600,
                'date' => '20161202'
            ],
            [
                'card_no' => 'DSGBTPKL7SDCLK2',
                'effect_params' => '10',
                'status' => 'activated',
                'enable_time' => 1480521600,
                'expire_time' => 1480780799,
                'retrieval_source' => 'magic_box2',
                'issue_time' => 1480521600,
                'date' => '20161203'
            ],
            [
                'card_no' => 'DSGBTPKL7SDCLK3',
                'effect_params' => '10',
                'status' => 'activated',
                'enable_time' => 1480521600,
                'expire_time' => 1480780799,
                'retrieval_source' => 'magic_box3',
                'issue_time' => 1480521600,
                'date' => '20161204'
            ],
            [
                'card_no' => 'DSGBTPKL7SDCLK4',
                'effect_params' => '10',
                'status' => 'activated',
                'enable_time' => 1480521600,
                'expire_time' => 1480780799,
                'retrieval_source' => 'magic_box4',
                'issue_time' => 1480521600,
                'date' => '20161205'
            ],
            [
                'card_no' => 'DSGBTPKL7SDCLK5',
                'effect_params' => '10',
                'status' => 'activated',
                'enable_time' => 1480521600,
                'expire_time' => 1480780799,
                'retrieval_source' => 'magic_box5',
                'issue_time' => 1480521600,
                'date' => '20161206'
            ],
            [
                'card_no' => 'DSGBTPKL7SDCLK6',
                'effect_params' => '10',
                'status' => 'activated',
                'enable_time' => 1480521600,
                'expire_time' => 1480780799,
                'retrieval_source' => 'magic_box6',
                'issue_time' => 1480521600,
                'date' => '20161207'
            ],
        ];
    }

    /**
     * 从本地批量导入数据到线上ES
     */
    public function actionBulk()
    {
        $bulk = \common\models\elastic\PromoCard::getDb()->createBulkCommand();
        $bulk->index = \common\models\elastic\PromoCard::index();
        $bulk->type = \common\models\elastic\PromoCard::type();
        $query = ['index' => ['_index' => $bulk->index, '_type' => $bulk->type]];

        // 单个文件太大，内存足够，但是处理大数据不够，将文件按50000行分割成多个文本，如果数量过多，需要增加行数，不可以用大小分割，避免数据折断
        // 默认会在末尾添加字母标识，例如aa,ab,ac...
        // split -l 50000 promo_card-20160607-20160608-20161125114112 new_promo_card_
        $keys = ['card_no', 'effect_params', 'status', 'enable_time', 'expire_time', 'retrieval_source', 'issue_time', 'date'];
        $file = '/Users/zhgxun/Desktop/promo/promo_card-20161108-20161110-20170106155528.txt';
        $path = glob($file);
        foreach ($path as $item) {
            $list = [];
            // 直接读取每一个文件的内容
            $data = file($item);
            foreach ($data as $value) {
                $list[] = array_combine($keys, json_decode($value));
            }
            unset($data);
            // 最终为每5000个元素为一组
            $result = array_chunk($list, $this->length);
            unset($list);
            foreach ($result as $column) {
                foreach ($column as $value) {
                    $bulk->addAction($query, $value);
                }
                $bulk->execute();
                $bulk->actions = [];
            }
            unset($result);
        }
    }

    public function actionDelete()
    {
        $client = \common\models\elastic\PromoCard::getDb()->createCommand();
        $client->index = \common\models\elastic\PromoCard::index();
        $client->type = \common\models\elastic\PromoCard::type();
        // 删除卡号为DSGBTPKL7SDCLK6的现金券记录
//        $client->queryParts = [
//            'query' => [
//                'match' => [
//                    'card_no' => 'DSQACYWA7HGGJM'
//                ],
//            ],
//        ];
        // 删除某一天的数据
        $client->queryParts = [
            'query' => [
                'filtered' => [
                    'filter' => [
                        'range' => [
                            'date' => [
                                'gt' => '20161205',
                                'lt' => '20161207'
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $result = $client->deleteByQuery();
        print_r($result);
    }

    public function actionSearch()
    {
        $client = \common\models\elastic\PromoCard::getDb()->createCommand();
        $client->index = \common\models\elastic\PromoCard::index();
        $client->type = \common\models\elastic\PromoCard::type();
        // 搜索一个现金券卡号
        $client->queryParts = [
            'query' => [
                'match' => [
                    'card_no' => 'MBS5EW7HCBC2V7'
                ],
            ],
        ];
//        $client->queryParts = [
//            'query' => [
//                'match' => [
//                    'retrieval_source' => 'magic_box4'
//                ]
//            ]
//        ];
        // 搜索日期区间在2016年12月6号的数据
//        $client->queryParts = [
//            'query' => [
//                'filtered' => [
//                    'filter' => [
//                        'range' => [
//                            'date' => [
//                                'gt' => '20161205',
//                                'lt' => '20161207'
//                            ],
//                        ],
//                    ],
//                ],
//            ],
//        ];

        // 搜索日期在2016年12月5号和6号，并且现金券来源包含 magic_box4 关键字的数据
//        $client->queryParts = [
//            'query' => [
//                'filtered' => [
//                    'filter' => [
//                        'range' => [
//                            'date' => [
//                                'gt' => '20161204',
//                                'lt' => '20161207'
//                            ],
//                        ],
//                    ],
//                    'query' => [
//                        'match' => [
//                            'retrieval_source' => 'magic_box4'
//                        ],
//                    ],
//                ],
//            ],
//        ];
        $result = $client->search();
        print_r($result);
    }
}