<?php

require_once(dirname(__FILE__) . "/config.php");
require_once(dirname(__FILE__) . "/logs.php");
require_once(dirname(__FILE__) . "/db.php");
require_once(dirname(__FILE__) . "/get_officialchangelog.php");
require_once(dirname(__FILE__) . "/tweet.php");

$curtime_override = 0;

if($argc>=2)
{
	//Hour is 24h.
	$tmp_month = 0;
	$tmp_day = 0;
	$tmp_year = 0;
	$tmp_hour = 0;
	$tmp_min = 0;
	$tmp_sec = 0;
	if(sscanf($argv[1], "%02u-%02u-%02u_%02u-%02u-%02u", $tmp_month, $tmp_day, $tmp_year, $tmp_hour, $tmp_min, $tmp_sec) == 6)
	{
		echo "Using the specified curtime_override.\n";
		$curtime_override = mktime($tmp_hour, $tmp_min, $tmp_sec, $tmp_month, $tmp_day, $tmp_year);
	}
	else
	{
		echo "The specified curtime_override is invalid, ignoring it.\n";
	}

	if($argc>=4)
	{
		$overridden_initial_titleid = $argv[2];
		$overridden_initial_titleversion = $argv[3];
	}
}

do_systems_soap();

function do_systems_soap()
{
	global $mysqldb, $sitecfg_workdir;

	dbconnection_start();
	if(!db_checkmaintenance(0))
	{
		init_curl();

		$query="SELECT lastreqstatus FROM ninupdates_management";
		$result=mysqli_query($mysqldb, $query);
		$numrows=mysqli_num_rows($result);
		$lastreqstatus = "";
		if($numrows>0)
		{
			$row = mysqli_fetch_row($result);
			$lastreqstatus = $row[0];
		}

		$query="SELECT system FROM ninupdates_consoles WHERE enabled!=0 || enabled IS NULL";
		$result=mysqli_query($mysqldb, $query);
		$numrows=mysqli_num_rows($result);

		for($i=0; $i<$numrows; $i++)
		{
			$row = mysqli_fetch_row($result);
			dosystem($row[0]);
		}

		$query="UPDATE ninupdates_management SET lastscan='" . date(DATE_RFC822, time()) . "'";
		$result=mysqli_query($mysqldb, $query);

		$query="SELECT lastreqstatus FROM ninupdates_management";
		$result=mysqli_query($mysqldb, $query);
		$numrows=mysqli_num_rows($result);
		if($numrows>0)
		{
			$row = mysqli_fetch_row($result);
			$lastreqstatus_new = $row[0];

			if($lastreqstatus !== $lastreqstatus_new)
			{
				if($lastreqstatus==="")$lastreqstatus = "OK";
				if($lastreqstatus_new==="")$lastreqstatus_new = "OK";

				echo "Req status changed since last scan, sending msg...\n";
				$msg = "Last request status changed. Previous: \"$lastreqstatus\". Current: \"$lastreqstatus_new\". https://www.nintendo.co.jp/netinfo/en_US/index.html";
				sendtweet($msg);
			}
		}

		close_curl();

		$query="SELECT COUNT(*) FROM ninupdates_reports, ninupdates_consoles, ninupdates_officialchangelog_pages WHERE ninupdates_reports.updatever_autoset=0 && ninupdates_reports.systemid=ninupdates_consoles.id && ninupdates_officialchangelog_pages.systemid=ninupdates_consoles.id";
		$result=mysqli_query($mysqldb, $query);
		$numrows=mysqli_num_rows($result);

		if($numrows>0)
		{
			$row = mysqli_fetch_row($result);
			$count = $row[0];

			if($count>0)
			{
				echo "Starting a get_officialchangelog task for processing $count report(s)...\n";

				$get_officialchangelog_timestamp = date("m-d-y_h-i-s");

				system("php $sitecfg_workdir/get_officialchangelog_cli.php > $sitecfg_workdir/get_officialchangelog_scheduled_out/$get_officialchangelog_timestamp 2>&1 &");
			}
		}

		$query="SELECT COUNT(*) FROM ninupdates_reports, ninupdates_consoles WHERE ninupdates_reports.updatever_autoset=1 && ninupdates_reports.wikibot_runfinished=0 && ninupdates_reports.systemid=ninupdates_consoles.id";
		$result=mysqli_query($mysqldb, $query);
		$numrows=mysqli_num_rows($result);

		if($numrows>0)
		{
			$row = mysqli_fetch_row($result);
			$count = $row[0];

			if($count>0)
			{
				echo "Starting a wikibot task for processing $count report(s)...\n";

				$wikibot_timestamp = date("m-d-y_h-i-s");

				system("php $sitecfg_workdir/wikibot.php scheduled > $sitecfg_workdir/wikibot_out/$wikibot_timestamp 2>&1 &");
			}
		}

		$query="SELECT ninupdates_reports.reportdate, ninupdates_consoles.system FROM ninupdates_reports, ninupdates_consoles WHERE ninupdates_reports.postproc_runfinished=0 && ninupdates_reports.systemid=ninupdates_consoles.id";
		$result=mysqli_query($mysqldb, $query);
		$numrows=mysqli_num_rows($result);

		if($numrows>0)
		{
			echo "Starting post-processing tasks for processing $numrows report(s)...\n";

			$postproc_timestamp = date("m-d-y_h-i-s");

			for($i=0; $i<$numrows; $i++)
			{
				$row = mysqli_fetch_row($result);
				$reportdate = $row[0];
				$sys = $row[1];

				$maincmd_str = "php $sitecfg_workdir/postproc.php $reportdate $sys";

				$cmdout = system("ps aux | grep -c \"$maincmd_str\"");
				$proc_running = 0;
				if(is_numeric($cmdout))
				{
					if($cmdout > 2)$proc_running = 1;
				}

				if($proc_running==0)
				{
					echo "Starting postproc task for $reportdate-$sys...\n";
					system("$maincmd_str > $sitecfg_workdir/postproc_out/$postproc_timestamp 2>&1 &");
				}
				else
				{
					echo "The postproc task for $reportdate-$sys wasn't finished but the process is still running, skipping process creation for it.\n";
				}
			}
		}

		dbconnection_end();
	}
}

function dosystem($console)
{
	global $mysqldb, $region, $system, $sitecfg_emailhost, $sitecfg_target_email, $sitecfg_httpbase, $sitecfg_workdir, $sysupdate_available, $soap_timestamp, $dbcurdate, $sysupdate_regions, $sysupdate_timestamp, $sysupdate_systitlehashes;

	$system = $console;
	$msgme_message = "";
	$html_message = "";
	$sysupdate_available = 0;
	$sysupdate_timestamp = "";
	$soap_timestamp = "";
	$sysupdate_regions = "";
	$dbcurdate = "";
	$sysupdate_systitlehashes = array();

	echo "System $system\n";

	$query="SELECT regions FROM ninupdates_consoles WHERE system='".$system."'";
	$result=mysqli_query($mysqldb, $query);
	$row = mysqli_fetch_row($result);
	$regions = $row[0];

	for($i=0; $i<strlen($regions); $i++)
	{
		main(substr($regions, $i, 1));
	}

	if($sysupdate_available==0)
	{
		echo "System $system: No updated titles available.\n";
	}
	else
	{
		$msgme_message = "$sitecfg_httpbase/reports.php?date=".$sysupdate_timestamp."&sys=".$system;
		$email_message = "$msgme_message";
		
		$query="SELECT ninupdates_reports.reportdate FROM ninupdates_reports, ninupdates_consoles WHERE ninupdates_consoles.system='".$system."' && ninupdates_reports.systemid=ninupdates_consoles.id && ninupdates_reports.log='report'";
		$result=mysqli_query($mysqldb, $query);
		$numrows=mysqli_num_rows($result);

		$initialscan = 0;
		if($numrows==0)$initialscan = 1;

		$updateversion = "N/A";
		if($initialscan)$updateversion = "Initial_scan";

		$query="SELECT ninupdates_reports.reportdate FROM ninupdates_reports, ninupdates_consoles WHERE ninupdates_reports.reportdate='".$sysupdate_timestamp."' && ninupdates_consoles.system='".$system."' && ninupdates_reports.systemid=ninupdates_consoles.id && ninupdates_reports.log='report'";
		$result=mysqli_query($mysqldb, $query);
		$numrows=mysqli_num_rows($result);
		if($numrows==0)
		{
			$query="SELECT id FROM ninupdates_consoles WHERE system='".$system."'";
			$result=mysqli_query($mysqldb, $query);
			$row = mysqli_fetch_row($result);
			$systemid = $row[0];

			$query = "INSERT INTO ninupdates_reports (reportdate, curdate, systemid, log, regions, updateversion, reportdaterfc, initialscan, updatever_autoset, wikibot_runfinished, postproc_runfinished) VALUES ('".$sysupdate_timestamp."','".$dbcurdate."',$systemid,'report','".$sysupdate_regions."','".$updateversion."','".$soap_timestamp."',$initialscan,0,0,0)";
			$result=mysqli_query($mysqldb, $query);
			$reportid = mysqli_insert_id($mysqldb);

			$region = strtok($sysupdate_regions, ",");
			while($region!==FALSE)
			{
				$query = "INSERT INTO ninupdates_systitlehashes (reportid, region, titlehash) VALUES ('".$reportid."','".$region."','".$sysupdate_systitlehashes[$region]."')";
				$result=mysqli_query($mysqldb, $query);

				$region = strtok(",");
			}

			$query="UPDATE ninupdates_titles SET reportid=$reportid WHERE curdate='".$dbcurdate."' && reportid=0";
			$result=mysqli_query($mysqldb, $query);
		}
		else
		{
			//this will only happen when the report row already exists and the --difflogs option was used.
			$query="UPDATE ninupdates_reports, ninupdates_consoles SET ninupdates_reports.regions='".$sysupdate_regions."' WHERE reportdate='".$sysupdate_timestamp."' && ninupdates_consoles.system='".$system."' && ninupdates_reports.systemid=ninupdates_consoles.id && log='report'";
			$result=mysqli_query($mysqldb, $query);
		}

		if($initialscan==0)echo "System $system: System update available for regions $sysupdate_regions.\n";
		if($initialscan)echo "System $system: Initial scan successful for regions $sysupdate_regions.\n";

		echo "\nSending IRC msg...\n";
		sendircmsg($msgme_message);
		sendtweet("Sysupdate detected for " . getsystem_sysname($system) . ": $msgme_message");
		echo "Sending email...\n";
        	if(!mail($sitecfg_target_email, "$system sysupdates", $email_message, "From: ninsoap@$sitecfg_emailhost"))echo "Failed to send mail.\n";

		echo "Writing to the lastupdates_csvurls file...\n";
		$msg = "$sitecfg_httpbase/titlelist.php?date=".$sysupdate_timestamp."&sys=".$system."&csv=1";
		$tmp_cmd = "echo '" . $msg . "' >> $sitecfg_workdir/lastupdates_csvurls";
		system($tmp_cmd);
	}
}

function initialize($ishac)
{
	global $mysqldb, $hdrs, $soapreq, $fp, $system, $region, $sitecfg_workdir, $soapreq_data, $httpreq_useragent, $console_deviceid;
	
	error_reporting(E_ALL);

	$regionid = "";
	$countrycode = "";

	$query="SELECT deviceid, platformid, subplatformid FROM ninupdates_consoles WHERE system='".$system."'";
	$result=mysqli_query($mysqldb, $query);
	$numrows=mysqli_num_rows($result);

	if($numrows)
	{
		$row = mysqli_fetch_row($result);
		$deviceid = $row[0];
		$platformid = $row[1];
		$subplatformid = $row[2];
		$console_deviceid = $deviceid;

		if($ishac===0)
		{
			$platformid = ($platformid << 32);
			if($subplatformid != NULL && $subplatformid != "")$platformid |= ($subplatformid << 31);

			if($platformid != NULL && $platformid != "")
			{
				$deviceid = $platformid | rand(0, 0x7fffffff);
			}
		}
	}
	else
	{
		die("Row doesn't exist in the db for system $system.\n");
	}

	$query="SELECT regionid, countrycode FROM ninupdates_regions WHERE regioncode='".$region."'";
	$result=mysqli_query($mysqldb, $query);
	$row = mysqli_fetch_row($result);

	if($numrows)
	{
		$regionid = $row[0];
		$countrycode = $row[1];
	}
	else
	{
		die("Row doesn't exist in the db for region $region.\n");
	}

	if($ishac===0)
	{
		$httpreq_useragent = "ds libnup/2.0";
	}
	else
	{
		$useragent_fw = "2.1.0-0";

		$eid = "lp1";

		$httpreq_useragent = "NintendoSDK Firmware/" . $useragent_fw . " (platform:NX; did:" . $deviceid . "; eid:" . $eid . ")";
	}

	if($ishac===0)
	{
		$soapreq_data = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>
  <soapenv:Envelope xmlns:soapenv=\"http://schemas.xmlsoap.org/soap/envelope/\"
                    xmlns:xsd=\"http://www.w3.org/2001/XMLSchema\"
                    xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\">
    <soapenv:Body>
      <GetSystemUpdateRequest xmlns=\"urn:nus.wsapi.broadon.com\">
        <Version>1.0</Version>
        <MessageId>1</MessageId>
        <DeviceId>$deviceid</DeviceId>
        <RegionId>$regionid</RegionId>
        <CountryCode>$countrycode</CountryCode>
        <Attribute>2</Attribute>
      </GetSystemUpdateRequest>
    </soapenv:Body>
  </soapenv:Envelope>";
	}

	if($ishac===0)
	{
		$hdrs = array('SOAPAction: "urn:nus.wsapi.broadon.com/GetSystemUpdate"', 'Content-Type: application/xml', 'Content-Size: '.strlen($soapreq_data), 'Connection: Keep-Alive', 'Keep-Alive: 30');
	}
	else
	{
		$hdrs = array('Accept:application/json');
	}

	init_titlelistarray();
}

function init_curl()
{
	global $curl_handle, $sitecfg_workdir, $error_FH;

	$error_FH = fopen("$sitecfg_workdir/debuglogs/error.log","w");
	$curl_handle = curl_init();
}

function close_curl()
{
	global $curl_handle, $error_FH;

	curl_close($curl_handle);
	fclose($error_FH);
}

function send_httprequest($url, $ishac)
{
	global $mysqldb, $hdrs, $soapreq, $httpstat, $sitecfg_workdir, $soapreq_data, $httpreq_useragent, $curl_handle, $system, $error_FH;

	$query="SELECT clientcertfn, clientprivfn FROM ninupdates_consoles WHERE system='".$system."'";
	$result=mysqli_query($mysqldb, $query);
	$row = mysqli_fetch_row($result);
	$clientcertfn = $row[0];
	$clientprivfn = $row[1];

	curl_setopt($curl_handle, CURLOPT_VERBOSE, true);
	curl_setopt ($curl_handle, CURLOPT_STDERR, $error_FH );

	curl_setopt($curl_handle, CURLOPT_USERAGENT, $httpreq_useragent);

	curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);

	curl_setopt($curl_handle, CURLOPT_HTTPHEADER, $hdrs);

	if($ishac===0)
	{
		curl_setopt($curl_handle, CURLOPT_POST, 1);
		curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $soapreq_data);
	}
	else
	{
		curl_setopt($curl_handle, CURLOPT_POST, 0);
	}

	curl_setopt($curl_handle, CURLOPT_URL, $url);

	if(strstr($url, "https") && $clientcertfn!="" && $clientprivfn!="")
	{
		curl_setopt($curl_handle, CURLOPT_SSLCERTTYPE, "PEM");
		curl_setopt($curl_handle, CURLOPT_SSLCERT, "$sitecfg_workdir/sslcerts/$clientcertfn");
		curl_setopt($curl_handle, CURLOPT_SSLKEY, "$sitecfg_workdir/sslcerts/$clientprivfn");

		curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, 0);
	}

	$buf = curl_exec($curl_handle);

	$errorstr = "";

	$httpstat = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
	if($buf===FALSE)
	{
		$errorstr = "HTTP request failed: " . curl_error ($curl_handle);
		$httpstat = "0";
	} else if($httpstat!="200")$errorstr = "HTTP error $httpstat: " . curl_error ($curl_handle);

	if($errorstr!="")$buf = $errorstr;

	$query="UPDATE ninupdates_management SET lastreqstatus='" . mysqli_real_escape_string($mysqldb, $errorstr) . "'";
	$result=mysqli_query($mysqldb, $query);

	return $buf;
}

function load_titlelist_withcmd($reportdate)
{
	global $sitecfg_workdir, $sitecfg_load_titlelist_cmd, $system, $newtitles, $newtitlesversions, $newtotal_titles;

	if($newtotal_titles < 1)
	{
		echo "newtotal_titles is <1.\n";
		return -2;
	}

	$titleid = escapeshellarg($newtitles[0]);
	$titlever = escapeshellarg($newtitlesversions[0]);

	$filepath = "$sitecfg_workdir/load_titlelist_data/titlelist/$reportdate-$system";
	$maincmd_str = "$sitecfg_load_titlelist_cmd $reportdate $system $filepath $sitecfg_workdir/load_titlelist_data $titleid,$titlever";

	echo "Running load_titlelist cmd...\n";
	$retval = 0;
	$ret = system("$maincmd_str > $sitecfg_workdir/load_titlelist_out/$reportdate-$system 2>&1", $retval);
	if($ret===FALSE || $retval!=0)
	{
		echo "cmd failed.\n";
		if($retval>0)$retval = -$retval;
		if($retval==0)$retval = -3;
		return $retval;
	}

	if(file_exists($filepath)===FALSE)
	{
		echo "The titlelist file doesn't exist.\n";
		return -1;
	}

	$buf = file_get_contents($filepath);
	if($buf===FALSE)
	{
		echo "Failed to load the titlelist file.\n";
		return -1;
	}

	parse_soapresp($buf, 1);//Reuse the SOAP format.

	return 0;
}

function titlelist_dbupdate_withcmd($curdate)
{
	global $system;

	if($system == "hac")
	{
		$retval = load_titlelist_withcmd($curdate);
		if($retval!=0)
		{
			appendmsg_tofile("load_titlelist_withcmd() for $curdate-$system failed.", "msgme");
			return $retval;
		}
	}

	return titlelist_dbupdate();
}

function compare_titlelists($curdate)
{
	global $mysqldb, $system, $region, $sysupdate_systitlehashes;

	$query="SELECT ninupdates_systitlehashes.titlehash FROM ninupdates_reports, ninupdates_consoles, ninupdates_systitlehashes WHERE ninupdates_systitlehashes.reportid=ninupdates_reports.id && ninupdates_reports.systemid=ninupdates_consoles.id && ninupdates_consoles.system='".$system."' && ninupdates_systitlehashes.region='".$region."' && ninupdates_reports.log='report' ORDER BY ninupdates_reports.curdate DESC LIMIT 1";
	$result=mysqli_query($mysqldb, $query);
	$numrows=mysqli_num_rows($result);

	if($numrows==0)
	{
		//echo "System $system Region $region: Titlehash is missing.\n";
		return titlelist_dbupdate_withcmd($curdate);
	}
	else
	{
		$row = mysqli_fetch_row($result);
		$titlehashold = $row[0];

		if($sysupdate_systitlehashes[$region]!=$titlehashold)
		{
			return titlelist_dbupdate_withcmd($curdate);
		}
		else
		{
			return 0;
		}
	}

	return 0;
}

function main($reg)
{
	global $mysqldb, $system, $log, $region, $httpstat, $syscmd, $sitecfg_httpbase, $sysupdate_available, $sysupdate_timestamp, $sitecfg_workdir, $curdate, $dbcurdate, $soap_timestamp, $sysupdate_regions, $arg_difflogold, $arg_difflognew, $newtotal_titles, $console_deviceid, $curtime_override;

	$region = $reg;

	$query="SELECT nushttpsurl FROM ninupdates_consoles WHERE system='".$system."'";
	$result=mysqli_query($mysqldb, $query);
	$row = mysqli_fetch_row($result);
	$nushttpsurl = $row[0];

	$ishac = 0;
	if($system == "hac")$ishac = 1;

	initialize($ishac);

	if($ishac===0)
	{
		$url = "$nushttpsurl/nus/services/NetUpdateSOAP";
	}
	else
	{
		$url = "$nushttpsurl/v1/system_update_meta?device_id=" . $console_deviceid;
	}

	for($i=0; $i<5; $i++)
	{
		$ret = send_httprequest($url, $ishac);
		if($httpstat!="0")break;
	}

	if($httpstat=="200")
	{
		if($ishac===0)
		{
			parse_soapresp($ret, 0);
		}
		else
		{
			$retval = parse_json_resp($ret);
			if($retval!=0)return;
		}
	}
	else
	{
		echo $ret . "\n";
		return;
	}

	if($curtime_override==0)
	{
		$curtime = time();
	}
	else
	{
		$curtime = $curtime_override;
	}
	$soap_timestamp = date(DATE_RFC822, $curtime);

	$curdate = date("m-d-y_h-i-s", $curtime);
	if($sysupdate_timestamp!="")$curdate = $sysupdate_timestamp;
	$curdatefn = $curdate . ".html";

	if($dbcurdate=="")
	{	
		$query = "SELECT FROM_UNIXTIME($curtime)";
		$result=mysqli_query($mysqldb, $query);
		$row = mysqli_fetch_row($result);
		$dbcurdate = $row[0];
	}

	if($curtime_override!=0)
	{
		echo "Using overridden timestamps: soap_timestamp = '".$soap_timestamp."', curdate='".$curdate."', dbcurdate='".$dbcurdate."'.\n";
	}

	$sendupdatelogs = 0;

	$query = "SELECT ninupdates_titles.version FROM ninupdates_titles, ninupdates_consoles WHERE ninupdates_titles.region='".$region."' && ninupdates_titles.systemid=ninupdates_consoles.id && ninupdates_consoles.system='".$system."'";
	$result=mysqli_query($mysqldb, $query);
	$numrows=mysqli_num_rows($result);

	if($numrows==0 && $newtotal_titles>0)
	{
		$retval = titlelist_dbupdate_withcmd($curdate);
		if($retval < 0)
		{
			echo "titlelist_dbupdate_withcmd() failed.\n";
			return;
		}

		$fsoap = fopen("$sitecfg_workdir/soap$system/$region/$curdatefn.soap", "w");
		fwrite($fsoap, $ret);
		fclose($fsoap);

		echo "System $system: This is first scan for region $region, unknown if any titles were recently updated.\n";

		$sendupdatelogs = 1;
	}
	else
	{
		$retval = compare_titlelists($curdate);
		if($retval < 0)
		{
			echo "compare_titlelists() failed.\n";
			return;
		}

		if($retval)
		{
			$sendupdatelogs = 1;

			$fsoap = fopen("$sitecfg_workdir/soap$system/$region/$curdatefn.soap", "w");
			fwrite($fsoap, $ret);
			fclose($fsoap);
		}
		else
		{
			$sendupdatelogs = 0;
		}
	}
	
	if($sendupdatelogs)
	{
		$sysupdate_available = 1;
		if($sysupdate_timestamp=="")$sysupdate_timestamp = $curdate;

		if($sysupdate_regions!="")$sysupdate_regions.= ",";
		$sysupdate_regions.= $region;
	}

}

?>
