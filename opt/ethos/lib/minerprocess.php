<?php

function delete_old_api_port(){
        $oldapiport = trim(`/usr/bin/sudo /sbin/iptables -t nat -L | grep DNAT | grep LOCAL |  grep -m 1 -Poi "(?<=to:127.0.0.1:)(.*)"`);
	if ($oldapiport) {
		`/usr/bin/sudo /sbin/iptables -t nat -D POSTROUTING -m addrtype --src-type LOCAL --dst-type UNICAST -j MASQUERADE`;
		`/usr/bin/sudo /sbin/iptables -t nat -D PREROUTING -p tcp -m tcp --dport 42000 -j DNAT --to-destination 127.0.0.1:$oldapiport`;
		`/usr/bin/sudo /sbin/iptables -t nat -D OUTPUT -p tcp -m addrtype --src-type LOCAL --dst-type LOCAL -m tcp --dport 42000 -j DNAT --to-destination 127.0.0.1:$oldapiport`;
	}
}

function select_api_port(){
        $apiport = rand(42001, 42200);
        if (!$socket = @fsockopen("127.0.0.1", $apiport, $errno, $errstr, 3)) {
                echo "Not in use, creating iptables rule..";
		`/usr/bin/sudo /sbin/sysctl -w net.ipv4.conf.all.route_localnet=1`;
		`/usr/bin/sudo /sbin/iptables -t nat -A POSTROUTING -m addrtype --src-type LOCAL --dst-type UNICAST -j MASQUERADE`;
		`/usr/bin/sudo iptables -t nat -A OUTPUT -m addrtype --src-type LOCAL --dst-type LOCAL -p tcp --dport 42000 -j DNAT --to-destination 127.0.0.1:$apiport`;
		$externalapi = trim(`/opt/ethos/sbin/ethos-readconf externalapi`);
		if ($externalapi == "enabled" ){
			`/usr/bin/sudo sysctl -w net.ipv4.conf.eth0.route_localnet=1`;
			`/usr/bin/sudo iptables -t nat -A PREROUTING -p tcp --dport 42000 -j DNAT --to-destination 127.0.0.1:$apiport`;
		}

        } else {
                echo "In use, finding new port.";
                fclose($socket);
                select_api_port();
        }
        return $apiport;
}

function select_gpus(){

	$selectedgpus = trim(`/opt/ethos/sbin/ethos-readconf selectedgpus`);

	if($selectedgpus){
		$devices = explode(" ",$selectedgpus);
	}

	if($selectedgpus == "0"){
		$devices = array($selectedgpus);
	}

	if(!$devices){

		//mining functionality is dependent on gpucount.file always being available
		
			$gpus = trim(file_get_contents("/var/run/ethos/gpucount.file"));

			for($i = 0; $i < $gpus; $i++){
					$devices[] = $i;
			}
	}

	return $devices;
}

// fglrx / amdgpu check igp function
function check_igp()
{
	$checkigp = trim(`/opt/miners/ethminer/ethminer -G --list-devices | tee /var/run/ethos/checkigp.file`);
	preg_match('#\b(Kaveri|Beavercreek|Sumo|Wrestler|Kabini|Mullins|Temash|Trinity|Richland|Carrizo)\b#', $checkigp, $baddevices);

	if ($baddevices) {
		echo "non-mining device found, excluding from mining gpus.\n";
		$validdevices = `grep ']' /var/run/ethos/checkigp.file | grep -v FORMAT | grep -v OPENCL | egrep -iv 'Beavercreek|Sumo|Wrestler|Kabini|Mullins|Temash|Trinity|Richland|Carrizo' | sed 's/\[//g' | sed 's/\]//g' | awk '{print \$1}' | xargs`;
		$extraflags = trim("--opencl-devices $validdevices");
		return $extraflags;
	}
}

function check_status()
{
	$miner = trim(`/opt/ethos/sbin/ethos-readconf miner`);
	$miner_pid = trim(`/opt/ethos/sbin/ethos-readconf pid`);
	$miner_uptime = 0 + trim(`/opt/ethos/sbin/ethos-readdata mineruptime`);
	$max_boots = trim(`/opt/ethos/sbin/ethos-readconf autoreboot`);
	
	$uptime = trim(`cut -d " " -f1 /proc/uptime | cut -d "." -f 1`);
	$hostname = trim(file_get_contents("/etc/hostname"));
	
	//boot value assignment

	$status['updating']['value'] = intval(trim(file_get_contents("/var/run/ethos/updating.file")));
	$status['adl_error']['value'] = intval(trim(file_get_contents("/var/run/ethos/adl_error.file")));
	$status['no_cables']['value'] = intval(trim(file_get_contents("/var/run/ethos/nvidia_error.file")));
	$status['nomine']['value'] = intval(trim(file_get_contents("/var/run/ethos/nomine.file")));
	$status['nowatchdog']['value'] = intval(trim(file_get_contents("/var/run/ethos/nowatchdog.file")));

	if(preg_match("/sgminer/",$miner)){
		$status['sgminerconfigerror']['value']  = intval(trim(@shell_exec("/opt/ethos/bin/lintsgconf status")));
	}

	$status['allow']['value'] = intval(trim(file_get_contents("/opt/ethos/etc/allow.file")));
	$status['off']['value'] = intval(trim(`/opt/ethos/sbin/ethos-readconf off`));
	$status['autorebooted']['value'] = intval(trim(file_get_contents("/opt/ethos/etc/autorebooted.file")));
	$status['defunct']['value'] = intval(trim(`ps uax | grep "$miner " | grep defunct | grep -v grep | wc -l`));
	$crashedgpus = strval(trim(file_get_contents("/var/run/ethos/crashed_gpus.file")));
	if(($crashedgpus === "0" ) || ($crashedgpus)) {
		$crashedgpus = count(explode(" ", $crashedgpus));
	}

	$status['gpucrashed']['value'] = $crashedgpus;
	$status['overheat']['value'] = intval(trim(file_get_contents("/var/run/ethos/overheat.file")));
	$status['starting']['value'] = intval(trim(`ps uax | grep "$miner " | grep -v defunct | grep -v grep | wc -l`));
	$status['invalid']['value'] = intval(trim(@file_get_contents("/var/run/ethos/invalid.file")));

	$status['hash']['value'] = floatval(trim(`tail -10 /var/run/ethos/miner_hashes.file | sort -V | tail -1 | tr ' ' '\n' | awk '{sum += \$1} END {print sum}'`));

	//boot message assignment

	$status['booting']['message'] = "starting ethos: finishing boot process";
	$status['updating']['updating'] = "do not reboot: system upgrade in progress";
	$status['updating']['updated'] = "reboot required: update complete, reboot system";
	$status['adl_error']['message'] = "hardware error: possible gpu/riser/power failure";
	$status['no_cables']['message'] = "power cable problem: PCI-E power cables not seated properly";
	$status['nomine']['message'] = "hardware error: graphics driver did not load";
	$status['nowatchdog']['message'] = "no overheat protection: overheat protection not running";
	$status['sgminerconfigerror']['message'] = "config error: sgminer configuration is not valid";
	$status['allow']['message'] = "miner disallowed: use 'allow' command";
	$status['off']['message'] = "miner off:  miner set to off in config";
	$status['autorebooted']['message'] = "too many autoreboots: autorebooted ".$status['autorebooted']['value']." times";
	$status['defunct']['message'] = "gpu crashed: reboot required";
	$crashedgpusclean = trim(`cat /var/run/ethos/crashed_gpus.file | sed -r 's/[^ ]+/gpu&/g'`);

	$status['gpucrashed']['message'] = "gpu clock problem: $crashedgpusclean clocks are affected";
	$status['overheat']['message'] = "overheat: one or more gpus overheated";
	$status['starting']['message'] = "miner started: miner commanded to start";
	$status['invalid']['message'] = "invalid miner specified: set valid miner";
	$status['hung']['message'] = "possible miner stall: check miner log";
	$status['hash']['message'] = sprintf("%.1f", $status['hash']['value']) . " hash: miner active";

	//boot value/message checks

	if ($status['booting']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['booting']['message'] . "\n");
		return false;
	}
	
	if ($status['updating']['value'] == 1) {
		file_put_contents("/var/run/ethos/status.file", $status['updating']['updating'] . "\n");
		return false;
	}
	
	if ($status['updating']['value'] == 2) {
		file_put_contents("/var/run/ethos/status.file", $status['updating']['updated'] . "\n");
		return false;
	}
	
	if ($status['adl_error']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['adl_error']['message'] . "\n");
		return false;
	}
	
	if ($status['no_cables']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['no_cables']['message'] . "\n");
		return false;
	}

	if ($status['nomine']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['nomine']['message'] . "\n");
		return false;
	}

	if ($status['nowatchdog']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['nowatchdog']['message'] . "\n");
		return false;
	}
	
	if ($status['sgminerconfigerror']['value'] >= 1 && preg_match("/sgminer/",$miner)) {
		file_put_contents("/var/run/ethos/status.file", $status['sgminerconfigerror']['message'] . "\n");
		return false;
	}
	
	if ($status['allow']['value'] == 0) {
		file_put_contents("/var/run/ethos/status.file", $status['allow']['message'] . "\n");
		return false;
	}

	if ($status['off']['value'] == 1) {
		file_put_contents("/var/run/ethos/status.file", $status['off']['message'] . "\n");
		return false;
	}
	
	if ($status['autorebooted']['value'] > $max_boots) {
		file_put_contents("/var/run/ethos/status.file", $status['autorebooted']['message'] . "\n");
		return false;
	}

	if ($status['defunct']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['defunct']['message'] . "\n");
		file_put_contents("/var/run/ethos/defunct.file", $status['defunct']['value']);
		return false;
	}
	
	if ($status['gpucrashed']['value'] > 0 ) {
		//only report clocks too low if the miner is running
		if (($miner_uptime > 600 ) && ($miner_pid)) {
			file_put_contents("/var/run/ethos/status.file", $status['gpucrashed']['message'] . "\n");
			return false;
		}
	}

	if ($status['overheat']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['overheat']['message'] . "\n");
		return false;
	}

	if ($status['starting']['value'] == 0) {
		file_put_contents("/var/run/ethos/status.file", $status['starting']['message'] . "\n");
		return true;
	}

	if ($status['invalid']['value'] > 0){
		file_put_contents("/var/run/ethos/status.file", $status['invalid']['message'] . "\n");
		return false;
	}

	if (($miner_uptime > 600 ) && ($status['hash']['value'] == 0)){
		file_put_contents("/var/run/ethos/status.file", $status['hung']['message'] . "\n");
		return false;
	}

	if ($status['hash']['value'] > 0) {
		file_put_contents("/var/run/ethos/status.file", $status['hash']['message'] . "\n");
		return false;
	}
	
}

$pool_syntax = array(
    "ethminer"=>array(
    "ssl"=>"%s",
    "stratum+tcp"=>"%s",
    "http"=>"%s",
    ""=>"%s"
  ),
    "dstm-zcash"=>array(
    "ssl"=>"ssl://%s",
    "stratum+tcp"=>"%s",
    "http"=>"%s",
    ""=>"%s"
  ),
    "ewbf-zcash"=>array(
    "ssl"=>"%s",
    "stratum+tcp"=>"%s",
    "http"=>"%s",
    ""=>"%s"
  ),
  "optiminer-zcash"=>array(
    "ssl"=>"zstratum+ssl://%s",
    "stratum+tcp"=>"%s",
    "http"=>"%s",
    ""=>"%s"
  ),
  "default"=>array(
    "ssl"=>"ssl://%s",
    "stratum+tcp"=>"stratum+tcp://%s",
    "http"=>"http://%s",
    ""=>"stratum+tcp://%s"
  )
);

function setup_pools($miner)
{
	global $pool_syntax;

	$profile = ((isset($pool_syntax[$miner])) ? $miner : "default");

	foreach (range(1,4) as $checkpool) {
		if (($pools[$checkpool] = trim(`/opt/ethos/sbin/ethos-readconf proxypool$checkpool`))) {
			if (!preg_match("/(ssl|http|stratum\+tcp)\:\/\/(.*)/", $pools[$checkpool], $proxypoolsplit)) {
				$proxypoolsplit = array("", "", $pools[$checkpool]);
			}
			$pools[$checkpool] = sprintf($pool_syntax[$profile][$proxypoolsplit[1]], $proxypoolsplit[2]);
		}
	}
	return array_values($pools);
}

function check_miner()
{
	$miner = trim(`/opt/ethos/sbin/ethos-readconf miner`);
	$checks = array("proxypool1","proxypool2","proxypool3","proxypool4","proxywallet","miner");
	
	foreach($checks as $check){
			$poolinfo_string .= trim(`/opt/ethos/sbin/ethos-readconf $check`);
	}
	
	$poolinfo_md5_prior = trim(@file_get_contents("/var/run/ethos/poolinfo.md5"));
	$poolinfo_md5_current = md5($poolinfo_string);
	file_put_contents("/var/run/ethos/poolinfo.md5", $poolinfo_md5_current);
	if (trim($poolinfo_md5_prior) && $poolinfo_md5_prior != $poolinfo_md5_current) {
		`/opt/ethos/bin/minestop`;
		`echo "" | tee /var/run/miner.output > /tmp/minercmd`;
		foreach (range(0,16) as $outnum) {
			`echo "" | tee /var/run/miner.output.$outnum`;
		}
		return false;
	}
}

function start_miner()
{
	$miner = trim(`/opt/ethos/sbin/ethos-readconf miner`);
	
	check_miner();
	$status = check_status();

	if (!$status) {
			return false;
	}
	

	//global vars
	
	$driver = trim(`/opt/ethos/sbin/ethos-readconf driver`);
	$flags = trim(`/opt/ethos/sbin/ethos-readconf flags`);
	$extraflags = ""; // no extra flags by default
	$hostname = trim(`cat /etc/hostname`);
	$poolpass1 = trim(shell_exec("/opt/ethos/sbin/ethos-readconf poolpass1"));
	$poolpass2 = trim(shell_exec("/opt/ethos/sbin/ethos-readconf poolpass2"));
	$poolpass3 = trim(shell_exec("/opt/ethos/sbin/ethos-readconf poolpass3"));
	$poolpass4 = trim(shell_exec("/opt/ethos/sbin/ethos-readconf poolpass4"));
	$proxywallet = trim(`/opt/ethos/sbin/ethos-readconf proxywallet`);
	list ($proxypool1, $proxypool2, $proxypool3, $proxypool4) = setup_pools($miner);
	$poolemail = trim(`/opt/ethos/sbin/ethos-readconf poolemail`);
	$gpus = trim(file_get_contents("/var/run/ethos/gpucount.file"));
	$stratumtype = trim(`/opt/ethos/sbin/ethos-readconf stratumenabled`);
	if (!$poolpass1) {
		$poolpass1 = "x";
	}
	if (!$poolpass2) {
		$poolpass2 = "x";
	}

	//setup the worker name, and manage pool exceptions
	$worker = trim(`/opt/ethos/sbin/ethos-readconf worker`);
	$worker = trim(preg_replace("/[^a-zA-Z0-9]+/", "", $worker));
	$dworker = trim(`/opt/ethos/sbin/ethos-readconf worker`); // only used for claymore dualminer, as it may have eth on nanopool and dualminer pool on a different pool.
	$dworker = trim(preg_replace("/[^a-zA-Z0-9]+/", "", $dworker));
	$namedisabled = trim(`/opt/ethos/sbin/ethos-readconf namedisabled`);
	if (!preg_match("/(ethminer|claymore\z)/",$miner)){
		$worker = "." . $worker;
	}
	if ($namedisabled == "disabled"){
		$worker = "";
	}

	if ($worker){
		if (preg_match("/dwarfpool.com/",$proxypool1) || preg_match("/dwarfpool.com/",$proxypool2)){
			if($miner == "ccminer" || $miner == "sgminer-gm-xmr" || $miner == "claymore-xmr" ){
				$worker = trim(preg_replace("([a-zA-Z])", "1", $worker));
			}
		}
	
        	if (($poolemail) && (preg_match("/(ethosdistro.com|nanopool.org)/",$proxypool1) || preg_match("/(ethosdistro.com|nanopool.org)/",$proxypool2))) {
			$worker .= "/" . $poolemail;
        	}
    	}

	//begin dstm-zcash configuration generation
	if ($miner == "dstm-zcash") {
		$externalapi = trim(`/opt/ethos/sbin/ethos-readconf externalapi`);
		$api = "--telemetry 127.0.0.1:2222";
		if ($externalapi == "enabled" ){
			$api = "--telemetry 0.0.0.0:2222";
		}
		$devices = implode(",",select_gpus());
		if(trim(`/opt/ethos/sbin/ethos-readconf selectedgpus`)){
			$mine_with = "-dev $devices";
		}
		preg_match("/(.*):(\d+)/", $proxypool1, $dstm_pool1);
		$pools = "--server " . $dstm_pool1['1'] . " --port " .  $dstm_pool1['2'] . " --user $proxywallet$worker --pass $poolpass1 ";
		if($proxypool2){
			preg_match("/(.*):(\d+)/", $proxypool2, $dstm_pool2);
			$pools .= " --pool=" . $dstm_pool2['1'] . "," .  $dstm_pool2['2'] . "," . $proxywallet . $worker . "," . $poolpass2 . " ";
		}
		if($proxypool3){
			preg_match("/(.*):(\d+)/", $proxypool3, $dstm_pool3);
			$pools .= " --pool=" . $dstm_pool3['1'] . "," .  $dstm_pool3['2'] . "," . $proxywallet . $worker . "," . $poolpass3 . " ";
		}
		if($proxypool4){
			preg_match("/(.*):(\d+)/", $proxypool4, $dstm_pool4);
			$pools .= " --pool=" . $dstm_pool4['1'] . "," .  $dstm_pool4['2'] . "," . $proxywallet . $worker . "," . $poolpass4 . " ";
		}
	
	}


	//begin ethminer configuration generation
	
	if ($miner == "ethminer") {

		$gpumode = trim(`/opt/ethos/sbin/ethos-readconf gpumode`);
		$pool = trim(`/opt/ethos/sbin/ethos-readconf fullpool`);
		
		if (!$flags) { $flags = "--farm-recheck 200"; }
		if (!preg_match("/cl-global-work/", $flags) && ($driver == "amdgpu" || $driver == "fglrx" )) {
			$flags .= " --cl-global-work 8192 ";
		}
		
		if (!preg_match("/cuda-parallel-hash/", $flags) && $driver == "nvidia") {
			$flags .= " --cuda-parallel-hash 4 ";
		}
		
		if ($gpumode != "-G" || $gpumode != "-U") {
			if ($driver == "nvidia") {
				$gpumode = "-U";
			}

			if ($driver == "fglrx" || $driver == "amdgpu") {
				$gpumode = "-G";
			}
		}

		if ($driver == "nvidia" && $gpumode == "-U") {
			$selecteddevicetype = "--cuda-devices";
		} else {
			$selecteddevicetype = "--opencl-devices";
			$extraflags = check_igp();
		}

		$minermode = "-F";

		// getwork

		if ($stratumtype != "enabled" && $stratumtype != "miner") {
			$pool = str_replace("WORKER", $worker, $pool);
		}

		// parallel proxy

		if ($stratumtype == "enabled") {
			stratum_phoenix();
			$pool = "http://127.0.0.1:8080/$worker";
		}

		// genoil proxy

		if ($stratumtype == "miner") {
			if ($worker) {
				$worker = "." . $worker;
			}
			$minermode = "-S";
			$pool = $proxypool1;
			$extraflags .= " -O $proxywallet$worker ";
			if ($proxypool2) {
				$extraflags .= " -FS $proxypool2 -FO $proxywallet$worker ";
			}
		}

		// genoil proxy

		if ($stratumtype == "nicehash") {
			if ($worker) {
				$worker = "." . $worker;
			}
			$minermode = "-SP 2 -S";
			$pool = $proxypool1;
			$extraflags .= " -O $proxywallet$worker ";
			if ($proxypool2) {
				$extraflags .= " -FS $proxypool2 -FO $proxywallet$worker ";
			}
		}

	}

	
	//begin ccminer config generation
	
	if (preg_match("/ccminer/",$miner)){
		$devices = implode(",",select_gpus());
		if(trim(`/opt/ethos/sbin/ethos-readconf selectedgpus`)){
			$mine_with = "-d $devices";
		}
		if(!preg_match("/-a/",$flags)){
			$flags .= " -a cryptonight ";
		}
		$pools="-o $proxypool1 -u $proxywallet$worker -p $poolpass1 ";
		if($proxypool2){
			$pools .= " -o $proxypool2 -u $proxywallet$worker -p $poolpass2 ";
		}
	}
	

        //begin tdxminer config generation
        
        if (preg_match("/tdxminer/",$miner)){
                $devices = implode(",",select_gpus());
                if(trim(`/opt/ethos/sbin/ethos-readconf selectedgpus`)){
                        $mine_with = "-d $devices";
                }
                if(!preg_match("/-a/",$flags)){
                        $flags .= " -a lyra2z ";
                }
                $pools="-o $proxypool1 -u $proxywallet$worker -p $poolpass1 ";
        }
	
	// begin cgminer-skein/sgminer-gm/sgminer-gm-xmr config generation
	if (preg_match("/(s|c)(gminer)/",$miner)){
		$devices = implode(",",select_gpus());
		if(trim(`/opt/ethos/sbin/ethos-readconf selectedgpus`)){
			$mine_with = "-d $devices";
		}
		$maxtemp = trim(shell_exec("/opt/ethos/sbin/ethos-readconf maxtemp"));
		if (!$maxtemp) {
			$maxtemp = "85";
		}
		if($miner == "sgminer-gm"){
			$config_string = file_get_contents("/home/ethos/sgminer.stub.conf");
		} else {
			$config_string = file_get_contents("/home/ethos/".$miner.".stub.conf");
		}
		if ($driver == "amdgpu") {
			$config_string = preg_replace("/ethash\"/", "ethash-new\"", $config_string);
		}
		$config_string = str_replace(".WORKER",$worker,$config_string);
		$config_string = str_replace("POOL1",$proxypool1,$config_string);
		$config_string = str_replace("POOL2",$proxypool2,$config_string);
		$config_string = str_replace("WALLET",$proxywallet,$config_string);
		$config_string = str_replace("PASSWORD1",$poolpass1,$config_string);
		$config_string = str_replace("PASSWORD2",$poolpass2,$config_string);
		$config_string = str_replace("MAXTEMP",$maxtemp,$config_string);
		file_put_contents("/var/run/ethos/sgminer.conf",$config_string);
	}

	//begin common claymore buildup
	if (preg_match("/claymore/",$miner)){
		// import legacy stub -> flags configuration for remote conf users first.
		$stubprefix = trim(@file_get_contents("/home/ethos/$miner.flags"));
		$config_string = trim(`/opt/ethos/sbin/ethos-readconf flags`);
		
		if(($mining_devices = select_gpus())){
			foreach($mining_devices as $i) {
				$device_array[$i]= dechex($i);
			}
		}
		$devices = implode("",$device_array);
		if(trim(`/opt/ethos/sbin/ethos-readconf selectedgpus`)){
			$mine_with = "-di $devices";
		}
		$maxtemp = trim(shell_exec("/opt/ethos/sbin/ethos-readconf maxtemp"));
		if (!$maxtemp) {
			$maxtemp = "85";
		}
		
	}
	    
	//begin claymore dualminer (eth) config generation
	if ($miner == "claymore") {
		
		$dualminer_status = (trim(`/opt/ethos/sbin/ethos-readconf dualminer`));
		if(!preg_match("/-esm/",$config_string)) {
			if ($stratumtype == "nicehash") {
				$config_string .= " -esm 3 ";
			} elseif ($stratumtype == "coinotron" ) {
				$config_string .= " -esm 2 ";
			} else {
				$config_string .= " -esm 0 ";
			}
		}
		if ($worker) {
			$config_string .= " -eworker " . $worker . " ";
		}
		if(!preg_match("/-dbg/",$config_string)) {
			$config_string .= " -dbg -1 ";
		}
		if(!preg_match("/-wd/",$config_string)) {
			$config_string .= " -wd 0 ";
		}
		if(!preg_match("/-colors/",$config_string)) {
			$config_string .= " -colors 0 ";
		}
		if(!preg_match("/-allcoins/",$config_string)) {
			$config_string .= " -allcoins 1 ";
		}
		if(!preg_match("/-allpools/",$config_string)) {
			$config_string .= " -allpools 1 ";
		}
		if(!preg_match("/-gser/",$config_string)) {
			$config_string .= " -gser 2 ";
		}
		// resume normal good stuff
		$pools = " -epool $proxypool1 -ewal $proxywallet -epsw $poolpass1 ";
		if($proxypool2) {
			$pools .= " -epool $proxypool2 -ewal $proxywallet -epsw $poolpass2 ";
		}
		if($proxypool3) {
			$pools .= " -epool $proxypool3 -ewal $proxywallet -epsw $poolpass3 ";
		}
		if($proxypool4){
			$pools .= " -epool $proxypool4 -ewal $proxywallet -epsw $poolpass4 ";
		}
		if ($dualminer_status == "enabled" ){

			$dualminerpool = (trim(`/opt/ethos/sbin/ethos-readconf dualminer-pool`));
			$dualminercoin = (trim(`/opt/ethos/sbin/ethos-readconf dualminer-coin`));
			$dualminerwallet = (trim(`/opt/ethos/sbin/ethos-readconf dualminer-wallet`));
			$dualminerpoolpass = (trim(`/opt/ethos/sbin/ethos-readconf dualminer-poolpass`));
			// workaround for the fact that dualminer uses -eworker for ethereum worker name, but not for dualminer worker.

			if ($namedisabled != "disabled"){
				$dualminerworker = "." . $dworker;
				if (($poolemail) && (preg_match("/(ethosdistro.com|nanopool.org)/",$dualminerpool))) {
					$dualminerworker = "." . $dworker . "/" . $poolemail;
				}

			}
			$config_string .= " -dcoin $dualminercoin -dwal $dualminerwallet$dualminerworker -dpool $dualminerpool ";
			
			if(!preg_match("/-dpsw/",$config_string)) {
				if ($dualminerpoolpass) {
					$config_string .= " -dpsw $dualminerpoolpass ";
				} else {
					$config_string .= " -dpsw x ";
				}
			}
		}
		$config_string = "$stubprefix $config_string $pools $mine_with";
			    
	}

			    
	//begin claymore-xmr configuration
	if ($miner == "claymore-xmr"){

		$flags .= " -dbg -1 -wd 0 ";
		$pools = " -xpool $proxypool1 -xwal $proxywallet$worker -xpsw $poolpass1 ";
		if($proxypool2){
			$pools .= " -xpool $proxypool2 -xwal $proxywallet$worker -xpsw $poolpass2 ";
		}
		if($proxypool3){
			$pools .= " -xpool $proxypool3 -xwal $proxywallet$worker -xpsw $poolpass3 ";
		}
		if($proxypool4){
			$pools .= " -xpool $proxypool4 -xwal $proxywallet$worker -xpsw $poolpass4 ";
		}
		
	}


	//begin claymore-zcash config generation
	if ($miner == "claymore-zcash") {
		
		if(!preg_match("/-dbg/",$config_string)){
			$config_string .= " -dbg -1 ";
		}
		if(!preg_match("/-wd/",$config_string)){
			$config_string .= " -wd 0 ";
		}
		if(!preg_match("/-colors/",$config_string)){
			$config_string .= " -colors 0 ";
		}
		if(!preg_match("/-allpools/",$config_string)){
			$config_string .= " -allpools 1 ";
		}
		$pools = " -zpool $proxypool1 -zwal $proxywallet$worker -zpsw $poolpass1 ";
		if($proxypool2){
			$pools .= " -zpool $proxypool2 -zwal $proxywallet$worker -zpsw $poolpass2 ";
		}
		if($proxypool3){
			$pools .= " -zpool $proxypool3 -zwal $proxywallet$worker -zpsw $poolpass3 ";
		}
		if($proxypool4){
			$pools .= " -zpool $proxypool4 -zwal $proxywallet$worker -zpsw $poolpass4 ";
		}
		$config_string = "$stubprefix $config_string $pools $mine_with";
		
	}
	//begin ewbf-zcash configuration
	
	if ($miner == "ewbf-zcash") {
		
		delete_old_api_port();
		$apiport = select_api_port();
		$devices = implode(" ",select_gpus());
		$config_string = file_get_contents("/opt/ethos/etc/ewbf-zcash.conf");
		$config_string = str_replace("DEVICES",$devices,$config_string);
		$config_string = str_replace("APIPORT",$apiport,$config_string);
		$maxtemp = trim(shell_exec("/opt/ethos/sbin/ethos-readconf maxtemp"));
		if (!$maxtemp) {
			$maxtemp = "85";
		}
		for ($i = 1; $i <= 4; $i++){
			if(${'proxypool'.$i}) {
				preg_match("/(.*):(\d+)/", ${'proxypool'.$i}, $pool_split);
				$config_string = $config_string . "\n[server]\nserver " . $pool_split[1] . "\nport " . $pool_split[2] . "\nuser " . $proxywallet . $worker . "\npass " . ${'poolpass'.$i} . "\n";
			}
		}
		$config_string = str_replace("MAXTEMP",$maxtemp,$config_string);
		file_put_contents("/var/run/ethos/ewbf-zcash.conf",$config_string);

	}

			
	//begin optiminer-zcash configuration
	
	if ($miner == "optiminer-zcash") {
				$optiminerversion = trim(`grep -Poi "(?<=optiminer-zcash v)(.*)" /var/run/ethos/miner.versions`);
				
				$devices = implode(" -d ",select_gpus());
				$extraflags = trim(`/opt/ethos/sbin/ethos-readconf flags`);
				$mine_with = "-d $devices";
				if(($optiminerversion != "1.7.0") && (!preg_match("/-a/",$extraflags))){
					$extraflags .= " -a equihash200_9 ";
				}
	}
	
	//begin wolf-xmr-cpu configuration
	
	if ($miner == "wolf-xmr-cpu"){
		$threads = trim(`/opt/ethos/sbin/ethos-readconf flags`);
		if (!$threads){
			$threads = trim(`nproc`);
		}
	}

			    
	//begin miner commandline buildup
	$miner_path['dstm-zcash'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.dstm-zcash -l -L -dmS dstm-zcash /opt/miners/dstm-zcash/dstm-zcash";
	$miner_path['ccminer'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.ccminer -l -L -dmS ccminer /opt/miners/ccminer/ccminer";
	$miner_path['cgminer-skein'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.cgminer-skein -dmS cgminer-skein /opt/miners/cgminer-skein/cgminer-skein";		
	$miner_path['claymore'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.claymore -l -L -dmS claymore /opt/miners/claymore/claymore";
	$miner_path['claymore-xmr'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.claymore-xmr -l -L -dmS claymore-xmr /opt/miners/claymore-xmr/claymore-xmr";
	$miner_path['claymore-zcash'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.claymore-zcash -l -L -dmS claymore-zcash /opt/miners/claymore-zcash/claymore-zcash";
	$miner_path['ethminer'] = "/opt/miners/ethminer/ethminer";
	$miner_path['ewbf-zcash'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.ewbf-zcash -l -L -dmS ewbf-zcash /opt/miners/ewbf-zcash/ewbf-zcash";
	$miner_path['optiminer-zcash'] = "/bin/bash -c \" cd /opt/miners/optiminer-zcash && /usr/bin/screen -c /opt/ethos/etc/screenrc -dmS optiminer /opt/miners/optiminer-zcash/optiminer-zcash";
	$miner_path['sgminer-gm'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.sgminer-gm -dmS sgminer /opt/miners/sgminer-gm/sgminer-gm";
	$miner_path['sgminer-gm-xmr'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.sgminer-gm-xmr -dmS sgminer /opt/miners/sgminer-gm/sgminer-gm-xmr";
	$miner_path['wolf-xmr-cpu'] = "/opt/miners/wolf-xmr-cpu/wolf-xmr-cpu";
        $miner_path['tdxminer'] = "/usr/bin/screen -c /opt/ethos/etc/screenrc.tdxminer -l -L -dmS claymore /opt/miners/tdxminer/tdxminer";
			

			
	$start_miners = select_gpus();

	foreach($start_miners as $start_miner) {
		$miner_params['dstm-zcash'] = $api ." ". $flags ." ". $pools;
		$miner_params['ccminer'] = $flags ." ". $pools;
		$miner_params['cgminer-skein'] = "-c /var/run/ethos/sgminer.conf";
		$miner_params['claymore'] = "$config_string";
		$miner_params['claymore-xmr'] = "-allpools 1 " . $flags . " " . $pools;
		$miner_params['claymore-zcash'] = "$config_string";
		$miner_params['ethminer'] = $minermode . " " . $pool . " " . $gpumode . " --dag-load-mode sequential " . $flags . " " . $extraflags . " " . $selecteddevicetype . " " . $start_miner;
		$miner_params['ewbf-zcash'] = "--config /var/run/ethos/ewbf-zcash.conf";
		$miner_params['sgminer-gm'] = "-c /var/run/ethos/sgminer.conf";
		$miner_params['sgminer-gm-xmr'] = "-c /var/run/ethos/sgminer.conf";
		$miner_params['optiminer-zcash'] = "-s $proxypool1 -u $proxywallet$worker -p $poolpass1 --log-file /var/run/miner.output";
		$miner_params['wolf-xmr-cpu'] = "-o $proxypool1 -p $poolpass1 -u $proxywallet$worker -t $threads";
		
		$miner_suffix['dstm-zcash'] = " " . $mine_with . " " . $extraflags;
		$miner_suffix['ccminer'] = " " . $mine_with . " " . $extraflags;
		$miner_suffix['cgminer-skein'] = " " . $mine_with . " " . $extraflags;
		$miner_suffix['claymore'] = " " . $extraflags;
		$miner_suffix['claymore-xmr'] = " " . $mine_with . " " . $extraflags;
		$miner_suffix['claymore-zcash'] = " " . $extraflags;
		$miner_suffix['ethminer'] = " 2>&1 | /usr/bin/tee -a /var/run/miner.output >> /var/run/miner.$start_miner.output &";
		$miner_suffix['ewbf-zcash'] = "";
		$miner_suffix['sgminer-gm'] = " " . $mine_with . " " . $extraflags;
		$miner_suffix['sgminer-gm-xmr'] = " " . $mine_with ." ". $extraflags;
		$miner_suffix['optiminer-zcash'] = " " . $mine_with ." " . $extraflags ." \\\"";
		$miner_suffix['wolf-xmr-cpu'] = " 2>&1 | /usr/bin/tee -a /var/run/miner.output &";
                $miner_params['tdxminer'] = $flags ." ". $pools;
		
		$command = "su - ethos -c \"" . escapeshellcmd($miner_path[$miner] . " " . $miner_params[$miner]) . " $miner_suffix[$miner]\"";
		$command = str_replace('\#',"#",$command);
		$command = str_replace('\&',"&",$command);
		if ($miner == "optiminer-zcash") {
			file_put_contents("/tmp/minercmd", "#!/bin/bash \n");
			file_put_contents("/tmp/minercmd", $command . "\n", FILE_APPEND);
		} else {
			file_put_contents("/tmp/minercmd", $command . "\n");
		}
		chmod("/tmp/minercmd", 0755);
		`/tmp/minercmd`;

		// if($debug){ file_put_contents("/home/ethos/debug.log",$date $command); 

		if($miner != "ethminer"){
			break;
		}

		sleep(10);
	}

	return true;
}

?>
