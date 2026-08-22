<?php
$root=dirname(__DIR__);
$checks=[
 'Login route'=>'resources/js/App.jsx|path="/login"',
 'Register route'=>'resources/js/App.jsx|path="/register"',
 'Auth guard'=>'resources/js/App.jsx|RequireAuth',
 'Admin role guard'=>'resources/js/App.jsx|RequireRole',
 'Admin shell'=>'resources/js/layout/AdminShell.jsx|Admin Panel',
 'Vendor shell'=>'resources/js/layout/VendorShell.jsx|Seller Center',
 'Admin users page'=>'resources/js/pages/AdminUsers.jsx|User Management',
 'Admin vendors page'=>'resources/js/pages/AdminVendors.jsx|Vendor Management',
 'Header sign in'=>'resources/js/layout/Shell.jsx|Sign in',
 'Header registration'=>'resources/js/layout/Shell.jsx|Create account',
 'Backend area middleware'=>'app/Http/Middleware/EnforceAreaRole.php|Administrative access is required',
 'Admin users API'=>'routes/api.php|/admin/users',
 'Admin vendors API'=>'routes/api.php|/admin/vendors',
 'Admin seed'=>'database/seeders/DatabaseSeeder.php|admin@example.test',
 'Customer seed'=>'database/seeders/DatabaseSeeder.php|customer@example.test',
 'Seller seed'=>'database/seeders/DatabaseSeeder.php|seller@example.test',
];
$failed=[];
foreach($checks as $label=>$spec){[$file,$needle]=explode('|',$spec,2);$text=@file_get_contents($root.'/'.$file);if($text===false||!str_contains($text,$needle))$failed[]="$label ($file)";}
if($failed){fwrite(STDERR,"Auth/Admin UI audit failed:\n - ".implode("\n - ",$failed)."\n");exit(1);}echo "Auth/Admin UI audit PASS: ".count($checks)." required flows present.\n";
