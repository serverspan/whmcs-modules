<?php
declare(strict_types=1);
use WHMCS\Database\Capsule;

add_hook('DailyCronJob',1,function(): void { if(!Capsule::schema()->hasTable('mod_radiussphere_commands')) return; Capsule::table('mod_radiussphere_commands')->where('state','retry')->where('available_at','<=',date('Y-m-d H:i:s'))->update(['state'=>'pending','updated_at'=>date('Y-m-d H:i:s')]); });
add_hook('AdminAreaHeadOutput',1,function(array $vars): string { if(($vars['filename']??'')!=='addonmodules.php'||($_GET['module']??'')!=='radiussphere') return ''; return '<style>.radiussphere-status{font-weight:600}</style>'; });
