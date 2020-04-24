<?php
/**
 * 抽奖红包推送
 * 
 * Class Qiniu
 */
namespace App\HttpController\Api;


use App\HttpController\Api\Base;
use EasySwoole\EasySwoole\Task\TaskManager;
use EasySwoole\EasySwoole\ServerManager;


use EasySwoole\EasySwoole\Config as GlobalConfig;

use App\Task\RedisQueuePushDataTask;
use App\Task\RedisQueuePullDataTask;

use App\Models\Player;
use App\Models\OtherUser;
use App\Models\PlayerRedBag;


class Lottery extends Base
{

    /**
     * 生成红包
     * 
     * PARAM
     * @param number redTitle      红包标题.
     * @param number redTotalMoney 红包总金额.
     * @param number redNum        红包总个数.
     * @param string unionid       user unionid.
     * @param string playerId      活动id.
     * @param int    type          类型 1 默认红包  2拼手气.
     * 
     * @return json | null.
     */
    public function redBoxCreate()
    {
        $redTitle      = $this->request()->getRequestParam('redTitle');
        $redTotalMoney = $this->request()->getRequestParam('redTotalMoney');
        $redNum        = $this->request()->getRequestParam('redNum');
        $unionid       = $this->request()->getRequestParam('unionid');
        $playerId      = $this->request()->getRequestParam('playerId');
        $type          = $this->request()->getRequestParam('type');
        

        
        if(
            $redTitle == '' || 
            !is_numeric($redTotalMoney) || 
            !is_numeric($redNum) || 
            !is_numeric($playerId) || 
            $unionid == ''||
            ($type != 1 && $type != 2)
        ) {
            $this->writeJson('400', null, '参数错误');
            return false;
        }

        $preg = '/([\x{4e00}-\x{9fa5}\sA-Za-z0-9\s]+)/u';

        if(!preg_match($preg, $redTitle)){
            $this->writeJson('401', null, '标题支持中文、英文、数字、下划线');
        }

        if(mb_strlen($redTitle) > 15) {
            $this->writeJson('402', null, '标题最大长度为15个字');
        }

        if(strlen($redTotalMoney) > 8) {
            $this->writeJson('403', null, '总金额最大长度为8');
        }

        if(strlen($redNum) > 8) {
            $this->writeJson('403', null, '个数最大长度为8');
        }

        

        $resForPlayer = Player::create()->checkExistsById($playerId);

       
        if(!$resForPlayer) {
            $this->writeJson('999', null, '活动不存在');
            return false;
        }

        
        $playerName       = $resForPlayer['name'];
        $playerLiveTime   = $resForPlayer['live_time'];
        $playerMasterId   = $resForPlayer['player_master_id'];
        $playerMasterName = $resForPlayer['player_master_name'];



        $resForOther = OtherUser::create()->checkExistsByUnionid($unionid);

        if(!$resForOther) {
            $this->writeJson('999', null, '用户不存在');
            return false;
        }


        $otherUserId = $resForOther['id'];


        $playRedBag = PlayerRedBag::create();

        $playRedBag->player_id          = $playerId;
        $playRedBag->player_name        = $playerName;
        $playRedBag->player_live_time   = $playerLiveTime;
        $playRedBag->player_master_id   = $playerMasterId;
        $playRedBag->player_master_name = $playerMasterName;
        $playRedBag->other_user_id      = $otherUserId;
        $playRedBag->other_uesr_unionid = $unionid;
        $playRedBag->red_title          = $redTitle;
        $playRedBag->red_total_money    = $redTotalMoney;
        $playRedBag->red_num            = $redNum;
        $playRedBag->red_type           = $type;
        $playRedBag->red_send_time      = $redNum;


        $playRedBagSaveReturn = $playRedBag->save();
        if($playRedBagSaveReturn == null) {
            $this->writeJson('999', null, '操作失败');
            return false;
        }


        $this->contextSet('pid', $playRedBagSaveReturn);
        $this->contextSet('unionid', $unionid);
        $this->contextSet('playerId', $playerId);
        $this->contextSet('type', $type);

        $this->contextSet('redTotalMoney', $redTotalMoney);
        $this->contextSet('redNum', $redNum);
        

        // go(function (){
        //     $csp = new \EasySwoole\Component\Csp();
            
        //     //推送弹幕到 Redis队列 协程异步
        //     $csp->add('t1',function (){
                
                $type          = $this->contextGet('type');
                $pid           = $this->contextGet('pid');
                $redTotalMoney = $this->contextGet('redTotalMoney');
                $redNum        = $this->contextGet('redNum');
                $unionid       = $this->contextGet('unionid');
                $playerId      = $this->contextGet('playerId');

                $redBoxArray = [];
                if($type == 1) {
                    //固定红包
                    $redBoxArray = $this->redEnvelopeProduce($redTotalMoney, $redNum);
                } elseif($type == 2) {
                    //随机红包
                    $redBoxArray = $this->redEnvelopeRandomProduce($redTotalMoney, $redNum);
                }

                $redisQueueNamePrefix = GlobalConfig::getInstance()->getConf('REDIS_LOTTERY_QUEUE_NAME_PREFIX');
                $redisName            = GlobalConfig::getInstance()->getConf('REDIS_LOTTERY_USE_NAME');
        
                $queueName = $redisQueueNamePrefix.$unionid.'_'.$playerId;
               
                foreach($redBoxArray as $value) {
                    $task = TaskManager::getInstance();
                    var_dump($task->async(new RedisQueuePushDataTask($value, $redisName, $queueName)));
                }
               // \co::sleep(1);
               //return 't1 result';
            // });

            // $csp->add('t2',function (){
                
                $type          = $this->contextGet('type');
                $pid           = $this->contextGet('pid');
                $redTotalMoney = $this->contextGet('redTotalMoney');
                $redNum        = $this->contextGet('redNum');

                $redBoxArray = [];
                if($type == 1) {
                    //固定红包
                    $redBoxArray = $this->redEnvelopeProduce($redTotalMoney, $redNum);
                } elseif($type == 2) {
                    //随机红包
                    $redBoxArray = $this->redEnvelopeRandomProduce($redTotalMoney, $redNum);
                }
               
                $saveData = [];
                foreach($redBoxArray as $value) {
                    $redCode  = $value['red_code'];
                    $redTitle = $value['red_title'];
                    $redMoney = $value['red_money'];

                    $saveData[$redCode]['red_pid']         = $pid;
                    $saveData[$redCode]['red_title']       = $redTitle;
                    $saveData[$redCode]['red_money']       = $redMoney;
                    $saveData[$redCode]['red_total_money'] = $redTotalMoney;
                    $saveData[$redCode]['red_type']        = $type;
                }
                $playRedBag = PlayerRedBag::create();

                $playRedBagSaveReturn = $playRedBag->saveAll($saveData);
                
                //\co::sleep(3);
               var_dump($playRedBagSaveReturn);
        //     });
        //      $csp->exec();
        // });



        // $this->redisPush(['action' => 'broadCast', 'content' => '123123', 'roomId' => 11, 'username' => 'server']);
    }


    /**
     * 获取红包
     * 
     * @return json | null.
     */
    public function redBoxGet()
    {

        
        // $data =  $task->sync(function (){
        //     echo "同步调用task1\n";
        //     return "可以返回调用结果\n";
        // });

        $redisQueueNamePrefix = GlobalConfig::getInstance()->getConf('REDIS_LOTTERY_QUEUE_NAME_PREFIX');
        $redisName            = GlobalConfig::getInstance()->getConf('REDIS_LOTTERY_USE_NAME');
        $queueName            = $redisQueueNamePrefix.'10_10';
        $task                 = TaskManager::getInstance();
        $result               = $task->sync(new RedisQueuePullDataTask($redisName, $queueName));
       
        $this->writeJson(200, null, $result);
    }


    /**
     * 拼手气红包生成算法
     * @param $red_total_money
     * @param $red_num
     * @return array
     */
    private function redEnvelopeRandomProduce($red_total_money, $red_num)
    {
        //1 声明定义最小红包值
        $red_min = 0.01;
    
        //2 声明定义生成红包结果
        $result_red = array();
    
        
        //3 惊喜红包计算
        for ($i = 1; $i < $red_num; $i++) {
            //3.1 计算安全上限 | 保证每个人都可以分到最小值
            $safe_total = ($red_total_money - ($red_num - $i) * $red_min) / ($red_num - $i);
            //3.2 随机取出一个数值
            $red_money_tmp = mt_rand($red_min * 100, $safe_total * 100) / 100;
            //3.3 将金额从红包总金额减去
            $red_total_money -= $red_money_tmp;
            $result_red[] = array(
                'red_code' => $i,
                'red_title' => '红包' . $i,
                'red_money' =>  round($red_money_tmp, 2),
            );
        }
    
        //4 最后一个红包
        $result_red[] = array(
            'red_code'  => $red_num,
            'red_title' => '红包' . $red_num,
            'red_money' => round($red_total_money, 2),
        );
    
        return $result_red;
    }

    /**
     * 固定红包算法
     * @param $red_total_money
     * @param $red_num
     * @return array
     */
    private function redEnvelopeProduce($red_total_money, $red_num)
    {

        //1 声明定义最小红包值
        //$red_min = 0.01;
    
        //2 声明定义生成红包结果
        $result_red = array();
    
        $red_money_tmp = round($red_total_money / $red_num, 2);

        //3 惊喜红包计算
        for ($i = 1; $i < $red_num; $i++) {
            //3.1 计算安全上限 | 保证每个人都可以分到最小值
            //$safe_total = ($red_total_money - ($red_num - $i) * $red_min) / ($red_num - $i);
            //3.2 随机取出一个数值
            
            //3.3 将金额从红包总金额减去
            $red_total_money -= $red_money_tmp;
            $result_red[] = array(
                'red_code' => $i,
                'red_title' => '红包' . $i,
                'red_money' =>  $red_money_tmp,
            );
        }
    
        //4 最后一个红包
        $result_red[] = array(
            'red_code' => $red_num,
            'red_title' => '红包' . $red_num,
            'red_money' => round($red_total_money, 2),
        );
    
        return $result_red;
    }

}