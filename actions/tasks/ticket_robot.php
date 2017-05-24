#!/usr/bin/php
<?php
/**
 * Usage: 工单机器人，用于指派工单，创建工单申请的资源，根据工单状态更新资源状态等
 * File Name: ticket_robot.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2017-05-24 13:10:27
 **/
require dirname(__FILE__).'/../etc/config.php';

$ID = getenv("ID");
// 默认UserRequest，action配置时需要设置参数 FINALCLASS=$this->finalclass$
$FINALCLASS = getenv("FINALCLASS");
$log = dirname(__FILE__) . '/../' . $config['tasklogdir'] . "/" .  end(explode("/", $argv[0])) . ".log";

$FINALCLASS==false ? $sClass = "UserRequest" : $sClass = $FINALCLASS;

$query = "SELECT " . $sClass . " WHERE id='" . $ID . "'";

$data = json_decode($iTopAPI->coreGet($sClass, $query), true);

$ret = null;

/**
 * 资源申请工单创建资源对象
 * 要求：request template中Fields必须在对应类中存在，比如 name对应name，location对应location
 * 允许存在tips开头的Fields，该函数忽略tips开头的Fields
 * 工单创建时，新建对象，状态设置为实施，工单完成时，更改对象状态（上线或者废弃）
 */
function CreateObj($oClass, $Ticket)
{
	global $iTopAPI;
	if($oClass == "Server")
	{
		return;
	}
	$fields = array();
	$fields['org_id'] = $Ticket['fields']['org_id'];
	$fields['description'] = $Ticket['fields']['description'];
	$fields['status'] = "implementation";

	$user_data = $Ticket['fields']['service_details']['user_data'];
	foreach($user_data as $k => $v)
	{
		if(preg_match("/^tips/", $k))
		{
			continue;
		}elseif($k == "applicationsolution_list")
		{
			$fields[$k] = array(array("applicationsolution_id"=>$v));
		}else
		{
			$fields[$k] = $v;
		}
	}
	$oNew = json_decode($iTopAPI->coreCreate($oClass, $fields), true);
	$tFields = array();
	if($oNew['code'] == 0)
	{
		$oKey = reset($oNew['objects'])['key'];
		$tFields['functionalcis_list'] = array(array('functionalci_id' => $oKey));
		$tFields['public_log'] = "资源创建成功: " . reset($oNew['objects'])['fields']['friendlyname'];
	}else{
		$tFields['public_log'] = "资源创建异常: " . $oNew['message'];
	}

	$upTk = $iTopAPI->coreUpdate("UserRequest", $Ticket['key'], $tFields);
	return($upTk);
}

/**
 * 创建事件之后，根据事件关联的APP自动更新事件的配置项、联系人
 * 并且自动分配事件给该联系人
 */
function UpdateIncident()
{}

/**
 * 指派工单
 * 根据配置文件自动指派工单（排班）
 */
function AssignUserRequest($Ticket)
{
	if($Ticket['fields']['finalclass'] != "UserRequest")
	{
		return;
	}

	global $iTopAPI;
	global $config;
	$servicesubcategory = $Ticket['fields']['servicesubcategory_name'];
	extract($config['ticket']);
	
	// get oAssign
	$agent = NULL;
	if(is_array($special) && array_key_exists($servicesubcategory, $special))
	{
		$agent = $special[$servicesubcategory];
	}elseif(is_array($plan))
	{
		$week = date("W", time());
		$len = count($plan);
		$agent = $plan[$week%$len];
	}

	// 自动指派用户请求
	if($agent && $opsteam)
	{
		$ret = json_decode($iTopAPI->coreApply_stimulus('UserRequest', $Ticket['key'], array(
			'agent_id' => array("login"=>$agent),
			'team_id' => array("name" => $opsteam)
		),'ev_assign'),true);

		if($ret['code'] == 0)
		{
			$msg = "自动指派成功: 指派给" . $agent; 
		}else
		{
			$msg = "自动指派失败: " . $ret['message'];
		}
	}

	$iTopAPI->coreUpdate("UserRequest", $Ticket['key'], array(
		"public_log" => $msg
	));
}

/**
 * 获取指派信息(用于事件管理)
 * 根据联系人所在组织的交付模式获取该交付模式的联系人（团队），判断该联系人的团队ID
 */
function GetAssignInfo()
{
}

// 更新对象
function UpdateObj()
{}


if($data['objects'])
{
	/** 
	 * 这里需要取service_details, 2.3.3的rest api不支持AttributeCustom类型的Fields，需要打补丁
	 * 补丁在 ../itop_restapi_2.3.3.patch
	 */
	$Ticket = reset($data['objects']);
	$user_data = $Ticket['fields']['service_details']['user_data'];
	$template_id = $Ticket['fields']['service_details']['template_id'];
	$template = json_decode($iTopAPI->coreGet('RequestTemplate', $template_id), true);
	$template = reset($template['objects']);
	$oClass = $template['fields']['relatedclass'];
	$type = $template['fields']['type'];

	switch($type) {
		case "new": CreateObj($oClass, $Ticket); AssignUserRequest($Ticket);break;
		default:break;
	}

}

file_put_contents($log , $config['datetime'] . " - $ID - $sClass - $ret \n", FILE_APPEND);
