<?php
/**
 * Usage: 由于zabbix自动注册没有删除机制（例如以主机名为规则注册，当服务器主机名变更时，会再次注册，
 * 并不删除原主机名的监控），导致inventory中有相同SN的服务器，对CMDB审计造成影响。此脚本用于定时找出
 * 这样的zabbix host，并全部删除，让机器重新注册。另外，实测发现如果zabbix-agent未重启，则注册的主
 * 机名不会更新。因此zabbix-agent需要定时重启。
 *
 * File Name: audit_zabbix.php
 * Author: annhe  
 * Mail: i@annhe.net
 * Created Time: 2016-10-28 11:48:33
 **/

require dirname(__FILE__).'/../etc/config.php';
require dirname(__FILE__).'/../composer/vendor/confirm-it-solutions/php-zabbix-api/build/ZabbixApi.class.php';

define('ZBXURL', $config['zabbix']['url']);
define('ZBXUSER', $config['zabbix']['user']);
define('ZBXPWD', $config['zabbix']['password']);

$zbxAPI = new \ZabbixApi\ZabbixApi(ZBXURL, ZBXUSER, ZBXPWD);

// zabbix查询host接口
function zabbixHostGet($name)
{
	global $zbxAPI;
	$param = array(
		"output" => array("host","inventory"),
		"selectInventory" => array("asset_tag", "vendor", "model", "tag", "notes"),
		"searchInventory" => array("asset_tag" => $name)
	);
	$data = $zbxAPI->hostGet($param);
	return($data);
}

// zabbix获取所有有asset_tag的服务器
function zabbixAllHostGet()
{
	return(json_decode(json_encode(zabbixHostGet("")), true));
}

// sn审计
function main()
{
	global $zbxAPI;
	$conflict = array();
	// 先一次性取出zabbix中所有的host，并组装成key为sn的数组
	$zbxServers = zabbixAllHostGet();
	$zbxAll = array();
	foreach($zbxServers as $server)
	{
		$sn = $server['inventory']['asset_tag'];
		// sn未成功录入inventory的忽略
		if($sn == "")
		{
			continue;
		}
		$hostid = $server['hostid'];
		if(array_key_exists($sn, $zbxAll))
		{
			array_push($conflict, $hostid, $zbxAll[$sn]['hostid']);
		}else
		{
			$zbxAll[$sn] = $server;
		}
	}

	$ret = array();
	foreach($conflict as $host)
	{
		$param = array($host);
		try{
			$ret[$host] = $zbxAPI->hostDelete($param);
		}
		catch(Exceptioin $e)
		{
			$ret[$host] = "failed";
		}
	}
	return($ret);
}

die(json_encode(main()));
