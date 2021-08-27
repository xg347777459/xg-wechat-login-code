<?php

namespace Xg\WechatLoginCode;


class WechatLoginCode extends Controller{
    /*******************************************************************************************************************************************************************************************************************
     * 配置项
     */
    private $token = ''; //微信公众号服务器配置令牌
    private $wxgzh = []; //公众号 暂未使用
    private $bd_ai = []; //百度-理解与交互技术UNIT
    private $post_obj; //接收微信服务器推送的消息
    private $user_info; //用户信息
    private $user_auth = []; //用户授权
    private $auth = [
    ]; //本接口涉及权限
    //信息类型
    private $type_arr = [
        '1'=>'text',
        '2'=>'image',
        '3'=>'voice',
        '4'=>'video',
        '5'=>'music',
        '6'=>'news'
    ];

    /*******************************************************************************************************************************************************************************************************************
     * 验证消息的确来自微信服务器
     */
    public function __construct(){
        //获取公众号配置 暂未使用
        //$this->wxgzh = \App\Server\WxGzh::getWxConfig();

        //百度配置
        $bd_ai_obj = App\Models\XgConfig::select('content')->where('code', 'BDY')->first();
        foreach ($bd_ai_obj->content as $k => $v){
            $this->bd_ai[$v['name']] = $v['value'];
        }
    }

    private function init(){
        $user_info = App\Models\XgUser::where('wx_openid', $this->post_obj->FromUserName)->first();
        $user_auth = App\Models\XgUserAuth::where('uid',$user_info->id)->first();
        $this->user_info = $user_info;
        $this->user_auth = empty($user_auth)?[]:explode(',',$user_auth->auth_id);
    }

    public function index(){
        $token=$this->token;
        $timestamp=@$_GET['timestamp'];
        $signature=@$_GET['signature'];
        $nonce=@$_GET['nonce'];
        $echostr=@$_GET['echostr'];
        $array=@array($token,$timestamp,$nonce);
        sort($array);
        $str=implode($array);
        if (sha1($str)==$signature && $echostr) {
            // 首次验证
            echo  $echostr;
        }else{
            // 已验证
            $this->res();
        }
    }


    /*******************************************************************************************************************************************************************************************************************
     * 接收微信服务器推送的消息
     */

    private function res(){
        $postArr = @file_get_contents('php://input');
        $postObj = simplexml_load_string( $postArr );
        $this->post_obj = $postObj;
        // 用户关注
        if( strtolower($this->post_obj->Event) == 'subscribe' ){
            //关注注册用户信息
            if (!$this->user_info){
                $insert_data['wx_openid'] = $this->post_obj->FromUserName;
                $insert_data['nick_name'] = '观众'.date("YmdHis");
                $insert_data['user_name'] = time();
                $insert_data['password'] = 123456;
                $insert_data['user_type'] = 1;
                $insert_data['img'] = 'images/default_user_img.jpg';
                $insert_data['create_time'] = date("Y-m-d H:i:s");
                App\Models\XgUser::insert($insert_data);
            }

            $content = '感谢您的关注！';
            // 推送关注词给用户
            $this->re_text($content);
            return;
        }
        $this->init();
        // 用户输入普通信息
        if( strtolower($this->post_obj->MsgType) == 'text'){
            //用户输入内容
            $content = $this->post_obj->Content;
            $str_last = substr($content, -1);
            if (in_array($str_last,['?','!','.'])){
                $content = substr($content, 0, -1);
            }
            //查找对应的回复信息，仅限文本输入
            $info = App\Models\XgAutoReplie::where('key',$content)->where('state',1)->first();
        }
        // 用户输入图片信息 暂不处理
        if( strtolower($this->post_obj->MsgType) == 'image'){
            $content = '请输入文字，暂不支持图片哦！';
            // 推送关注词给用户
            $this->re_text($content);
            return;
        }
        //回复
        if(!empty($info)){
            App\Models\XgAutoReplie::where('id', $info->id)->increment('num');
            $content = $info->content;
            $media_id = $info->media_id;
            if ($info->type > 1 && $info->status==0){
                $this->re_text('素材准备中，请稍后！');
                return;
            }
            switch ($info->type) {
                case 1:
                    //回复文本
                    $this->re_text($content);
                    break;
                case 2:
                    //回复图片
                    $media_id = $info->media_id;
                    if (!$media_id){
                        $this->re_image('素材准备中，请稍后！');
                    }
                    $this->re_image($media_id);
                    break;
                case 3:
                    //回复语音
                    $this->re_voice($media_id);
                    break;
                case 4:
                    //回复视频
                    $this->re_video($media_id);
                    break;
                case 5:
                    //回复音乐
                    $this->re_music($media_id);
                    break;
                case 6:
                    //回复图文
                    $this->re_news($media_id);
                    break;
            }

        }else{
            if ($content == '帮助') {
                $str = '请输入'. "\n";
                $str .= '消息模板'. "\n";
                $str .= '记账模板'. "\n";
                $str .= '账号密码模板'. "\n";
                $str .= '随机密码模板'. "\n";
                $str .= '消费图表';
                $this->re_text($str);
                return;
            }
            if ($content == 'ID') {
                $str = '你的ID是'. $this->user_info->id;
                $this->re_text($str);
                return;
            }
            //laraveladmin授权登录授权码
            if ($content == '授权码') {
                $res = DB::table('admin_users')->where('uid',$this->user_info->id)->first();
                if (!$res){
                    $this->re_text("少年，你还不是管理员哦！");
                    return;
                }
                $redis_key = config('common.REDIS_KEY')[2];
                $wx_openid = $this->post_obj->FromUserName;
                $rand = rand(100000,999999);
                redis::setex($redis_key.$rand, 60*1, "$wx_openid");
                $this->re_text("授权码：".$rand."\n授权码一分钟后失效，请尽快操作");
                return;
            }
            //账号密码 只有管理员才有权限
            if($this->post_obj->FromUserName == env('ADMIN_OPENID')) {
                if (strpos($content, 'ADDPWD|') !== false) {
                    $str = $this->pwd($content, 0);
                    $this->re_text($str);
                    return;
                } elseif (strpos($content, 'ADDPWD1|') !== false) {
                    $str = $this->pwd($content);
                    $this->re_text($str);
                    return;
                }elseif (strpos($content, 'DELPWD|') !== false) {
                    $str = $this->pwd($content, 3);
                    $this->re_text($str);
                    return;
                }elseif ($content == '账号密码模板') {
                    $str = '添加-普通：ADDPWD|招商银行|18694060590|555555+'. "\n";
                    $str .= '添加-加密：ADDPWD1|招商银行|18694060590|555555+'. "\n";
                    $str .= '删除：DELPWD|招商银行'. "\n";
                    $str .= '查看：招商银行'. "\n";
                    $str .= '查看所有：账号密码'. "\n";
                    $this->re_text($str);
                    return;
                }elseif ($content == '账号密码') {
                    $str = $this->pwd($content, 4);
                    $this->re_text($str);
                    return;
                }elseif ($content == '测试号授权码') {
                    $user_info_cs = App\Models\XgUser::where('wx_openid', '测试号授权码')->first();
                    $res = DB::table('admin_users')->where('uid',$user_info_cs->id)->first();
                    if (!$res){
                        $this->re_text("少年，你还不是管理员哦！");
                        return;
                    }
                    $redis_key = config('common.REDIS_KEY')[2];
                    $rand = rand(100000,999999);
                    redis::setex($redis_key.$rand, 60*1, "测试号授权码");
                    $this->re_text("授权码：".$rand."\n授权码一分钟后失效，请尽快操作");
                    return;
                }
                $str = $this->pwd($content, 2);
                if ($str){
                    $this->re_text($str);
                    return;
                }
            }
            //自动回复消息模板
            if(in_array($this->auth[0],$this->user_auth)){
                if (strpos($content,'BC|')!==false){
                    //保存信息到自动回复模板
                    $content_arr = explode('|',$content);
                    if (count($content_arr)==3){
                        //保存文本信息
                        $insert_data['type'] = 1;
                        $insert_data['content'] = trim($content_arr[2]);
                        $insert_data['key'] = trim($content_arr[1]);
                        $info1 = App\Models\XgAutoReplie::where('key',$content_arr[1])->first();
                        if ($info1){
                            $result = App\Models\XgAutoReplie::where('key',$content_arr[1])->update($insert_data);
                        }else{
                            $result = App\Models\XgAutoReplie::insert($insert_data);
                        }
                        if ($result){
                            $this->re_text('保存成功！');
                            return;
                        }else{
                            $this->re_text('保存失败！');
                            return;
                        }
                    }else{
                        $this->re_text('模板格式错误！');
                        return;
                    }

                }elseif (strpos($content,'SC|')!==false){
                    //删除自动回复模板
                    $content_arr = explode('|',$content);
                    if (count($content_arr)==2) {
                        $result = App\Models\XgAutoReplie::where('key', $content_arr[1])->delete();
                        if ($result) {
                            $this->re_text('删除成功！');
                            return;
                        } else {
                            $this->re_text('删除失败！');
                            return;
                        }
                    }else{
                        $this->re_text('模板格式错误！');
                        return;
                    }
                }elseif ($content == 'CK') {
                    //查询所有回复模板
                    $list = App\Models\XgAutoReplie::pluck('key');
                    if ($list) {
                        $str = "";
                        foreach ($list as $k => $v) {
                            $str .= ($k + 1) . "：" . $v . "\n";
                        }
                        $str = rtrim($str, "\n");
                        $this->re_text($str);
                        return;
                    } else {
                        $this->re_text('暂无回复消息模板！');
                        return;
                    }
                }elseif ($content == '消息模板') {
                    $str = '保存：BC|你是谁？|我是熊大'. "\n";
                    $str .= '删除：SC|你是谁？'. "\n";
                    $str .= '查询所有回复模板：CK';
                    $this->re_text($str);
                    return;
                }
            }
            //记账
            if(in_array($this->auth[1],$this->user_auth)){
                if ($content == 'TYPE'){
                    //查询消费类型
                    $xg_consume_type = App\Models\XgConsumeType::get()->toArray();
                    $array = array_column($xg_consume_type,'name');
                    $str = '消费类型'."\n";
                    foreach ($array as $key => $value) {
                        $str.=($key+1).'：'.$value."\n";
                    }
                    $str .= '账户类型'."\n";
                    $str .= '1：支付宝'."\n".'2：微信'."\n".'3：现金';
                    $this->re_text($str);
                    return;
                }elseif (strpos($content,'ADD|')!==false){
                    $content_arr = explode('|',$content);
                    if (count($content_arr)<5 && count($content_arr)>6) {
                        $this->re_text('模板格式错误！');
                        return;
                    }
                    //防止微信重复推送
                    if (!empty(redis::get('repetition')) && redis::get('repetition')==$content){
                        $this->re_text('重复推送！');
                        return;
                    }else{
                        redis::setex('repetition', 5, "$content");
                    }
                    $data['type'] = trim($content_arr[1]);
                    $data['money'] = trim($content_arr[2]);
                    $data['date'] = $content_arr[3]==0?strtotime(date('Y-m-d')):strtotime($content_arr[3]);
                    $data['content'] = trim($content_arr[4]);
                    $data['account_type'] = empty($content_arr[5])?1:trim($content_arr[5]);
                    $data['created_at'] = date('Y-m-d H:i:s');
                    $result = App\Models\XgConsumption::insertGetId($data);
                    if ($result){
                        $this->re_text('记录成功！('.$result.')');
                        return;
                    }else{
                        $this->re_text('记录失败！');
                        return;
                    }
                } elseif (strpos($content,'XF|')!==false){
                    $content_arr = explode('|',$content);
                    if (count($content_arr)!=2) {
                        $this->re_text('模板格式错误！');
                        return;
                    }
                    $str = $this->day_consumption_detail(3,$content_arr[1]);
                    $this->re_text($str);
                    return;
                }elseif (strpos($content,'DEL|')!==false){
                    $content_arr = explode('|',$content);
                    if (count($content_arr)!=2) {
                        $this->re_text('模板格式错误！');
                        return;
                    }
                    $result = App\Models\XgConsumption::where("id",$content_arr[1])->delete();
                    $str = $result?'删除成功':'删除失败';
                    $this->re_text($str);
                    return;
                }elseif ($content=='XF'){
                    $str = $this->day_consumption_detail(1);
                    $this->re_text($str);
                    return;
                }elseif ($content=='XFALL'){
                    $str = $this->day_consumption_detail(2);
                    $this->re_text($str);
                    return;
                }elseif ($content == '记账模板') {
                    $str = '添加：ADD|1|10|2020-09-15|备注'. "\n";
                    $str .= '删除：DEL|1'. "\n";
                    $str .= '查询今日消费：XF'. "\n";
                    $str .= '查询指定日期消费：XF|2020-09-15'. "\n";
                    $str .= '查询所有消费：XFALL';
                    $str .= '查询消费及账户类型：TYPE';
                    $this->re_text($str);
                    return;
                }elseif ($content == '消费图表') {
                    $code = randstr(10);
                    $redis_key = config('common.REDIS_KEY')[4];
                    redis::setex($redis_key, 60*3, $code);
                    $str = env('APP_URL').'/echarts/'.$code;
                    $str = "<a href='".$str."'>点击查看</a>";
                    $this->re_text($str);
                    return;
                }
            }

            $user_info = $this->user_info;
            //调用百度ai接口
            $session_id = $user_info->session_id?:time();
            $result = $this->ai_speak("$content",$user_info->id,'xg'.$user_info->id,$session_id);
            $arr = json_decode($result,true);
            if ($arr['error_code']==0){
                App\Models\XgUser::where('id', $user_info->id)->update(['session_id' => $arr['result']['session_id']]);
                $content = $arr['result']['response_list'][0]['action_list'][0]['say'];
                $xm = ['我叫张得亮。','我的名字叫王二小'];
                if (in_array($content,$xm)){
                    $content = '我叫小熊';
                }
            }else{
                $content = '德玛西亚';
            }
            $type = 1;// rand(1,2); 回复信息类型
            if ($type==1){
                //回复文本信息
                $this->re_text($content);
                return;
            }else{
                //回复语音信息 有问题待修改
                $file_name = $this->ai_audio($content);
                $result = $this->add_media($file_name,2);
                //素材上传成功后删除语音文件
                unlink($file_name);
                $res=json_decode($result);
                $this->re_voice($res->media_id);
                return;
            }
        }
    }


    /*******************************************************************************************************************************************************************************************************************
     * 被动回复信息模板
     */


    /**
     * [re_text 回复文本信息]
     * @actor  xg
     * @time   2017-09-28T09:55:58+0800
     * @param  [type]                   $content [回复内容，不支持HTML]
     * @return [type]                            [description]
     */
    private function re_text($content){
        $toUser   = $this->post_obj->FromUserName;
        $fromUser = $this->post_obj->ToUserName;
        $template ="<xml>
                    <ToUserName><![CDATA[%s]]></ToUserName>
                    <FromUserName><![CDATA[%s]]></FromUserName>
                    <CreateTime>%s</CreateTime>
                    <MsgType><![CDATA[%s]]></MsgType>
                    <Content><![CDATA[%s]]></Content>
                    </xml>";
        echo    sprintf($template, $toUser, $fromUser, time(), 'text', $content);
    }

    /**
     * [re_image 回复图片信息]
     * @actor  xg
     * @time   2017-09-28T09:55:44+0800
     * @param  [type]                   $MediaId [多媒体ID]
     * @return [type]                            [description]
     */
    private function re_image($mediaId){
        $toUser   = $this->post_obj->FromUserName;
        $fromUser = $this->post_obj->ToUserName;
        $template ="<xml>
                    <ToUserName><![CDATA[%s]]></ToUserName>
                    <FromUserName><![CDATA[%s]]></FromUserName>
                    <CreateTime>%s</CreateTime>
                    <MsgType><![CDATA[%s]]></MsgType>
                    <Image>
                    <MediaId><![CDATA[%s]]></MediaId>
                    </Image>
                    </xml>";
        echo    sprintf($template, $toUser, $fromUser, time(), 'image', $mediaId);
    }

    /**
     * [re_news 回复图文信息]
     * @actor  xg
     * @time   2017-09-28T10:33:43+0800
     * @param  [type]                   $arr     [图文信息，即一个数组]
     * @return [type]                            [description]
     */
    private function re_news($arr){
        $toUser   = $this->post_obj->FromUserName;
        $fromUser = $this->post_obj->ToUserName;
        $template ="<xml>
                    <ToUserName><![CDATA[%s]]></ToUserName>
                    <FromUserName><![CDATA[%s]]></FromUserName>
                    <CreateTime>%s</CreateTime>
                    <MsgType><![CDATA[%s]]></MsgType>
                    <ArticleCount>".count($arr)."</ArticleCount>
                    <Articles>";
        foreach($arr as $k=>$v){
            $template.="<item>
                        <Title><![CDATA[".$v['title']."]]></Title>
                        <Description><![CDATA[".$v['description']."]]></Description>
                        <PicUrl><![CDATA[".$v['picUrl']."]]></PicUrl>
                        <Url><![CDATA[".$v['url']."]]></Url>
                        </item>";
        }
        $template.="</Articles>
                    </xml> ";
        echo    sprintf($template, $toUser, $fromUser, time(), 'news');
    }

    /**
     * [re_voice 回复语音信息]
     * @actor  xg
     * @time   2017-09-28T09:55:01+0800
     * @param  [type]                   $MediaId [多媒体ID]
     * @return [type]                            [description]
     */
    private function re_voice($mediaId){
        $toUser   = $this->post_obj->FromUserName;
        $fromUser = $this->post_obj->ToUserName;
        $template ="<xml>
                    <ToUserName><![CDATA[%s]]></ToUserName>
                    <FromUserName><![CDATA[%s]]></FromUserName>
                    <CreateTime>%s</CreateTime>
                    <MsgType><![CDATA[%s]]></MsgType>
                    <Voice>
                    <MediaId><![CDATA[%s]]></MediaId>
                    </Voice>
                    </xml>";
        echo    sprintf($template, $toUser, $fromUser, time(), 'voice','80HBCyu6D02gJvYluqGMS-IQk_-6Z4IJ3HhDct05Uqw');
    }

    /**
     * [re_voice 回复音乐信息]
     * @actor  xg
     * @time   2017-09-28T10:18:38+0800
     * @param  [type]                   $ThumbMediaId [多媒体ID]
     * @param  string                   $title        [音乐信息的标题]
     * @param  string                   $description  [音乐信息的描述]
     * @param  string                   $MusicUrl     [音乐链接]
     * @param  string                   $HQMusicUrl   [高质量音乐链接，WIFI环境优先使用该链接播放音乐]
     * @return [type]                                 [description]
     */
    private function re_music($ThumbMediaId,$title='', $description='', $MusicUrl='', $HQMusicUrl=''){
        $toUser   = $this->post_obj->FromUserName;
        $fromUser = $this->post_obj->ToUserName;
        $template ="<xml>
                    <ToUserName><![CDATA[%s]]></ToUserName>
                    <FromUserName><![CDATA[%s]]></FromUserName>
                    <CreateTime>%s</CreateTime>
                    <MsgType><![CDATA[%s]]></MsgType>
                    <Music>
                    <Title><![CDATA[%s]]></Title>
                    <Description><![CDATA[%s]]></Description>
                    <MusicUrl><![CDATA[%s]]></MusicUrl>
                    <HQMusicUrl><![CDATA[%s]]></HQMusicUrl>
                    <ThumbMediaId><![CDATA[%s]]></ThumbMediaId>
                    </Music>
                    </xml>";
        echo    sprintf($template, $toUser, $fromUser, time(), 'music', $title, $description, $MusicUrl, $HQMusicUrl,$ThumbMediaId);
    }


    /**
     * [re_voice 回复视频信息]
     * @actor  xg
     * @time   2017-09-28T10:07:16+0800
     * @param  [type]                   $mediaId     [多媒体ID]
     * @param  [type]                   $title       [视频消息的标题]
     * @param  [type]                   $description [视频消息的描述]
     * @return [type]                                [description]
     */
    private function re_video($mediaId,$title='', $description=''){
        $toUser   = $this->post_obj->FromUserName;
        $fromUser = $this->post_obj->ToUserName;
        $template ="<xml>
                    <ToUserName><![CDATA[%s]]></ToUserName>
                    <FromUserName><![CDATA[%s]]></FromUserName>
                    <CreateTime>%s</CreateTime>
                    <MsgType><![CDATA[%s]]></MsgType>
                    <Video>
                    <MediaId><![CDATA[%s]]></MediaId>
                    <Title><![CDATA[%s]]></Title>
                    <Description><![CDATA[%s]]></Description>
                    </Video>
                    </xml>";
        echo    sprintf($template, $toUser, $fromUser, time(), 'video', $mediaId, $title, $description);
    }


    /** 暂未使用
     * [upload_media 新增其他类型素材]
     * @actor  xg
     * @time   2017-10-05T10:24:39+0800
     * @param  [type]                   $file_info [素材信息，数组]
     * @return [type]                              [media_id,url 数组]
     */
    private function add_media($filename,$v = 2){
        $file_info['filename'] = $filename;
        $file_info['content-type'] = filetype($filename);
        $file_info['filelength'] = filesize($filename);
        $access_token=\App\Server\WxGzh::get_wx_accesss_token();
        if ($v == 1) {
            // 新增临时素材，媒体文件类型，分别有图片（image）、语音（voice）、视频（video）和缩略图（thumb）
            $url = "https://api.weixin.qq.com/cgi-bin/media/upload?access_token=".$access_token."&type=voice";
        }elseif ($v == 2) {
            // 新增其他类型永久素材，分别有图片（image）、语音（voice）、视频（video）和缩略图（thumb）
            $url = "https://api.weixin.qq.com/cgi-bin/material/add_material?access_token=".$access_token."&type=voice";
        }
        $curl = curl_init();
        if (class_exists('\CURLFile')){
            curl_setopt($curl, CURLOPT_SAFE_UPLOAD, true);
            $data = array('media' => new \CURLFile($filename));//
        }else {
            curl_setopt($curl,CURLOPT_SAFE_UPLOAD,false);
            $data = array('media'=>'@'.$filename);
        }
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_POST, 1 );
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_USERAGENT,"TEST");
        $result = curl_exec($curl);
        return $result;
    }

    /*****以下为百度api****************/

    /** 获取accesss_token
     * @return mixed
     */
    private function get_bd_ai_accesss_token(){
        $redis_key = config('common.REDIS_KEY')[3];
        if (!empty(redis::get($redis_key))){
            return redis::get($redis_key);
        }
        $cofig = $this->bd_ai;
        $url = 'https://aip.baidubce.com/oauth/2.0/token';
        $post_data['grant_type']    = 'client_credentials';
        $post_data['client_id']     = $cofig['api_key'];
        $post_data['client_secret'] = $cofig['secret_key'];
        $o = "";
        foreach ( $post_data as $k => $v ) {
            $o.= "$k=" . urlencode( $v ). "&" ;
        }
        $post_data = substr($o,0,-1);
        $result = https_request($url, $post_data);
        $accesss_token = json_decode($result,true)['access_token'];
        //存储到数据库
        redis::setex($redis_key, 3600*24*30, $accesss_token);
        return $accesss_token;
    }

    /**ai智能回复
     * @param $content 输入内容
     * @param $user_id
     * @param $log_id
     * @param $session_id
     * @return mixed
     */
    private function ai_speak($content,$user_id,$log_id,$session_id){
        $access_token = $this->get_bd_ai_accesss_token();
        $url = 'https://aip.baidubce.com/rpc/2.0/unit/service/chat?access_token=' . $access_token;
        $data['version'] = '2.0';
        $data['service_id'] = 'S14737';
        $data['log_id'] = $log_id;
        $data['session_id'] = $session_id;
        $data['request'] = ['user_id'=>$user_id,'query'=>$content];
        $bodys = json_encode($data);
        $result = https_request($url, $bodys);
        return $result;
    }

    /**文本合成语音文件 暂未使用
     * @param $content 文本内容
     * @return string  文件目录
     */
    private function ai_audio($content){
        $access_token = $this->get_bd_ai_accesss_token();
        $url = 'http://tsn.baidu.com/text2audio';
        $bodys = "tex=$content&tok=$access_token&cuid=".time()."&ctp=1&lan=zh&per=4";
        $result = https_request($url, $bodys);
        $file_name = 'upload/audio/audio'.date('YmdHis').'-'.rand(100,999).'.mp3';
        file_put_contents($file_name, $result);
        return $file_name;
    }


    /**消费记录详情
     * @param Request $request
     */
    private function day_consumption_detail($type=1,$day=''){
        $date = time();
        switch ($type)
        {
            case 1:
                //当天
                $day_start_time = mktime(0, 0 , 0,date("m",$date),date("d",$date),date("Y",$date));
                $day_end_time = mktime(23,59,59,date("m",$date),date("d",$date),date("Y",$date));
                $daylist = App\Models\XgConsumption::where("date",'>=', $day_start_time)->where('date','<=',$day_end_time)->get();
                $daylist = json_decode(json_encode($daylist), true);
                $allmoney = array_sum(array_column($daylist,'money'));
                $xg_consume_type = App\Models\XgConsumeType::get()->toArray();
                $array = array_column($xg_consume_type,'name');
                $str = "今日截止目前消费\n";
                foreach ($daylist as $k=>$v){
                    $str.=$array[$v['type']-1].'：'.$v['money']."\n";
                }
                $str.='合计：'.$allmoney.'元';
                return $str;
                break;
            case 2:
                //本月
                $month_start_time = mktime(0, 0 , 0,date("m",$date),1,date("Y",$date));
                $month_end_time = $date;
                $month = App\Models\XgConsumption::where("date",'>=', $month_start_time)->where('date','<',$month_end_time)->sum("money");
                //上月
                $last_month_start_time = mktime(0, 0 , 0,date("m",$date)-1,1,date("Y",$date));
                $last_month_end_time = mktime(23,59,59,date("m",$date) ,0,date("Y",$date));
                $last_month = App\Models\XgConsumption::where("date",'>=', $last_month_start_time)->where('date','<',$last_month_end_time)->sum("money");
                //本周
                $weeks_start_time = mktime(0, 0 , 0,date("m",$date),date("d",$date)-date("w",$date),date("Y",$date));
                $weeks_end_time = $date;
                $weeks = App\Models\XgConsumption::where("date",'>=', $weeks_start_time)->where('date','<',$weeks_end_time)->sum("money");
                //上周
                $last_weeks_start_time = mktime(0, 0 , 0,date("m",$date),date("d",$date)-date("w",$date)-7,date("Y",$date));
                $last_weeks_end_time = mktime(23,59,59,date("m",$date),date("d",$date)-date("w",$date)+7-7,date("Y",$date));
                $last_weeks = App\Models\XgConsumption::where("date",'>=', $last_weeks_start_time)->where('date','<',$last_weeks_end_time)->sum("money");
                //昨天
                $last_day_start_time = mktime(0, 0 , 0,date("m",$date),date("d",$date)-1,date("Y",$date));
                $last_day_end_time = mktime(23,59,59,date("m",$date),date("d",$date)-1,date("Y",$date));
                $last_day = App\Models\XgConsumption::where("date",'>=', $last_day_start_time)->where('date','<',$last_day_end_time)->sum("money");
                //当天
                $day_start_time = mktime(0, 0 , 0,date("m",$date),date("d",$date),date("Y",$date));
                $day_end_time = $date;
                $day = App\Models\XgConsumption::where("date",'>=', $day_start_time)->where('date','<',$day_end_time)->sum("money");
                $str =  '今日消费：'.$day."元\n";
                $str .=  '昨日消费：'.$last_day."元\n";
                $str .=  '本周消费：'.$weeks."元\n";
                $str .=  '上周消费：'.$last_weeks."元\n";
                $str .=  '本月消费：'.$month."元\n";
                $str .=  '上月消费：'.$last_month."元\n";
                $str .=  '本月平均消费：'.round($month/date("j",$date),2).'元';
                return $str;
                break;
            case 3:
                //指定日期
                $time = strtotime($day);
                $daylist = App\Models\XgConsumption::where("date",$time)->get();
                $daylist = json_decode(json_encode($daylist), true);
                $allmoney = array_sum(array_column($daylist,'money'));
                $xg_consume_type = App\Models\XgConsumeType::get()->toArray();
                $array = array_column($xg_consume_type,'name');
                $str = $day."消费\n";
                foreach ($daylist as $k=>$v){
                    $str.=$array[$v['type']-1].'：'.$v['money']."\n";
                }
                $str.='合计：'.$allmoney.'元';
                return $str;
                break;
        }
    }

    //账号密码
    private function pwd($str,$type=1){
        $content_arr = explode('|',$str);
        switch ($type) {
            case 0:
                if (count($content_arr) != 4) {
                    return '模板格式错误！';
                }
                $pwd_key = $redis_key = config('common.PWD_KEY');
                if (substr($content_arr[3],-1)=='+'){
                    $content_arr[3] =  substr($content_arr[3],0,strlen($content_arr[3])-1).".";
                }
                $pwd_arr = [$pwd_key,$content_arr[3]];
                $pwd =  base64_encode(implode('|',$pwd_arr));
                $data['name'] = trim($content_arr[1]);
                $data['account'] = trim($content_arr[2]);
                $data['pwd'] = $pwd;
                $info = App\Models\XgPasswords::where('name', $content_arr[1])->first();
                if ($info){
                    return '该记录已存在！';
                }
                $result = App\Models\XgPasswords::insert($data);
                return $result?'保存成功，请删除记录哦！':'保存失败！';
                break;
            case 1:
                if (count($content_arr) != 4) {
                    return '模板格式错误！';
                }
                $pwd_key = $redis_key = config('common.PWD_KEY');
                if (substr($content_arr[3],-1)=='+'){
                    $content_arr[3] =  substr($content_arr[3],0,strlen($content_arr[3])-1).".";
                }
                $pwd_arr = [$pwd_key,$content_arr[3]];
                $pwd =  base64_encode(implode('|',$pwd_arr));
                $data['name'] = trim($content_arr[1]);
                $data['account'] = trim($content_arr[2]);
                $data['pwd'] = $pwd;
                $data['key_level'] = 1;
                $info = App\Models\XgPasswords::where('name', $content_arr[1])->first();
                if ($info){
                    return '该记录已存在！';
                }
                $result = App\Models\XgPasswords::insert($data);
                return $result?'保存成功，请删除记录哦！':'保存失败！';
                break;
            case 2:
                $result = App\Models\XgPasswords::where('name', $content_arr[0])->first();
                if (!$result || !isset($result['pwd'])){
                    return false;
                }
                $pwd_arr = explode('|',base64_decode($result['pwd']));
                if ($result['key_level']==1){
                    $pwd =  $this->pwd_view($pwd_arr[1]);
                }else{
                    $pwd =  $pwd_arr[1];
                }
                $str = $result['account']?'账号：'.$result['account']:'';
                $str .= "\n密码：".$pwd;
                return $str;
                break;
            case 3:
                if (count($content_arr) != 2) {
                    return '模板格式错误！';
                }
                $result = App\Models\XgPasswords::where('name', $content_arr[1])->delete();
                return $result?'删除成功！':'删除失败！';
                break;
            case 4:
                $result = App\Models\XgPasswords::get('name');
                $str = '所有密码名称';
                foreach ($result as $v){
                    $str .=  "\n".$v['name'];
                }
                return $str;
                break;
        }
    }

    //密码显示
    private function pwd_view($str){
        $array = ['a'=>'~','b'=>':','c'=>'-','d'=>'@','e'=>'3','f'=>'#','g'=>'%',"h"=>'’', "i"=>8, "j"=>'“', "k"=>'*', "l"=>'?', "m"=>'/', "n"=>';', "o"=>9, "p"=>0, "q"=>1, "r"=>4, "s"=>'!', "t"=>5, "u"=>7, "v"=>'_', "w"=>2, "x"=>')', "y"=>6, "z"=>'(', 'A'=>'[~]','B'=>'[:]','C'=>'[-]','D'=>'[@]','E'=>'[3]','F'=>'[#]','G'=>'[%]',"H"=>'[’]', "I"=>'[8]', "J"=>'[“]', "K"=>'[*]', "L"=>'[?]', "M"=>'[/]', "N"=>'[;]', "O"=>'[9]', "P"=>'[0]', "Q"=>'[1]', "R"=>'[4]', "S"=>'[!]', "T"=>'[5]', "U"=>'[7]', "V"=>'[_]', "W"=>'[2]', "X"=>'[)]', "Y"=>'[6]', "Z"=>'[(]','.'=>'+'];
        $str_arr = str_split($str);
        $return_str = '';
        //取现在分支个位
        $minute_one = substr(date('i'), 1);
        foreach ($str_arr as $v){
            if (is_numeric($v)){
                //取个位
                $num = $v+$minute_one;
                $f_str = is_numeric(substr($return_str,-1))?'':'.';
                if (strlen($num)==2){
                    $return_str.=$f_str.substr($v+$minute_one, 1);
                }else{
                    $return_str.=$f_str.($v+$minute_one);
                }
            }else{
                $f_str = is_numeric(substr($return_str,-1))?'.':'';
                $return_str.=$f_str.$array[$v];
            }
        }
        $f_str = is_numeric(substr($return_str,-1))?'.':'';
        $return_str .= $f_str;
        return $return_str;
    }
}
