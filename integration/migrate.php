<?php
declare(strict_types=1);
if(PHP_SAPI !== 'cli'){http_response_code(404);exit;}
$config=require dirname(__DIR__).'/config.php';
$db=new PDO('sqlite:'.$config['db_path']);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec(file_get_contents(__DIR__.'/schema.sql'));
echo "PrivacyLib consent table ready.\n";
