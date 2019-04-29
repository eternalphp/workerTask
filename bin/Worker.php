<?php 

//服务容器

define("ROOT",dirname(__DIR__));

class Worker{
	
    /**
     * 状态 启动中
     * @var int
     */
    const STATUS_STARTING = 1;
    
    /**
     * 状态 运行中
     * @var int
     */
    const STATUS_RUNNING = 2;
    
    /**
     * 状态 停止
     * @var int
     */
    const STATUS_SHUTDOWN = 4;
	
	const SHMKEY = 0x4337b700;
	
	static $onMessage = null;
	static $onClose = null;
	static $onError = null;
	
	const Worker_CONFIG_TYPE = 'conf';
	const Worker_CONFIG_FILE = ROOT .'/conf/worker.conf';
	const ACTIVE_STATUS_FILE = ROOT .'/conf/worker.status';
	const ACTIVE_UPDATE_FILE = ROOT .'/worker.update';
	const Worker_PROCESS_FILE = __DIR__ . '/WorkerProcess';
	const Worker_PROCESS_PID = ROOT .'/conf/worker.pid';
	const Worker_VERSION_FILE = ROOT .'/conf/worker.version';
	
	static $workerErrorLog = ROOT . '/logs/error.log';
	static $workerRuntimeLog = ROOT . '/logs/active.log';
	static $workerProcessPid = ROOT . '/conf/worker.pid';
	
	static $taskProcessList = array();
	static $taskList = array();
	
	//加载任务列表
	public static function init(){
		date_default_timezone_set('Asia/Shanghai'); //默认时区
		
		$acticeList = array();
		$taskList = Worker::getConfigList();
		if($taskList){
			foreach($taskList as $k=>$val){
				$acticeList[$val["taskid"]] = $val;
			}
		}
		if($acticeList){
			Worker::$taskList = $acticeList;
			Worker::updateActiveData($acticeList);
		}
		
		$global = Worker::getConfigList('global');
		if($global){
			if(isset($global['error_log'])){
				self::$workerErrorLog = ROOT .'/'. $global['error_log'];
				if(!file_exists(dirname(self::$workerErrorLog))){
					mkdir(dirname(self::$workerErrorLog),0777,true);
				}
			}
			if(isset($global['runtime_log'])){
				self::$workerRuntimeLog = ROOT .'/'.$global['runtime_log'];
				if(!file_exists(dirname(self::$workerRuntimeLog))){
					mkdir(dirname(self::$workerRuntimeLog),0777,true);
				}
			}
			if(isset($global['pid'])){
				self::$workerProcessPid = ROOT .'/'.$global['pid'];
				if(!file_exists(dirname(self::$workerProcessPid))){
					mkdir(dirname(self::$workerProcessPid),0777,true);
				}
			}
		}
		
		$shmid = @shmop_open(self::SHMKEY, 'c', 0644, 1);
		shmop_write($shmid, self::STATUS_RUNNING , 0);
		
		file_put_contents(self::$workerProcessPid,getmypid());
	}
	
	//创建新的进程
	public static function createProcess($taskid){
		
        $start_file = self::Worker_PROCESS_FILE;
        $std_file = sys_get_temp_dir() . '/'.str_replace(array('/', "\\", ':'), '_', $start_file).'.out.txt';
		

        $descriptorspec = array(
            0 => array('pipe', 'a'), // stdin
            1 => array('file', $std_file, 'w'), // stdout
            2 => array('file', $std_file, 'w') // stderr
        );


        $pipes       = array();
        $process     = proc_open("php \"$start_file\" -q", $descriptorspec, $pipes);
        $std_handler = fopen($std_file, 'a+');
        stream_set_blocking($std_handler, 0);
		$status = proc_get_status($process);
		
		fwrite($pipes[0],json_encode(self::$taskList[$taskid]));
		
		exec(sprintf("wmic process where ParentProcessId=%d get ProcessId",$status["pid"]),$result);

		Worker::$taskProcessList[$taskid] = array($process, $start_file, 0);
		
		foreach(self::$taskList as $id=>$val){
			if($val["taskid"] == $taskid){
				$val["running"] = $status["running"];
				$val["pid"] = $status["pid"];
				$val["childPid"] = $result[1];
				$val["status"] = self::STATUS_RUNNING;
				$val["runtime"] = date("Y-m-d H:i:s");
				
				if($status["running"]){
					
					if(isset($val["stoptime"])){
						unset($val["stoptime"]);
					}
					
					call_user_func(Worker::$onMessage,$val);
				}else{
					call_user_func(Worker::$onError,$val);
				}
				
				self::$taskList[$id] = $val;
				
				Worker::updateActiveData(self::$taskList);
				
				break;
			}
		}
		
	}
	
	//启动服务
	public static function runAll(){
		Worker::init();
		Worker::loop();
	}
	
	public static function loop(){
		while(true){
			
			Worker::listion();

			if(self::$taskList){
				foreach(self::$taskList as $k=>$val){
					if($val["status"] == self::STATUS_STARTING){
						self::createProcess($val["taskid"]);
					}
				}
			}
			
			Worker::checkWorkerStatus();
			
			sleep(1);
		}
	}
	
	//检测进程状态
	public static function checkWorkerStatus(){
		
		foreach(static::$taskProcessList as $taskid=>$process_data){
            
			$process = $process_data[0];
            $start_file = $process_data[1];
			$status = proc_get_status($process);
			
			if(!$status['running']){
				$row = Worker::getActiveDetail($taskid);
				if($row){
					$row["running"] = 0;
					$row["status"] = self::STATUS_SHUTDOWN;
					$row["stoptime"] = date("Y-m-d H:i:s");
					$data = Worker::getActiveData();
					$data[$taskid] = $row;
					Worker::updateActiveData($data);
					
					call_user_func(Worker::$onClose,$row);
					
					unset(static::$taskProcessList[$taskid]);
					
					Worker::addLogData($row);
				}
			}else{
				$row = Worker::getActiveDetail($taskid);
				Worker::addLogData($row);
			}
			
		}
	}
	
	//监听是否更新配置文件
	public static function listion(){
		if(file_exists(self::ACTIVE_UPDATE_FILE)){
			Worker::$taskList = Worker::getActiveData();
			unlink(self::ACTIVE_UPDATE_FILE);
		}
	}
	
	public static function onMessage(callable $callback){
		Worker::$onMessage = $callback;
	}
	
	public static function onError(callable $callback){
		Worker::$onError = $callback;
	}
	
	public static function onClose(callable $callback){
		Worker::$onClose = $callback;
	}
	
	public static function addLogData($data = array()){
		if($data){
			file_put_contents(self::$workerRuntimeLog,implode(" | ",$data)."\n",FILE_APPEND);
		}
	}
	
	//重载配置
	public static function reload(){
		$taskList = Worker::getConfigList();
		
		if($taskList){
			
			$taskids = array();
			$data = Worker::getActiveData();
			foreach($taskList as $k=>$val){
				$taskids[] = $val["taskid"];
				if(isset($data[$val["taskid"]])){
					
					$row = $data[$val["taskid"]];
					$row["worker_options"] = $val["worker_options"];
					$row["worker_processes"] = $val["worker_processes"];
					$row["taskUrl"] = $val["taskUrl"];
					$row["cmdFile"] = $val["cmdFile"];
					$row["minute"] = $val["minute"];
					$row["hour"] = $val["hour"];
					$row["day"] = $val["day"];
					$row["month"] = $val["month"];
					$row["dayofweek"] = $val["dayofweek"];
					$data[$val["taskid"]] = $row;
				}else{
					$data[$val["taskid"]] = $val;
				}
			}
			
			if($data){
				foreach($data as $taskid=>$val){
					if(!in_array($taskid,$taskids)){
						unset($data[$taskid]);
					}
				}
			}

			Worker::updateActiveData($data);
			file_put_contents(self::ACTIVE_UPDATE_FILE,'');
	
		}
	}
	
	public static function getTaskList(){
		
		$shmid = @shmop_open(self::SHMKEY, 'c', 0644, 1);
		if(shmop_read($shmid,0,1) != self::STATUS_RUNNING){
			fwrite(STDOUT,"Worker: Worker service was closed ! \n\n");
		}

		
		$list = Worker::getActiveData();
		if($list){
			$line = array();
			$line[] = implode(" | ",array(self::formatStr('taskid',11),self::formatStr('pid',10),self::formatStr('title',15),self::formatStr('status',10),self::formatStr('running',10),self::formatStr('runtime',20),self::formatStr('stoptime',20)));
			foreach($list as $k=>$val){
				if(!isset($val["stoptime"])) $val["stoptime"] = "";
				$line[] = implode(" | ",array(self::formatStr($val["taskid"],10),self::formatStr($val["pid"],10),self::formatStr($val["title"],15),self::formatStr($val["status"],10),self::formatStr($val["running"],10),self::formatStr($val["runtime"],20),self::formatStr($val["stoptime"],20)));
			}
			if($line){
				echo implode(" \n ",$line);
			}
		}
	}
	
	public static function formatStr($str,$len){
		return str_pad($str,$len," ");
	}
	
	public static function updateActiveData($data = array()){
		$data = json_encode($data);
		file_put_contents(self::ACTIVE_STATUS_FILE,$data);
	}
	
	public static function getActiveData(){
		
		if(file_exists(self::ACTIVE_STATUS_FILE)){
			$data = file_get_contents(self::ACTIVE_STATUS_FILE);
			return json_decode($data,true);
		}else{
			return array();
		}

	}
	
	public static function getActiveDetail($taskid){
		$data = self::getActiveData();
		if($data){
			if(isset($data[$taskid])){
				return $data[$taskid];
			}
		}
		return false;
	}
	
	public static function stopProcess($taskid){
		$row = Worker::getActiveDetail($taskid);
		if($row){
			exec(sprintf("kill -9 %d -f",$row["pid"]));
		}
	}
	
	public static function stopProcessAll(){
		$list = Worker::getActiveData();
		if($list){
			foreach($list as $val){
				Worker::stopProcess($val["taskid"]);
			}
		}
		$pid = file_get_contents(self::$workerProcessPid);
		exec(sprintf("kill -9 %d -f",$pid));
		unlink(self::$workerProcessPid);
		unlink(self::ACTIVE_STATUS_FILE);
	}
	
	public static function restartProcessAll(){
		$list = Worker::getActiveData();
		if($list){
			foreach($list as $val){
				Worker::stopProcess($val["taskid"]);
			}
			
			sleep(1);
			
			foreach($list as $val){
				Worker::startProcess($val["taskid"]);
			}
			
		}
	}
	
	public static function startProcess($taskid){
		$data = Worker::getActiveData();
		if($data){
			if(isset($data[$taskid])){
				$data[$taskid]["status"] = self::STATUS_STARTING;
				Worker::updateActiveData($data);
				file_put_contents(self::ACTIVE_UPDATE_FILE,'');
			}
		}
	}
	
	public static function startProcessAll(){
		$data = Worker::getActiveData();
		if($data){
			foreach($data as $taskid=>$val){
				$data[$taskid]["status"] = self::STATUS_STARTING;
			}
			Worker::updateActiveData($data);
			file_put_contents(self::ACTIVE_UPDATE_FILE,'');
		}
	}
	
	public static function getVersion(){
		return file_get_contents(self::Worker_VERSION_FILE);
	}
	
	//解析配置文件
	public static function parseConfig(){

		if(file_exists(self::Worker_CONFIG_FILE)){
			
			if(self::Worker_CONFIG_TYPE == 'json'){
				
				return json_decode(file_get_contents(self::Worker_CONFIG_FILE),true);
				
			}else{
			
				$conf = file_get_contents(self::Worker_CONFIG_FILE);
				$data = array();

				preg_match_all("/worker\s?\{(.*?)\}/ies",$conf,$rules);
					
				if($rules[1]){
					foreach($rules[1] as $k=>$rule){
						
						$lines = explode("\n",$rule);
						if($lines){
							
							$row = array();
							foreach($lines as $line){
								if(trim($line) != ''){
									preg_match("/([a-zA-Z_]+)\s+(.*?);+/ie",$line,$fields);
									list($str,$key,$value) = $fields;
									if($key == 'rules'){
										$vals = explode(" ",$value);
										$row["minute"] = isset($vals[0])?$vals[0]:"*";
										$row["hour"] = isset($vals[1])?$vals[1]:"*";
										$row["day"] = isset($vals[2])?$vals[2]:"*";
										$row["month"] = isset($vals[3])?$vals[3]:"*";
										$row["dayofweek"] = isset($vals[4])?$vals[4]:"*";
									}else{
										$row[$key] = $value;
									}
								}
							}
							$data['workers'][] = $row;
						}
					}
				}
				
				if($rules[0]){
					foreach($rules[0] as $rule){
						$conf = str_replace($rule,'',$conf);
					}
				}
				
				$conf = preg_replace("/server\s?\{(.*?)\}/is","",$conf);

				$lines = explode("\n",$conf);
				foreach($lines as $line){
					preg_match("/([a-zA-Z_]+)\s+(.*?);+/ie",$line,$fields);
					if($fields){
						list($str,$key,$value) = $fields;
						$data['global'][$key] = $value;
					}
				}
				
				return $data;
			}
		}else{
			return array();
		}
	}
	
	public static function saveConfig($data = array()){
		
		if(self::Worker_CONFIG_TYPE == 'json'){
			if($data) file_put_contents(self::Worker_CONFIG_FILE,json_encode($data));
		}else{
			$lines = array();
			if($data){
				
				$lines[] = "server {";
				
				foreach($data as $k=>$val){
					$lines[] = "\tworker {";
					
					$lines[] = "\t\t".implode(" ",array('taskid',$val["taskid"])).";";
					$lines[] = "\t\t".implode(" ",array('title',$val["title"])).";";
					
					$rules = implode(" ",array($val["minute"],$val["hour"],$val["day"],$val["month"],$val["dayofweek"]));
					
					$lines[] = "\t\t".implode(" ",array('rules',$rules)).";";
					
					$lines[] = "\t\t".implode(" ",array('taskUrl',$val["taskUrl"])).";";
					$lines[] = "\t\t".implode(" ",array('cmdFile',$val["cmdFile"])).";";
					$lines[] = "\t\t".implode(" ",array('status',$val["status"])).";";
					$lines[] = "\t\t".implode(" ",array('worker_processes',$val["worker_processes"])).";";
					$lines[] = "\t\t".implode(" ",array('worker_options',$val["worker_options"])).";";
					
					$lines[] = "\t}";
					$lines[] = "\n";
				}
				
				$lines[] = "}";
				
				$conf = file_get_contents(self::Worker_CONFIG_FILE);
				
				preg_match_all("/worker\s?\{(.*?)\}/ies",$conf,$matchs);
				if($matchs[0]){
					foreach($matchs[0] as $worker){
						$conf = str_replace($worker,'',$conf);
					}
				}

				$conf = preg_replace("/server\s?\{(.*?)\}/is",implode("\n",$lines),$conf);
				
				file_put_contents(self::Worker_CONFIG_FILE,$conf);
				
			}
			
		}
	}
	
	public static function getConfigList($key = 'workers'){
		$data = Worker::parseConfig();
		if(isset($data[$key])){
			return $data[$key];
		}else{
			return array();
		}
	}
	
	public static function addConfig($data = array()){
		
		$taskList = self::getConfigList();
		if($data){
			$taskid = 1;
			foreach($taskList as $val){
				if($val["taskid"] > $taskid){
					$taskid = $val["taskid"];
				}
			}
			$data["taskid"] = $taskid + 1;
			$data["status"] = 1;
			$taskList[] = $data;
			Worker::saveConfig($taskList);
		}
	}
	
	public static function removeConfig($taskid){
		
		$row = Worker::getActiveDetail($taskid);
		if($row){
			if($row["running"] == self::STATUS_RUNNING){
				fwrite(STDOUT,"Worker: This process is running, please close it first. \n");	
			}else{
				$taskList = self::getConfigList();
				if($taskList){
					foreach($taskList as $k=>$val){
						if($val["taskid"] == $taskid){
							unset($taskList[$k]);
						}
					}
					Worker::saveConfig($taskList);
				}
			}
		}
	}
	
	public static function getConfig(){
		$pid = getmypid();
		$data = Worker::getActiveData();
		if($data){
			foreach($data as $val){
				if($val["childPid"] == $pid){
					return $val;
				}
			}
		}
		return null;
	}
	
	public static function parseCommand($config = array()){
		if($config){
			
			$data = array();
			$data["sleep"] = 0;
			$data["taskUrl"] = $config["taskUrl"];
			$data["cmdFile"] = $config["cmdFile"];
			$data["worker_processes"] = $config["worker_processes"];
			$data["worker_options"] = $config["worker_options"];
			
			if($config["minute"] != '' && $config["minute"] != '*'){
				if(strstr($config["minute"],"/")){
					$minutes = explode("/",$config["minute"]);
					$data['sleep'] = $minutes[1]*60;
					return $data;
				}else{
					if(strstr($config["minute"],",")){
						$minutes = explode(",",$config["minute"]);
					}elseif(strstr($config["minute"],"-")){
						$minutes = explode("-",$config["minute"]);
						$min = $minutes[0] + 1;
						$max = $minutes[1];
						for($minute = $min;$minute < $max;$minute++){
							$minutes[] = $minute;
						}
					}
					
					$data['minutes'] = $minutes;
				}
			}
			
			if($config["hour"] != '' && $config["hour"] != '*'){
				if(strstr($config["hour"],"/")){
					$hours = explode("/",$config["hour"]);
					$data['sleep'] = $hours[1]*60*60;
					return $data;
				}else{
					if(strstr($config["hour"],",")){
						$hours = explode(",",$config["hour"]);
					}elseif(strstr($config["hour"],"-")){
						$hours = explode("-",$config["hour"]);
						$min = $hours[0] + 1;
						$max = $hours[1];
						for($hour = $min;$hour < $max;$hour++){
							$hours[] = $hour;
						}
					}
					
					$data['hours'] = $hours;
				}
			}
			
			if($config["day"] != '' && $config["day"] != '*'){
				if(strstr($config["day"],"/")){
					$days = explode("/",$config["day"]);
					$data['sleep'] = $days[1]*60*60*24;
					return $data;
				}else{
					if(strstr($config["day"],",")){
						$days = explode(",",$config["day"]);
					}elseif(strstr($config["day"],"-")){
						$days = explode("-",$config["day"]);
						$min = $days[0] + 1;
						$max = $days[1];
						for($day = $min;$day < $max;$day++){
							$days[] = $day;
						}
					}
					
					$data['days'] = $days;
				}
			}
			
			if($config["month"] != '' && $config["month"] != '*'){
				if(strstr($config["month"],",")){
					$months = explode(",",$config["month"]);
				}elseif(strstr($config["month"],"-")){
					$months = explode("-",$config["month"]);
					$max = $months[1];
					$min = $months[0]+1;
					if($months){
						for($month = $min;$month < $maxMonth;$month++){
							$months[] = $month;
						}
					}
				}
				
				$data['months'] = $months;
				
			}
			
			if($config["dayofweek"] != '' && $config["dayofweek"] != '*'){
				$data['weeks'] = explode(",",$config["dayofweek"]);
			}
			
			return $data;
		}
	}
	
	public static function checkRule($data = array()){
		if($data){
			if($data["minutes"]){
				if(!in_array(intval(date("i")),$data["minutes"])){
					return false;
				}
			}
			
			if($data["hours"]){
				if(!in_array(date("G"),$data["hours"])){
					return false;
				}
			}
			
			if($data["days"]){
				if(!in_array(date("j"),$data["days"])){
					return false;
				}
			}
			
			if($data["months"]){
				if(!in_array(date("n"),$data["months"])){
					return false;
				}
			}
			
			if($data["weeks"]){
				if(!in_array(date("w"),$data["weeks"])){
					return false;
				}
			}
			
			return true;
		}
	}
	
	public static function cmdCommand($data = array()){
		if($data){
			if($data["taskUrl"] != ''){
				Worker::httpsRequest($data["taskUrl"]);
			}else{
				
				$pid = 1;
				while($pid <= $data["worker_processes"]){
					
					if(strstr($data["cmdFile"],'/')){
						$start_file = $data["cmdFile"];
					}else{
						$start_file = ROOT ."/". $data["cmdFile"];
					}
					
					$std_file = sys_get_temp_dir() . '/'.str_replace(array('/', "\\", ':'), '_', $start_file).'.out.txt';
					

					$descriptorspec = array(
						0 => array('pipe', 'a'), // stdin
						1 => array('file', $std_file, 'w'), // stdout
						2 => array('file', $std_file, 'w') // stderr
					);


					$pipes       = array();
					$process     = proc_open("php \"$start_file\" -q", $descriptorspec, $pipes);
					$std_handler = fopen($std_file, 'a+');
					stream_set_blocking($std_handler, 0);
					
					$config = array();
					$config['pid'] = $pid;
					$config['worker_options'] = $data["worker_options"];
					
					fwrite($pipes[0],json_encode($config));
					
					$pid++;
				}
			}	
		}
	}
	
	public static function httpsRequest($url,$data = null,$options = array()){
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, FALSE);

		
		if (!empty($data)){
			curl_setopt($curl, CURLOPT_POST, 1);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
		}
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		if(isset($options['timeout']) && $options['timeout']>0) curl_setopt($curl, CURLOPT_TIMEOUT,$options['timeout']);
		
		if(isset($options["return_header"])){
			curl_setopt($curl, CURLOPT_HEADER, $options["return_header"]);
		}
		
		if(isset($options['header']) && is_array($options['header']) && $options['header']){
			curl_setopt($curl, CURLOPT_HTTPHEADER,$options['header']);
		}elseif(isset($options['cookie']) && !empty($options['cookie'])){
			curl_setopt($curl, CURLOPT_COOKIE, $options['cookie']);
		}
		
		//保存cookie文件路径
		if(isset($options['saveCookieFile']) && $options['saveCookieFile']!=''){
			curl_setopt($curl, CURLOPT_COOKIEJAR, $options['saveCookieFile']); 
		}
		
		//读取cookie文件路径
		if(isset($options['readCookieFile']) && $options['readCookieFile']!=''){
			curl_setopt($curl, CURLOPT_COOKIEFILE, $options['readCookieFile']); 
		}

		curl_setopt ( $curl, CURLOPT_FOLLOWLOCATION, 1); 
		$output = curl_exec($curl);
		curl_close($curl);
		return $output;
	}
}

?>