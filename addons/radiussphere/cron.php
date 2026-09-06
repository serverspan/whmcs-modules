<?php
/** Run from WHMCS cron after including init.php: php modules/addons/radiussphere/cron.php */
declare(strict_types=1);
require_once __DIR__.'/../../../init.php';
use WHMCS\Database\Capsule;
$now=date('Y-m-d H:i:s');
$commands=Capsule::table('mod_radiussphere_commands')->where('state','pending')->where(function($q)use($now){$q->whereNull('available_at')->orWhere('available_at','<=',$now);})->orderBy('id')->limit(50)->get();
foreach($commands as $command){ Capsule::table('mod_radiussphere_commands')->where('id',$command->id)->where('state','pending')->update(['state'=>'running','attempts'=>$command->attempts+1,'updated_at'=>$now]); try { /* DriverDispatcher::apply($command) is introduced with SQL/API drivers. */ Capsule::table('mod_radiussphere_commands')->where('id',$command->id)->update(['state'=>'awaiting_driver','updated_at'=>date('Y-m-d H:i:s')]); } catch(Throwable $e) { $retryAt=date('Y-m-d H:i:s',time()+min(3600,60*(2**min(6,(int)$command->attempts)))); Capsule::table('mod_radiussphere_commands')->where('id',$command->id)->update(['state'=>'retry','available_at'=>$retryAt,'last_error'=>substr($e->getMessage(),0,2000),'updated_at'=>date('Y-m-d H:i:s')]); } }
