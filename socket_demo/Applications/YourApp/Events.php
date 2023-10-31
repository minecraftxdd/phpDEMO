<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

/**
 * 用于检测业务代码死循环或者长时间阻塞等问题
 * 如果发现业务卡死，可以将下面declare打开（去掉//注释），并执行php start.php reload
 * 然后观察一段时间workerman.log看是否有process_timeout异常
 */
//declare(ticks=1);

use \GatewayWorker\Lib\Gateway;

/**
 * 主逻辑
 * 主要是处理 onConnect onMessage onClose 三个方法
 * onConnect 和 onClose 如果不需要可以不用实现并删除
 */
class Events
{
    /**
     * 当客户端连接时触发
     * 如果业务不需此回调可以删除onConnect
     * 
     * @param int $client_id 连接id
     */
    public static function onConnect($client_id)
    {
        $msg = "client:".$_SERVER['REMOTE_ADDR']. " client_id:". $client_id." connect".PHP_EOL;
        echo $msg;
        $retMsg = [
            'cmd'   => 'connect',
            'msg'   => 'Websocket connect success.'
        ];
        Gateway::sendToCurrentClient(json_encode($retMsg));
    }
    
   /**
    * 当客户端发来消息时触发
    * @param int $client_id 连接id
    * @param mixed $message 具体消息
    */
   public static function onMessage($client_id, $message)
   {
       $msg = "onMessage: $client_id said $message".PHP_EOL;
       echo $msg;

       $message_data = json_decode($message, true);
       if(!$message_data)
       {
           $msg = "json_decode error: $client_id said $message".PHP_EOL;
           echo $msg;
           return;
       }

       switch($message_data['cmd']) {
           // 客户端发送的心跳 {"cmd":"ping"}
           case 'ping':
               Gateway::sendToCurrentClient('{"cmd":"pong"}');
               break;
           case 'pong':
               break;

           // 客户端声明 message格式: {"cmd":"declare","type":"device","sn":"设备编号"}
           case 'declare':
               // 判断是否有类别
               if (!isset($message_data['type'])) {
                   $msg = "\$message_data['type'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message";
                   throw new \Exception($msg);
                   break;
               }
               if (!isset($message_data['sn'])) {
                   $msg = "\$message_data['sn'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message";
                   throw new \Exception($msg);
                   break;
               }
               if (!isset($message_data['sn'])) {
                   $message_data['sn'] = 'client';
               }

               $type = htmlspecialchars($message_data['type']);
               $sn = htmlspecialchars($message_data['sn']);
               $_SESSION['type'] = $type;
               $_SESSION['sn'] = $sn;

               Gateway::joinGroup($client_id, $type);
               if ($type == "device") {
                   Gateway::bindUid($client_id, $sn);
               }

               $res_message = array(
                   'cmd' => 'declare',
                   'client_id' => $client_id
               );
               Gateway::sendToCurrentClient(json_encode($res_message));
               echo "declare -- sendToCurrentClient: " . json_encode($res_message) . PHP_EOL;
               break;

           // 发送给设备 message格式: {"cmd":"to_device","form":"client_id","to":"设备编号","data":{}}
           case 'to_device':
               // 判断是否有接收对象
               if(!isset($message_data['to']))
               {
                   $msg = "\$message_data['to'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message";
                   throw new \Exception($msg);
                   break;
               }
               $is_online = Gateway::isUidOnline($message_data['to']);
               if (!$is_online)
               {
                   if(!isset($message_data['data']['cmd']))
                   {
                       $message_data['data']['cmd'] = 'isOnline';
                   }
                   $errmsg = $message_data['to'].' is offline.';
                   $user_id = isset($message_data['data']['user_id'])?$message_data['data']['user_id']:'';
                   Events::errorReturn("to_client", $client_id, $message_data['data']['cmd'], $errmsg, $message_data['to'], $user_id);
                   break;
               }
               Gateway::sendToUid($message_data['to'], $message);
               break;

           // 发送给客户端 message格式: {"cmd":"to_client","form":"设备编号","to":"client_id","data":{}}
           case 'to_client':
               // 判断是否有接收对象
               if(!isset($message_data['to']))
               {
                   $msg = "\$message_data['to'] not set. client_ip:{$_SERVER['REMOTE_ADDR']} \$message:$message";
                   throw new \Exception($msg);
                   break;
               }
               $is_online = Gateway::isOnline($message_data['to']);
               if (!$is_online)
               {
                   if(!isset($message_data['data']['cmd']))
                   {
                       $message_data['data']['cmd'] = 'isOnline';
                   }
                   $errmsg = $message_data['to'].' is offline.';
                   $user_id = isset($message_data['data']['user_id'])?$message_data['data']['user_id']:'';
                   Events::errorReturn("to_device", $client_id, $message_data['data']['cmd'], $errmsg, $message_data['to'], $user_id);
                   break;
               }
               Gateway::sendToClient($message_data['to'], $message);
               break;
       }
   }
   
   /**
    * 当用户断开连接时触发
    * @param int $client_id 连接id
    */
   public static function onClose($client_id)
   {
       $msg =  "onClose client:{$_SERVER['REMOTE_ADDR']}:{$_SERVER['REMOTE_PORT']} client_id:$client_id onClose".PHP_EOL;
       echo $msg;
   }

    /**
     * 返回失败
     */
    public static function errorReturn($cmd, $to, $data_cmd, $errmsg, $from, $user_id)
    {
        switch ($data_cmd)
        {
            case 'setAd':
                $data_cmd = 'setAdRet';
                break;
            case 'setSwitch':
                $data_cmd = 'setSwitchRet';
                break;
            case 'addUser':
                $data_cmd = 'addUserRet';
                break;
            case 'editUser':
                $data_cmd = 'editUserRet';
                break;
            case 'delUser':
                $data_cmd = 'delUserRet';
                break;
        }

        $res_message = [
            "cmd"    => $cmd,
            "form"   => $from,
            "to"     => $to,
            "data"   => [
                "cmd"    => $data_cmd,
                'user_id' => $user_id,
                "code"   => 999,
                "msg"    => $errmsg
            ]
        ];
        Gateway::sendToCurrentClient(json_encode($res_message));
        $logMsg = "server error return -- ".$errmsg.PHP_EOL;
        echo $logMsg;
    }
}
