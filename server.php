<?php
$repo=__DIR__;$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$pages=['/gdpr.php'=>'gdpr.php','/gallery.php'=>'gallery.php','/dashboard.php'=>'dashboard.php','/archive.php'=>'archive.php','/rss.php'=>'rss.php','/recent.php'=>'recent.php','/export.php'=>'export.php','/import.php'=>'import.php','/bookmarklet.php'=>'bookmarklet.php','/bookmarklet-setup.php'=>'bookmarklet-setup.php','/tag-admin.php'=>'tag-admin.php','/'=>'index.php','/index.php'=>'index.php','/install.php'=>'install.php','/login.php'=>'login.php','/logout.php'=>'logout.php','/account.php'=>'account.php','/admin.php'=>'admin.php','/api.php'=>'api.php','/add-bookmark.php'=>'add-bookmark.php','/bookmark.php'=>'bookmark.php','/tags.php'=>'tags.php','/tag-network.php'=>'tag-network.php','/utilities.php'=>'utilities.php'];
if(isset($pages[$path])){require $repo.'/'.$pages[$path];return true;}
$file=realpath($repo.$path);$ext=$file?pathinfo($file,PATHINFO_EXTENSION):'';
$types=['css'=>'text/css','js'=>'application/javascript','svg'=>'image/svg+xml','png'=>'image/png','ico'=>'image/x-icon','woff2'=>'font/woff2'];
if($file&&str_starts_with($file,$repo.DIRECTORY_SEPARATOR)&&is_file($file)&&isset($types[$ext])&&!preg_match('#/(sessions|backups|archives)/#',$path)){header('Content-Type: '.$types[$ext]);readfile($file);return true;}
http_response_code(404);echo 'Not found';