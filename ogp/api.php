<?php
/*
 *
 * OGP - Open Game Panel
 * Copyright (C) Copyright (C) 2008 - 2014 The OGP Development Team
 *
 * http://www.opengamepanel.org/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 *
 */
// Report all PHP errors
error_reporting(E_ALL);
// Path definitions
define("INCLUDES", "includes/");
define("MODULES", "modules/");
define("CONFIG_FILE",INCLUDES."config.inc.php");
require_once(INCLUDES."functions.php");
require_once(INCLUDES."helpers.php");
require_once(INCLUDES."html_functions.php");
require_once CONFIG_FILE;
// Connect to the database server and select database.
$db = createDatabaseConnection($db_type, $db_host, $db_user, $db_pass, $db_name, $table_prefix);
$settings = $db->getSettings();
function error($message){
	echo json_encode(array('error' => array('message' => $message)));
	exit();
}
function result($result){
	echo json_encode(array('result' => $result));
	exit();
}
if(isset($_POST['adminlogin']) and isset($_POST['adminpassword']))
{
	$adminInfo = $db->getUser($_POST['adminlogin']);
	// If result matched $myusername and $mypassword, table row must be 1 row
	if(isset($adminInfo['users_passwd']) and md5($_POST['adminpassword']) == $adminInfo['users_passwd'] and $adminInfo['users_role'] == 'admin')
	{
		$whmcs = $db->query('SELECT * FROM `OGP_DB_PREFIXwhmcs`');
		if(!$whmcs)
		{
			$db->query("CREATE TABLE IF NOT EXISTS `OGP_DB_PREFIXwhmcs` (
						`serviceid` int(11) NOT NULL,
						`home_id` int(11) NOT NULL,
						`user_id` int(11) NOT NULL,
						`ip_id` int(11) NOT NULL,
						`port` int(11) NOT NULL,
						`main` tinyint(1) NULL,
						UNIQUE KEY serviceid (`serviceid`)
						) ENGINE=MyISAM DEFAULT CHARSET=latin1;");
		}
		if(isset($_POST['is_ts3']))
		{
			list($home_cfg_id, $mod_cfg_id) = explode("-", $_POST['home_cfg_id-mod_cfg_id']);
			$game_cfg = $db->getGameCfg($home_cfg_id);
			if(!$game_cfg)
			{
				error("The given IDs don't match any game.");
			}
			else
			{
				require(MODULES."config_games/server_config_parser.php");
				$server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$game_cfg['home_cfg_file']);
				if($server_xml->protocol == "teamspeak3")
				{
					$remote_server_id = trim($_POST['rserver_id']);
					$query = "SELECT home_id FROM `OGP_DB_PREFIXserver_homes` WHERE `home_cfg_id`=$home_cfg_id AND `remote_server_id`=$remote_server_id;";
					$ts3_game_homes = $db->resultQuery($query);
					if(!$ts3_game_homes)
						error("There is no TS3 server installed at given remote server #ID.");
					else
						result($ts3_game_homes[0]['home_id']);
				}
				else
					error("Not a TeamSpeak3 home_cfg_id.");
			}
		}
		elseif(isset($_POST['get_main_serviceid']))
		{
			$userInfo = $db->getUserByEmail($_POST['user_email']);
			if(is_array($userInfo))
			{
				$whmcs_service = $db->resultQuery("SELECT serviceid FROM `OGP_DB_PREFIXwhmcs` WHERE `main` = 1 AND `user_id`=$userInfo[user_id]");
				$serviceid = $whmcs_service[0]['serviceid'];
				if(is_numeric($serviceid))
				{
					result($serviceid);
				}
				else
					error("There is no service assigned to this user yet.");
			}
			else
				error("Can't find user with given email address (".$_POST['user_email'].").");
		}
		if(isset($_POST['get_user_serviceids']))
		{
			$userInfo = $db->getUserByEmail($_POST['user_email']);
			if(is_array($userInfo))
			{
				$serviceids = $db->resultQuery("SELECT serviceid FROM `OGP_DB_PREFIXwhmcs` WHERE `user_id`=$userInfo[user_id]");
				if(!$serviceids)
					error("There is no services assigned to this user yet.");
				else
					result($serviceids);
			}
			else
				error("Can't find user with given email address ($_POST[user_email]).");
		}
		elseif(isset($_POST['createaccount']))
		{
			$whmcs_service = $db->resultQuery("SELECT home_id FROM `OGP_DB_PREFIXwhmcs` WHERE `serviceid`=$_POST[serviceid]");
			if(!$whmcs_service)
			{
				$userInfo = $db->getUserByEmail($_POST['user_email']);
				$main = 'NULL';
				if(!$userInfo)
				{
					$db->addUser($_POST['ulogin'],$_POST['upassword'],"user",$_POST['user_email']);
					$userInfo = $db->getUserByEmail($_POST['user_email']);
					$main = 1;
				}
				$user_id = $userInfo['user_id'];
				list($home_cfg_id, $mod_cfg_id) = explode("-", $_POST['home_cfg_id-mod_cfg_id']);
				if($_POST['debaranding'] == "1" or $_POST['debaranding'] == "")
						$hostname = $_POST['server_name'];
					else
						$hostname = $settings['panel_name']." | ".$_POST['server_name'];
				
				if(isset($_POST['ts3_home_id']))
				{
					$ts3_home_info = $db->getGameHome($_POST['ts3_home_id']);
					$addresses = $db->getHomeIpPorts($_POST['ts3_home_id']);
					require_once("protocol/TeamSpeak3/TeamSpeak3.php");
					$cfg["user"] = "serveradmin";
					$cfg["pass"] = $ts3_home_info['control_password'];
					$cfg["query"] = "10011";
					if ( $ts3_home_info['use_nat'] == 1 )
						$cfg["host"] = $ts3_home_info['agent_ip'];
					else
						$cfg["host"] = $addresses[0]['ip'];
					try
					{
						$ts3_ServerInstance = TeamSpeak3::factory("serverquery://".$cfg["user"].":".
																				   $cfg["pass"]."@".
																				   $cfg["host"].":".
																				   $cfg["query"]."/");
					}
					catch(Exception $e)
					{
						error("Error ".$e->getCode().": ".$e->getMessage());
					}
					$ip_id = $addresses[0]['ip_id'];
					$port = $db->getNextAvailablePort($ip_id,$home_cfg_id);
					$db->addGameIpPort($_POST['ts3_home_id'],$ip_id,$port);
					if (isset($ts3_home_info['ufw_status']) and $ts3_home_info['ufw_status'] == "enable")
					{
						include(INCLUDES.'lib_remote.php');
						$remote = new OGPRemoteLibrary($ts3_home_info['agent_ip'], $ts3_home_info['agent_port'], $ts3_home_info['encryption_key'], $ts3_home_info['timeout']);
						$remote->sudo_exec("ufw allow ".$port);
					}
					/* create server using given props */
					try
					{
						$new_sid = $ts3_ServerInstance->serverCreate(array("virtualserver_name" => "$hostname", "virtualserver_maxclients" => $_POST['maxplayers'], "virtualserver_port" => $port));
					}
					catch(Exception $e)
					{
						$db->delGameIpPort($ts3_home_info['home_id'],$ip_id,$port);
						if (isset($ts3_home_info['ufw_status']) and $ts3_home_info['ufw_status'] == "enable")
						{
							$remote->sudo_exec("ufw deny ".$port);
						}
						error("Error ".$e->getCode().": ".$e->getMessage());
					}
					$db->query("INSERT INTO OGP_DB_PREFIXts3_homes (`rserver_id`, `ip`, `pwd`, `vserver_id`, `user_id`) VALUES ('".$ts3_home_info['remote_server_id']."', '".
																																   $addresses[0]['ip']."', '".
																																   $cfg["pass"]."', '".
																																   $new_sid['sid']."', '".
																																   $user_id."');");
					$home_id = (0 - $new_sid['sid']);
					$ip_id = $_POST['ts3_home_id'];
				}
				else
				{
					$rserver = $db->getRemoteServer($_POST['rserver_id']);
					$game_path = "/home/".$rserver['ogp_user']."/OGP_User_Files/whmcs/";
					$home_id = $db->addGameHome($_POST['rserver_id'], $user_id, $home_cfg_id, $game_path, $hostname, $_POST['server_rcon'], $_POST['ftp_passwd']);
					if($_POST['force_ip'] != "")
					{
						$ip_id = $db->getIpIdByIp($_POST['force_ip']);
						$port = $db->getNextAvailablePort($ip_id,$home_cfg_id);
					}
					else
					{
						$remote_server_ips = $db->getRemoteServerIPs($_POST['rserver_id']);
						foreach($remote_server_ips As $ip_info)
						{
							$ip_id = $ip_info['ip_id'];
							$port = $db->getNextAvailablePort($ip_id,$home_cfg_id);
							If($port){
								break;
							}
						}
					}
					$db->addGameIpPort($home_id, $ip_id, $port);
					$mod_id = $db->addModToGameHome($home_id, $mod_cfg_id);
					$db->updateGameModParams($_POST['maxplayers'], "", "NA", "0", $home_id, $mod_cfg_id);
					$db->assignHomeTo("user", $user_id, $home_id, $_POST['access_rights']);
					$home_info = $db->getGameHome($home_id);
					include(INCLUDES.'lib_remote.php');
					$remote = new OGPRemoteLibrary($home_info['agent_ip'], $home_info['agent_port'], $home_info['encryption_key'], $home_info['timeout']);
					if(preg_match("/t/", $_POST['access_rights']))
					{
						$remote->ftp_mgr("useradd", $home_id, $home_info['ftp_password'], $home_info['home_path']);
						$db->changeFtpStatus('enabled', $home_id);
					}
					// Getting pre and post commands
					$precmd = $home_info['mods'][$mod_id]['precmd'] == "" ? $home_info['mods'][$mod_id]['def_precmd'] : $home_info['mods'][$mod_id]['precmd'];
					$postcmd = $home_info['mods'][$mod_id]['postcmd'] == "" ? $home_info['mods'][$mod_id]['def_postcmd'] : $home_info['mods'][$mod_id]['postcmd'];
					// Starting Game server installation
					if($_POST['installation'] == "steam")
					{
						require(MODULES."config_games/server_config_parser.php");
						$server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$home_info['home_cfg_file']);
						if($server_xml->installer == "steamcmd")
						{
							$exec_folder_path = clean_path($home_info['home_path'] . "/" . $server_xml->exe_location);
							$exec_path = clean_path($exec_folder_path . "/" . $server_xml->server_exec_name);
							$mod_xml = xml_get_mod($server_xml, $home_info['mods'][$mod_id]['mod_key']);
							$installer_name = $mod_xml->installer_name;
							$modkey = $home_info['mods'][$mod_id]['mod_key'];
							// Some games like L4D2 require anonymous login
							if($mod_xml->installer_login){
								$login = $mod_xml->installer_login;
								$pass = '';
							}else{
								$login = $settings['steam_user'];
								$pass = $settings['steam_pass'];
							}
							$modname = ( $installer_name == '90' and !preg_match("/(cstrike|valve)/", $modkey) ) ? $modkey : '';
							$betaname = isset($mod_xml->betaname) ? $mod_xml->betaname : '';
							$betapwd = isset($mod_xml->betapwd) ? $mod_xml->betapwd : '';
							$arch = isset($mod_xml->steam_bitness) ? $mod_xml->steam_bitness : '';
							if(preg_match("/win(32|64)/", $server_xml->game_key))
								$os = "windows";
							elseif(preg_match("/linux/", $server_xml->game_key))
								$os = "linux";
							$remote->steam_cmd($home_id,$home_info['home_path'],$installer_name,$modname,
											   $betaname,$betapwd,$login,$pass,$settings['steam_guard'],
											   $exec_folder_path,$exec_path,$precmd,$postcmd,$os,'',$arch);
						}
						else
							error('This game is not supported by Steam installation.');
					}
					elseif($_POST['installation'] == "rsync")
					{
						require(MODULES."config_games/server_config_parser.php");
						$server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$home_info['home_cfg_file']);
						if(isset($server_xml->lgsl_query_name))
						{
							$rs_name = $server_xml->lgsl_query_name;
							if($rs_name == "quake3")
							{
								if($server_xml->game_name == "Quake 3")
									$rs_name = "q3";
							}
						}
						elseif(isset($server_xml->gameq_query_name))
						{
							$rs_name = $server_xml->gameq_query_name;
							if($rs_name == "minecraft")
							{
								if($server_xml->game_name == "Minecraft Tekkit")
									$rs_name = "tekkit";
								elseif($server_xml->game_name == "Minecraft Bukkit")
									$rs_name = "bukkit";
							}
						}
						elseif(isset($server_xml->protocol))
							$rs_name = $server_xml->protocol;
						else
							$rs_name = $server_xml->mods->mod['key'];
						$sync_list = @file(MODULES."gamemanager/rsync.list", FILE_IGNORE_NEW_LINES);
						if ( in_array($rs_name, $sync_list) ) 
						{
							$exec_folder_path = clean_path($home_info['home_path'] . "/" . $server_xml->exe_location);
							$exec_path = clean_path($exec_folder_path . "/" . $server_xml->server_exec_name);
							$url = "rsync.opengamepanel.org";
							if(preg_match("/win(32|64)/", $server_xml->game_key))
								$os = "windows";
							elseif(preg_match("/linux/", $server_xml->game_key))
								$os = "linux";
							$full_url = "$url/ogp_game_installer/$rs_name/$os/";
							$remote->start_rsync_install($home_id,$home_info['home_path'],"$full_url",$exec_folder_path,$exec_path,$precmd,$postcmd);
						}
						else
							error('This game is not supported by rsync installation.');
					}
					elseif($_POST['installation'] == "manual")
					{
						require(MODULES."config_games/server_config_parser.php");
						$server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$home_info['home_cfg_file']);
						
						if($_POST['url'] != "")
						{
							$postInstallCMD = "\n{OGP_LOCK_FILE} " . $home_info['home_path'] . "/" . ($server_xml->exe_location ? $server_xml->exe_location . "/" : "") . $server_xml->server_exec_name;
							$filename = basename($_POST['url']);
							$remote->start_file_download($_POST['url'],$home_info['home_path'],$filename,"uncompress",$postInstallCMD);
						}
						else
							error('The URL for manual installation is empty.');
					}
					elseif($_POST['installation'] == "master")
					{
						require(MODULES."config_games/server_config_parser.php");
						$server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$home_info['home_cfg_file']);
						$ms_home_id = $db->getMasterServer( $_POST['rserver_id'], $home_cfg_id );
						if(is_numeric($ms_home_id))
						{
							$exec_folder_path = clean_path($home_info['home_path'] . "/" . $server_xml->exe_location );
							$exec_path = clean_path($exec_folder_path . "/" . $server_xml->server_exec_name );
							$ms_info = $db->getGameHome($ms_home_id);
							$remote->masterServerUpdate($home_id,$home_info['home_path'],$ms_home_id,$ms_info['home_path'],$exec_folder_path,$exec_path,$precmd,$postcmd);
						}
						else
							error('There is no master server assigned for this game.');
					}
				}
				$db->query("INSERT INTO `OGP_DB_PREFIXwhmcs` (serviceid, home_id, user_id, ip_id, port, main) VALUES($_POST[serviceid], $home_id, $user_id, $ip_id, $port, $main);");
				result(true);
			}
			else
				error('Service already created.');
		}
		elseif(isset($_POST['terminateaccount']))
		{
			$whmcs_service = $db->resultQuery("SELECT * FROM `OGP_DB_PREFIXwhmcs` WHERE `serviceid`=$_POST[serviceid]");
			if(is_array($whmcs_service['0']))
			{
				$user_id = $whmcs_service['0']['user_id'];
				$home_id = $whmcs_service['0']['home_id'];
				if($home_id < 0)
				{
					$sid = $home_id * ( -1 );
					$ts3_home_id = $whmcs_service['0']['ip_id'];
					$ts3 = $db->resultQuery("SELECT * FROM OGP_DB_PREFIXts3_homes WHERE `vserver_id`=$sid AND `user_id`=$user_id;");
					if(!$ts3)
					{
						$db->query("DELETE FROM OGP_DB_PREFIXts3_homes WHERE vserver_id=$sid");
						$db->query("DELETE FROM `OGP_DB_PREFIXwhmcs` WHERE `serviceid`=$_POST[serviceid]");
						error("TS3 Virtual server with id $sid is not assigned to user id $user_id.");
					}
					else
					{
						$ts3_info = $ts3[0];
						$port = $whmcs_service['0']['port'];
						$ip_id = $db->getIpIdByIp($ts3_info['ip']);
						$DelVirtual = $db->delGameIpPort($ts3_home_id, $ip_id, $port);
						if ($DelVirtual)
						{
							$ts3_home_info = $db->getGameHome($ts3_home_id);
							require_once("protocol/TeamSpeak3/TeamSpeak3.php");
							$cfg["user"] = "serveradmin";
							$cfg["pass"] = $ts3_home_info['control_password'];
							$cfg["query"] = "10011";
							if ( $ts3_home_info['use_nat'] == 1 )
								$cfg["host"] = $ts3_home_info['agent_ip'];
							else
								$cfg["host"] = $ts3_info['ip'];
							try
							{
								$ts3_ServerInstance = TeamSpeak3::factory("serverquery://".$cfg["user"].":".
																						   $cfg["pass"]."@".
																						   $cfg["host"].":".
																						   $cfg["query"]."/");
							}
							catch(Exception $e)
							{
								error("Error ".$e->getCode().": ".$e->getMessage());
							}

							$ts3_ServerInstance->serverStop($sid);
							$ts3_ServerInstance->serverDelete($sid);
							$db->query("DELETE FROM OGP_DB_PREFIXts3_homes WHERE vserver_id=$sid");
							$db->query("DELETE FROM `OGP_DB_PREFIXwhmcs` WHERE `serviceid`=$_POST[serviceid]");
							result(true);
						}
						else
							error("The address of such TS3 virtual server does not exist.");
					}
				}
				else
				{
					$home_info = $db->getGameHomeWithoutMods($home_id);
					if(!$home_info)
					{
						$db->query("DELETE FROM `OGP_DB_PREFIXwhmcs` WHERE `serviceid`=$_POST[serviceid]");
						error("The server for this service has been removed manually, so the service has been removed too.");
					}
					else
					{
						$server_info = $db->getRemoteServerById($home_info['remote_server_id']);
						include(INCLUDES.'lib_remote.php');
						$remote = new OGPRemoteLibrary($server_info['agent_ip'], $server_info['agent_port'], $server_info['encryption_key'], $server_info['timeout']);
						if($db->IsFtpEnabled($home_id))
						{
							$login = isset($home_info['ftp_login']) ? $home_info['ftp_login'] : $home_id;
							$remote->ftp_mgr("userdel", $login);
							$db->changeFtpStatus('disabled',$home_id);
						}
						$user_homes = $db->getHomesFor("user_and_group",$user_id);
						include(MODULES."config_games/server_config_parser.php");
						$server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$home_info['home_cfg_file']);
						if(isset($server_xml->control_protocol_type))$control_type = $server_xml->control_protocol_type; else $control_type = "";
						$ip_id = $whmcs_service['0']['ip_id'];
						$port = $whmcs_service['0']['port'];
						$ip = $db->getIpById($ip_id);
						$remote->remote_stop_server($home_id,$ip,$port,$server_xml->control_protocol,$home_info['control_password'],$control_type,$home_info['home_path']);
						$db->unassignHomeFrom("user", $user_id, $home_id);
						$db->deleteGameHome($home_id);
						$remote->remove_home($home_info['home_path']);
						$db->query("DELETE FROM `OGP_DB_PREFIXwhmcs` WHERE `serviceid`=$_POST[serviceid]");
						$user_services = $db->resultQuery("SELECT * FROM `OGP_DB_PREFIXwhmcs` WHERE `user_id`=$user_id");
						if(!$user_services)
							$db->delUser($user_id);
						else
						{
							$main_serviceid = false;
							foreach($user_services as $user_service)
							{
								if($user_service['main'] == 1)
									$serviceid_main = true;
							}
							if(!$main_serviceid)
								$db->query("UPDATE `OGP_DB_PREFIXwhmcs` SET `main`=1 WHERE `serviceid`=".$user_services[0]['serviceid']);
						}
						result(true);
					}
				}
			}
			else
				error("Service id $_POST[serviceid] does not exists in OGP database.");
		}
		elseif(isset($_POST['suspendaccount']))
		{
			$whmcs_service = $db->resultQuery("SELECT * FROM `OGP_DB_PREFIXwhmcs` WHERE `serviceid`=$_POST[serviceid]");
			if(is_array($whmcs_service['0']))
			{
				$user_id = $whmcs_service['0']['user_id'];
				$home_id = $whmcs_service['0']['home_id'];
				if($home_id < 0)
				{
					$sid = $home_id * ( -1 );
					$ts3_home_id = $whmcs_service['0']['ip_id'];
					$ts3 = $db->resultQuery("SELECT * FROM OGP_DB_PREFIXts3_homes WHERE `vserver_id`=$sid AND `user_id`=$user_id;");
					if(!$ts3)
						error("TS3 Virtual server with id $sid is not assigned to user id $user_id.");
					else
					{
						$ts3_info = $ts3[0];
						$port = $whmcs_service['0']['port'];
						$ip_id = $db->getIpIdByIp($ts3_info['ip']);
						$ts3_home_info = $db->getGameHome($ts3_home_id);
						require_once("protocol/TeamSpeak3/TeamSpeak3.php");
						$cfg["user"] = "serveradmin";
						$cfg["pass"] = $ts3_home_info['control_password'];
						$cfg["query"] = "10011";
						if ( $ts3_home_info['use_nat'] == 1 )
							$cfg["host"] = $ts3_home_info['agent_ip'];
						else
							$cfg["host"] = $ts3_info['ip'];
						try
						{
							$ts3_ServerInstance = TeamSpeak3::factory("serverquery://".$cfg["user"].":".
																					   $cfg["pass"]."@".
																					   $cfg["host"].":".
																					   $cfg["query"]."/");
						}
						catch(Exception $e)
						{
							error("Error ".$e->getCode().": ".$e->getMessage());
						}

						$ts3_ServerInstance->serverStop($sid);
						$db->query("DELETE FROM OGP_DB_PREFIXts3_homes WHERE vserver_id=$sid");
						result(true);
					}
				}
				else
				{
					$home_info = $db->getGameHomeWithoutMods($home_id);
					if(!$home_info)
					{
						$db->query("DELETE FROM `OGP_DB_PREFIXwhmcs` WHERE `serviceid`=$_POST[serviceid]");
						error("The server for this service has been removed manually, so the service has been removed too.");
					}
					else
					{
						$server_info = $db->getRemoteServerById($home_info['remote_server_id']);
						include(INCLUDES.'lib_remote.php');
						$remote = new OGPRemoteLibrary($server_info['agent_ip'], $server_info['agent_port'], $server_info['encryption_key'], $server_info['timeout']);
						include(MODULES."config_games/server_config_parser.php");
						$server_xml = read_server_config(SERVER_CONFIG_LOCATION."/".$home_info['home_cfg_file']);
						if(isset($server_xml->control_protocol_type))$control_type = $server_xml->control_protocol_type; else $control_type = "";
						$ip_id = $whmcs_service['0']['ip_id'];
						$port = $whmcs_service['0']['port'];
						$ip = $db->getIpById($ip_id);
						$remote->remote_stop_server($home_id,$ip,$port,$server_xml->control_protocol,$home_info['control_password'],$control_type,$home_info['home_path']);
						$db->unassignHomeFrom("user", $user_id, $home_id);
						if($db->IsFtpEnabled($home_id))
						{
							$login = isset($home_info['ftp_login']) ? $home_info['ftp_login'] : $home_id;
							$remote->ftp_mgr("userdel", $login);
							$db->changeFtpStatus('disabled',$home_id);
						}
						result(true);
					}
				}
			}
			else
				error("Service id $_POST[serviceid] does not exists in OGP database.");
		}
		elseif(isset($_POST['unsuspendaccount']))
		{
			$whmcs_service = $db->resultQuery("SELECT * FROM `OGP_DB_PREFIXwhmcs` WHERE `serviceid`=$_POST[serviceid]");
			if(is_array($whmcs_service['0']))
			{
				$user_id = $whmcs_service['0']['user_id'];
				$home_id = $whmcs_service['0']['home_id'];
				if($home_id < 0)
				{
					$sid = $home_id * ( -1 );
					$ts3_home_id = $whmcs_service['0']['ip_id'];
					$port = $whmcs_service['0']['port'];
					$ts3_home_info = $db->getGameHome($ts3_home_id);
					$addresses = $db->getHomeIpPorts($ts3_home_id);
					require_once("protocol/TeamSpeak3/TeamSpeak3.php");
					$cfg["user"] = "serveradmin";
					$cfg["pass"] = $ts3_home_info['control_password'];
					$cfg["query"] = "10011";
					if ( $ts3_home_info['use_nat'] == 1 )
						$cfg["host"] = $ts3_home_info['agent_ip'];
					else
						$cfg["host"] = $addresses[0]['ip'];
					try
					{
						$ts3_ServerInstance = TeamSpeak3::factory("serverquery://".$cfg["user"].":".
																				   $cfg["pass"]."@".
																				   $cfg["host"].":".
																				   $cfg["query"]."/");
					}
					catch(Exception $e)
					{
						error("Error ".$e->getCode().": ".$e->getMessage());
					}
					$ts3_ServerInstance->serverStart($sid);
					$db->query("INSERT INTO OGP_DB_PREFIXts3_homes (`rserver_id`, `ip`, `pwd`, `vserver_id`, `user_id`) VALUES ('".$ts3_home_info['remote_server_id']."', '".
																																   $addresses[0]['ip']."', '".
																																   $cfg["pass"]."', '".
																																   $sid."', '".
																																   $user_id."');");
					result(true);
				}
				else
				{
					$home_info = $db->getGameHomeWithoutMods($home_id);
					if(!$home_info)
					{
						$db->query("DELETE FROM `OGP_DB_PREFIXwhmcs` WHERE `serviceid`=$_POST[serviceid]");
						error("The server for this service has been removed manually, so the service has been removed too.");
					}
					else
					{
						$db->assignHomeTo("user", $user_id, $home_id, $_POST['access_rights']);
						if(preg_match("/t/", $_POST['access_rights']))
						{
							include(INCLUDES.'lib_remote.php');
							$remote = new OGPRemoteLibrary($home_info['agent_ip'],$home_info['agent_port'],$home_info['encryption_key'], $home_info['timeout']);
							$login = isset($home_info['ftp_login']) ? $home_info['ftp_login'] : $home_id;
							$remote->ftp_mgr("useradd", $login, $home_info['ftp_password'], $home_info['home_path']);
							$db->changeFtpStatus('enabled',$home_id);
						}
						result(true);
					}
				}
			}
			else
				error("Service id $_POST[serviceid] does not exists in OGP database.");
		}
		elseif(isset($_POST['changepassword']))
		{
			$userInfo = $db->getUserByEmail($_POST['user_email']);
			if(!$userInfo)
			{
				error("Email $_POST[user_email] does not belong to any user of the panel.");
			}
			else
			{
				$user_id = $userInfo['user_id'];
				$db_password = md5($_POST['upassword']);
				$changepass = $db->updateUsersPassword($user_id,$db_password);
				result(true);
			}
		}
	}
}
else
{
	$game_cfgs = $db->getGameCfgs();
	echo "<table style='border:1px solid black;'>";
	echo "<tr style='border:1px solid black;' ><td style='border:1px solid black;' >Game Name</td><td style='border:1px solid black;' >Mod Name</td><td style='border:1px solid black;' >Home Cfg ID-Mod Cfg ID</td></tr>";
	foreach($game_cfgs as $cfg)
	{
		$home_cfg_id = $cfg['home_cfg_id'];
		$game_name = $cfg['game_name'];
		if(preg_match("/linux/", $cfg['game_key'])) $os = " (Linux)"; elseif(preg_match("/win/", $cfg['game_key'])) $os = " (Windows)";
		if(preg_match("/64/", $cfg['game_key'])) $arch = " (64bit)"; else $arch = " (32bit)";
		{
			$cfgs_mods = $db->getCfgMods($home_cfg_id);
			foreach($cfgs_mods as $mod)
			{
				echo "<tr style='border:1px solid black;'><td style='border:1px solid black;'>".$game_name.$os.$arch."</td><td style='border:1px solid black;' >".$mod['mod_name']."</td><td style='border:1px solid black;' ><center>".$mod['home_cfg_id']."-".$mod['mod_cfg_id']."</center></td></tr>";
			}
		}		
	}
	echo "</table>";
}
?>
