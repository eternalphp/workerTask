<?php 
//任务线程

date_default_timezone_set('Asia/Shanghai'); //默认时区

$config = fread(STDIN,1024);

file_put_contents(__DIR__ ."/log.txt",sprintf("task3 => %s data => %s \n",date("Y-m-d H:i:s"),$config),FILE_APPEND);


?>