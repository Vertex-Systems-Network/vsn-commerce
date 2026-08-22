<?php
return [
 'domain'=>env('HORIZON_DOMAIN'), 'path'=>env('HORIZON_PATH','horizon'), 'use'=>'default', 'prefix'=>env('HORIZON_PREFIX','vsn_horizon:'),
 'waits'=>['redis:critical'=>30,'redis:maintenance'=>300,'redis:notifications'=>60,'redis:reports'=>120,'redis:default'=>60],
 'trim'=>['recent'=>60,'pending'=>60,'completed'=>60,'recent_failed'=>10080,'failed'=>10080,'monitored'=>10080],
 'environments'=>['production'=>['supervisor-1'=>['connection'=>'redis','queue'=>['critical','maintenance','notifications','reports','default'],'balance'=>'auto','autoScalingStrategy'=>'time','minProcesses'=>1,'maxProcesses'=>(int)env('HORIZON_MAX_PROCESSES',10),'balanceMaxShift'=>1,'balanceCooldown'=>3,'tries'=>5,'timeout'=>900,'memory'=>256,'maxTime'=>3600]],'local'=>['supervisor-1'=>['connection'=>'redis','queue'=>['critical','maintenance','notifications','reports','default'],'balance'=>'simple','processes'=>2,'tries'=>3,'timeout'=>900]]],
];
