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
//cf_ -> custom fields
define("cf_server_name", "Server name");
define("cf_server_rcon", "RCON password");
define("cf_ftp_passwd",  "FTP password");
//co_ -> config options
define("co_slots",		 "Slots");
define("co_debranding",  "Debranding");
// mail template
define("createaccount",  "OGP Account Welcome Email");
   

function numberslist($maxqty) {
	$qty = 0;
	
	$string = 'N/A,';
	
	while($qty != $maxqty)
	{
		$qty++;
		$string .= $qty.',';
	}
	return $string;
}

function send_request($panel_url,$method,$postfields) {
	$settings[$method] = "true";
	$postfields = array_merge($settings,$postfields);
	
	$c = curl_init();
	curl_setopt($c, CURLOPT_URL,            $panel_url.'/api.php');
	curl_setopt($c, CURLOPT_POST,           true);
	curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($c, CURLOPT_POSTFIELDS,     $postfields );
	curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($c, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);
	$result = curl_exec($c);
	
	if($result === false) {
		$error = sprintf('Curl Error: "%s".', curl_error($c));
		curl_close($c);
		return $error;
	}
	
	curl_close($c);

	$json = json_decode($result, true);
	
	if(!is_array($json)) {
		return "Connection failure. Check up Username and Password fields at 'Server Details'.";
	}

	if(isset($json['error']) && !empty($json['error'])) {
		return sprintf('Method "%s" returned error: "%s"', $method, $json['error']['message']);
	}

	if(!isset($json['result'])) {
		return "Invalid response. Unknown error.";
	}

	return $json;
}

function get_access_rights($params)
{
	$access_rigths = "";
	if($params["configoption2"])
		$access_rigths .= "u";
	if($params["configoption4"])
		$access_rigths .= "f";
	if($params["configoption6"])
		$access_rigths .= "p";
	if($params["configoption8"])
		$access_rigths .= "e";
	if($params["configoption10"])
		$access_rigths .= "t";
	if($params["configoption12"])
		$access_rigths .= "c";
	return $access_rigths;
}

function ogp_ConfigOptions() {

	# Should return an array of the module options for each product - maximum of 24
	$configarray = array(
	 "Panel URL" => array( "Type" => "text", "Size" => "80", "Description" => "The URL to your panel - must include http://, no trailing slash , accepts IP, domain, subdomain and/or subfolders.<br>Example: <b>http://example.com/panel</b>" ),
	 'Allow updates' => array( "Type" => "yesno", "Description" => "Tick to grant access", "Default" => "yes" ),
	 "Remote Server ID" => array( "Type" => "text", "Size" => "5", "Description" => "Find it in your OGP site at Administration -> Servers, under 'Configured Remote Host' (#ID:).<br>Example: <b>1</b>" ),
	 'Allow file management' => array( "Type" => "yesno", "Description" => "Tick to grant access", "Default" => "yes" ),
	 "HomeCfgID-ModCfgID" => array( "Type" => "text", "Size" => "12", "Description" => "Browse OGP API (http://xxxxxxxxx/api.php) to get the correct id pair for the desired game, mod and OS.<br>Example: <b>35-39</b>" ),
	 'Allow parameter usage' => array( "Type" => "yesno", "Description" => "Tick to grant access", "Default" => "yes" ),
	 "Installation Type" => array( "Type" => "dropdown", "Options" => "steam,rsync,manual,master" ),
	 'Allow extra params' => array( "Type" => "yesno", "Description" => "Tick to grant access", "Default" => "yes" ),
	 "Game Package URL" => array( "Type" => "text", "Size" => "100", "Description" => "URL to zip or tar.gz file.<br><b>Only if Installation Type is 'manual', otherwise leave it empty.</b>" ),
	 'Allow FTP' => array( "Type" => "yesno", "Description" => "Tick to grant access", "Default" => "yes" ),
	 "Force IP" => array( "Type" => "text", "Size" => "16", "Description" => "By default the OGP API uses the first available IP, if you set an IP address here it will be used instead, otherwise leave it empty.<br><b>Be sure to use an IP address configured for the selected remote host, otherwise the installation will fail.</b>" ),
	 'Allow custom fields' => array( "Type" => "yesno", "Description" => "Tick to grant access", "Default" => "yes" )
	);

	return $configarray;
}

function ogp_CreateAccount($params) {

	$customfields = $params["customfields"]; # Array of custom field values for the product
	$configoptions = $params["configoptions"]; # Array of configurable option values for the product
	
	# User's name for the OGP account. OGP does not accept duplicated user names, so is preferable to use the email address as user name.
	$username = $params["clientsdetails"]['email'];
	
	# Service Configuration
	$panel_url = $params["configoption1"];
	$rserver_id = $params["configoption3"];
	$cfg_ids = $params["configoption5"];
	$installation_type = $params["configoption7"];
	$manual_url = $params["configoption9"];
	$force_ip = $params["configoption11"];
	
	$postfields['adminlogin'] = $params["serverusername"];
	$postfields['adminpassword'] = $params["serverpassword"];
	$postfields['user_email'] = $params["clientsdetails"]['email'];
	//Check if there is any other service assigned to this user in the panel
	$get_main = send_request($panel_url,'get_main_serviceid',$postfields);
	
	if(is_array($get_main))
	{
		$main_serviceid = $get_main['result'];
		$table = "tblhosting";
		$fields = "password";
		$where = array("id"=>$main_serviceid);
		$result = select_query($table,$fields,$where);
		$data = mysql_fetch_array($result);
		$update = array("password"=>$data['password']);
		$where = array("id"=>$params["serviceid"]);
		update_query($table,$update,$where);
	}
	//Check if the game is TeamSpeak3
	$postfields['home_cfg_id-mod_cfg_id'] = $cfg_ids;
	$postfields['rserver_id'] = $rserver_id;
	$ts3 = send_request($panel_url,'is_ts3',$postfields);
	if(is_array($ts3))
		$postfields['ts3_home_id'] = $ts3['result'];
	
	$postfields['upassword'] = $params["password"];
	$postfields['ulogin'] = $username;
	$postfields['serviceid'] = $params["serviceid"];
	$postfields['server_name'] = $customfields[cf_server_name];
	$postfields['maxplayers'] = $configoptions[co_slots];
	$postfields['server_rcon'] = $customfields[cf_server_rcon];
	$postfields['ftp_passwd'] = $customfields[cf_ftp_passwd];
	$postfields['debaranding'] = $configoptions[co_debranding];
	$postfields['installation'] = $installation_type;
	$postfields['access_rights'] = get_access_rights($params);
	$postfields['force_ip'] = $force_ip;
	$postfields['url'] = $manual_url;

	$response = send_request($panel_url,'createaccount',$postfields);
	
	$extravars = array( 'ogp_site' => $params["configoption1"].'/index.php', 
						'ogp_user' => $username );
	sendMessage(createaccount,$params["serviceid"],$extravars);
	
	if (is_array($response)) {
		$result = "success";
	} else {
		$result = $response;
	}
	return $result;
}

function ogp_TerminateAccount($params) {

	$postfields['adminlogin'] = $params["serverusername"];
	$postfields['adminpassword'] = $params["serverpassword"];
	$postfields['serviceid'] = $params["serviceid"];
	
	$response = send_request($params["configoption1"],'terminateaccount',$postfields);
	if (is_array($response)) {
		$result = "success";
	} else {
		$result = $response;
	}
	return $result;
}

function ogp_SuspendAccount($params) {

	$postfields['adminlogin'] = $params["serverusername"];
	$postfields['adminpassword'] = $params["serverpassword"];
	$postfields['serviceid'] = $params["serviceid"];
	
	$response = send_request($params["configoption1"],'suspendaccount',$postfields);
	if (is_array($response)) {
		$result = "success";
	} else {
		$result = $response;
	}
	return $result;
}

function ogp_UnsuspendAccount($params) {

	$postfields['adminlogin'] = $params["serverusername"];
	$postfields['adminpassword'] = $params["serverpassword"];
	$postfields['serviceid'] = $params["serviceid"];
	$postfields['access_rights'] = get_access_rights($params);
	
	$response = send_request($params["configoption1"],'unsuspendaccount',$postfields);
	if (is_array($response)) {
		$result = "success";
	} else {
		$result = $response;
	}
	return $result;
}

function ogp_ChangePassword($params) {
	
	$panel_url = $params["configoption1"];
	$postfields['adminlogin'] = $params["serverusername"];
	$postfields['adminpassword'] = $params["serverpassword"];
	$postfields['user_email'] = $params["clientsdetails"]['email'];
	$response = send_request($panel_url,'get_user_serviceids',$postfields);
	if(is_array($response))
	{
		$table = "tblhosting";
		$fields = "password";
		$where = array("id"=>$params["serviceid"]);
		$result = select_query($table,$fields,$where);
		$data = mysql_fetch_array($result);
		$update = array("password"=>$data['password']);
		$serviceids = $response['result'];
		foreach($serviceids as $serviceid)
		{
			if($serviceid["serviceid"] != $params["serviceid"])
			{
				$where = array("id"=>$serviceid["serviceid"]);
				update_query($table,$update,$where);
			}
		}
	}
	$postfields['upassword'] = $params["password"];
	$response = send_request($panel_url,'changepassword',$postfields);
	if (is_array($response)) {
		$result = "success";
	} else {
		$result = $response;
	}
	return $result;
}

function ogp_ChangePackage($params) {

	# Code to perform action goes here...

	if ($successful) {
		$result = "success";
	} else {
		$result = "Not Implemented Yet...";
	}
	return $result;

}

function ogp_ClientArea($params) {

	$customfields = $params["customfields"]; # Array of custom field values for the product
	# User's name for the OGP account. OGP does not accept duplicated user names, so is preferable to use the email address as user name.
	$username = $params["clientsdetails"]['email'];
	
	return '<form action="'.$params["configoption1"].'/index.php" method="post" target="_blank">
			  <input type="hidden" name="ulogin" value="'.$username.'" />
			  <input type="hidden" name="upassword" value="'.$params["password"].'" />
			  <input type="hidden" name="login"/>
			  <input type="submit" value="Login to Control Panel" />
			 </form>';
}

function ogp_AdminLink($params) {
	return '<a href="'.$params["configoption1"].'/index.php" >Go to Control Panel</a>';
}

function ogp_LoginLink($params) {
	return '<a href="'.$params["configoption1"].'/index.php" >Go to Control Panel</a>';
}

function ogp_reboot($params) {

	# Code to perform reboot action goes here...

	if ($successful) {
		$result = "success";
	} else {
		$result = "Not Implemented Yet...";
	}
	return $result;

}

function ogp_shutdown($params) {

	# Code to perform shutdown action goes here...

	if ($successful) {
		$result = "success";
	} else {
		$result = "Not Implemented Yet...";
	}
	return $result;

}

function ogp_ClientAreaCustomButtonArray() {
	$buttonarray = array(
	 // "Reboot Server" => "reboot",
	);
	return $buttonarray;
}

function ogp_AdminCustomButtonArray() {
	$buttonarray = array(
	 // "Reboot Server" => "reboot",
	 // "Shutdown Server" => "shutdown",
	);
	return $buttonarray;
}

function ogp_extrapage($params) {
	$pagearray = array(
	 // 'templatefile' => 'example',
	 // 'breadcrumb' => ' > <a href="#">Example Page</a>',
	 // 'vars' => array(
		// 'var1' => 'demo1',
		// 'var2' => 'demo2',
	 // ),
	);
	return $pagearray;
}

function ogp_UsageUpdate($params) {

	$serverid = $params['serverid'];
	$serverhostname = $params['serverhostname'];
	$domain = $params["domain"];
	$serverusername = $params['serverusername'];
	$serverpassword = $params['serverpassword'];
	$serveraccesshash = $params['serveraccesshash'];
	$serversecure = $params['serversecure'];

	# Run connection to retrieve usage for all domains/accounts on $serverid

	# Now loop through results and update DB

	foreach ($results AS $domain=>$values) {
		update_query("tblhosting",array(
		 "diskused"=>$values['diskusage'],
		 "dislimit"=>$values['disklimit'],
		 "bwused"=>$values['bwusage'],
		 "bwlimit"=>$values['bwlimit'],
		 "lastupdate"=>"now()",
		),array("server"=>$serverid,"domain"=>$values['domain']));
	}

}

function ogp_AdminServicesTabFields($params) {

	$result = select_query("mod_customtable","",array("serviceid"=>$params['serviceid']));
	$data = mysql_fetch_array($result);
	$var1 = $data['var1'];
	$var2 = $data['var2'];
	$var3 = $data['var3'];
	$var4 = $data['var4'];

	$fieldsarray = array(
		 // 'Field 1' => '<input type="text" name="modulefields[0]" size="30" value="'.$var1.'" />',
		 // 'Field 2' => '<select name="modulefields[1]"><option>Val1</option</select>',
		 // 'Field 3' => '<textarea name="modulefields[2]" rows="2" cols="80">'.$var3.'</textarea>',
		 // 'Field 4' => $var4, # Info Output Only
	);
	return $fieldsarray;

}

function ogp_AdminServicesTabFieldsSave($params) {
	update_query("mod_customtable",array(
		"var1"=>$_POST['modulefields'][0],
		"var2"=>$_POST['modulefields'][1],
		"var3"=>$_POST['modulefields'][2],
	),array("serviceid"=>$params['serviceid']));
}

?>
