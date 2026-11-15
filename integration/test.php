<?php
declare(strict_types=1);
// Native PHP HTTP checks against a disposable MySQL database and the original account handlers.
$root = dirname(__DIR__);
$php = getenv('PHP_BINARY') ?: PHP_BINARY;
$phpArgs = [$php];
if (getenv('PHP_INI')) array_push($phpArgs, '-c', getenv('PHP_INI'));
$opaBinary = getenv('OPA_BINARY') ?: 'opa';
$temp = sys_get_temp_dir() . '/privacy-php-check-' . bin2hex(random_bytes(8));
mkdir($temp); mkdir($temp . '/app'); mkdir($temp . '/sessions');
$processes = []; $checks = 0;
function check(string $name, bool $value): void { global $checks; if (!$value) throw new RuntimeException($name); ++$checks; echo "PASS $name\n"; }
function freePort(): int { $socket = stream_socket_server('tcp://127.0.0.1:0'); if (!$socket) throw new RuntimeException('Port allocation failed'); $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket); return $port; }
function launch(array $command, string $cwd, ?array $env = null): mixed { global $processes, $temp; $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $temp . '/server.log', 'a'], 2 => ['file', $temp . '/server.log', 'a']], $pipes, $cwd, $env, ['bypass_shell' => true]); if (!is_resource($process)) throw new RuntimeException('Process startup failed'); $processes[] = $process; return $process; }
function stop(mixed $process): void { if (is_resource($process)) { proc_terminate($process); proc_close($process); } }
function http(string $client, string $path, mixed $body = null, string $method = 'GET', array $headers = []): array {
    global $base, $temp; $handle = curl_init($base . $path);
    if ($body !== null && !is_string($body)) $body = json_encode($body, JSON_THROW_ON_ERROR);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 15000, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_COOKIEFILE => $temp . '/' . $client . '.cookies', CURLOPT_COOKIEJAR => $temp . '/' . $client . '.cookies', CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($handle); if ($raw === false) throw new RuntimeException(curl_error($handle)); $status = curl_getinfo($handle, CURLINFO_HTTP_CODE); curl_setopt($handle, CURLOPT_COOKIELIST, 'FLUSH');
    $json = json_decode($raw, true); return [$status, json_last_error() === JSON_ERROR_NONE ? $json : $raw];
}
function csrf(string $client): string { return http($client, '/gdpr.php?policy=csrf')[1]['token']; }
function api(string $client, string $policy, mixed $body = null, string $method = 'POST', bool|string $token = true): array { $headers = ['Content-Type: application/json']; if ($token !== false) $headers[] = 'X-CSRF-Token: ' . ($token === true ? csrf($client) : $token); return http($client, '/gdpr.php?policy=' . $policy, $body ?? new stdClass(), $method, $headers); }
function form(string $client, string $path, array $value): array { return http($client, $path, http_build_query($value), 'POST', ['Content-Type: application/x-www-form-urlencoded']); }
function formToken(string $client,string $path): string {$body=http($client,$path)[1];if(!preg_match('/name="csrf_token" value="([^"]+)"/',$body,$m))throw new RuntimeException('Native CSRF missing');return html_entity_decode($m[1]);}
function login(string $client,string $name): array {return form($client,'/login.php',['csrf_token'=>formToken($client,'/login.php'),'username'=>$name,'password'=>'Integration123!']);}
function sourceHashes(): array { global $root; $files = array_merge([$root . '/integration/privacy-lib/GdprRouter.php'], glob($root . '/integration/privacy-lib/gdpr/*.rego')); return array_map(fn($p) => hash_file('sha256', $p), $files); }
$original = sourceHashes(); $exit = 0;
$db=null;
try {
    $fixture=$temp.'/app';
    foreach(['includes','integration'] as $folder){mkdir($fixture.'/'.$folder);$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$folder,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($iterator as $file){$relative=substr($file->getPathname(),strlen($root)+1);$target=$fixture.'/'.$relative;if($file->isDir()){if(!is_dir($target))mkdir($target,0777,true);}else copy($file->getPathname(),$target);}}
    foreach(glob($root.'/*.php') as $file){if(basename($file)!=='config.php')copy($file,$fixture.'/'.basename($file));}
    file_put_contents($fixture.'/config.php',"<?php\n\$config=require __DIR__.'/config-example.php';\n\$config['db_path']=__DIR__.'/fixture.db';\n\$config['base_path']='';\nreturn \$config;\n");
    $port=freePort();$opaPort=freePort();$base='http://127.0.0.1:'.$port;
    $env=array_merge(getenv(),['OPA_QUERY_URL'=>'http://127.0.0.1:'.$opaPort.'/v1/query']);
    $opa=launch([$opaBinary,'run','--server','--addr','127.0.0.1:'.$opaPort,$fixture.'/integration/privacy-lib/gdpr'],$fixture);
    $app=launch(array_merge($phpArgs,['-S','127.0.0.1:'.$port,'-t',$fixture,$fixture.'/server.php']),$fixture,$env);
    $ready=false;for($i=0;$i<100;$i++){try{if(http('anon','/install.php')[0]===200){$socket=@stream_socket_client('tcp://127.0.0.1:'.$opaPort);if($socket){fclose($socket);$ready=true;break;}}}catch(Throwable){}usleep(100000);}if(!$ready)throw new RuntimeException('Startup timed out');
    check('Original installer creates administrator',str_contains(form('anon','/install.php',['username'=>'AlicePrivate','display_name'=>'AliceDisplay','password'=>'Integration123!','confirm_password'=>'Integration123!'])[1],'Administrator account created successfully'));
    $migration=launch(array_merge($phpArgs,[$fixture.'/integration/migrate.php']),$fixture,$env);if(proc_close($migration)!==0)throw new RuntimeException('Consent migration failed');
    $db=new PDO('sqlite:'.$fixture.'/fixture.db');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    check('Original login form renders',str_contains(http('alice','/login.php')[1],'name="username"'));
    check('Original native login redirects',login('alice','AlicePrivate')[0]===302);
    check('Anonymous privacy page denied',http('anon','/gdpr.php')[0]===401);
    check('Anonymous GDPR mutation denied',api('anon','consent',token:false)[0]===401);
    check('Header links privacy page',str_contains(http('alice','/index.php')[1],'Privacy checks'));
    check('Privacy page renders identity',str_contains(http('alice','/gdpr.php')[1],'AlicePrivate'));
    foreach(['/config.php','/fixture.db','/integration/schema.sql','/integration/test.php'] as $path)check('Private path denied: '.$path,http('anon',$path)[0]===404);
    $bookmark=form('alice','/api.php?action=add',['csrf_token'=>csrf('alice'),'url'=>'https://example.org/shared','title'=>'GlobalSharedBookmark','description'=>'GlobalSharedBody']);
    check('Original bookmark creation works',$bookmark[0]===200&&$bookmark[1]['success']===true);
    check('Missing CSRF rejected', api('alice', 'consent', token:false)[0] === 403);
    check('Invalid CSRF rejected', api('alice', 'consent', token:'invalid')[0] === 403);
    check('Missing consent rejects processing', api('alice', 'consent')[1] === false);
    check('Consent is persisted', api('alice', 'consent', ['value'=>'yes','withdrawn'=>false], 'PATCH')[1] === ['value'=>'yes','withdrawn'=>false]);
    check('Consent belongs to authenticated user',(int)$db->query('SELECT user_id FROM gdpr_consents')->fetchColumn()===1);
    foreach (['consent','lawful'] as $policy) check($policy . ' accepts stored consent', api('alice',$policy)[1] === true);
    check('Information report uses stored consent', api('alice','information')[1]['consent_valid'] === true);
    $access = api('alice','access')[1]; $data=json_decode($access['download']['content'],true);$fields=array_column($data['data_collected_from_user'],'field');sort($fields);
    check('Access maps real profile metadata', $data['user_id']==='1' && $fields===['display_name','username']);
    $rect=api('alice','rectification',['updates'=>['username'=>'UpdatedAlice']])[1];
    check('Rectification returns scoped update plan', $rect['request']['validated'] && count(array_filter($rect['db_update_plan'],fn($x)=>$x['user_id']==='1'&&$x['field']==='username'))>0);
    check('Synthetic retention exception rejects display-name erasure', api('alice','erasure',['fields'=>['display_name']])[1]['decision']==='reject');
    check('Restriction returns acceptance plan', api('alice','restriction')[1]['decision']==='accept');
    $export=api('alice','portability')[1];$exportText=json_encode($export);
    check('Portability exports real values in two formats', $export['decision']==='accept'&&count($export['actions']['downloads'])===2&&str_contains($exportText,'AlicePrivate')&&str_contains($exportText,'AliceDisplay'));
    check('Passwords and hashes excluded', !str_contains($exportText,'Integration123')&&!str_contains($exportText,'user_password_hash')&&!str_contains(json_encode($access),'password'));
    check('Objection returns stop plan',api('alice','objection',['reason'=>'marketing'])[1]['decision']==='accept');
    check('Complaint returns forwarding plan',api('alice','complaint',['authority_id'=>'cy-0000','description'=>'Test only'])[1]['decision']==='accept');
    check('Configured admin may assess breach',api('alice','breach',['incident_id'=>'test'])[1]['decision']==='notify_users');
    check('Original admin creates ordinary user',str_contains(form('alice','/admin.php',['csrf_token'=>csrf('alice'),'action'=>'add_user','username'=>'BobPrivate','password'=>'Integration123!','role'=>'user'])[1],'User added successfully'));
    check('Original ordinary-user login works',login('bob','BobPrivate')[0]===302);
    check('Second user has independent consent',api('bob','consent')[1]===false);
    check('Non-admin breach request denied',api('bob','breach',['incident_id'=>'test'])[0]===403);
    api('bob','consent',['value'=>'yes','withdrawn'=>false],'PATCH');$bob=json_encode(api('bob','portability')[1]);
    check('Bob export excludes Alice',str_contains($bob,'BobPrivate')&&!str_contains($bob,'AlicePrivate'));
    check('Shared bookmarks excluded from user exports',!str_contains($bob,'GlobalSharedBookmark')&&!str_contains(json_encode(api('alice','portability')[1]),'GlobalSharedBody'));
    check('Alice export excludes Bob',!str_contains(json_encode(api('alice','portability')[1]),'BobPrivate'));
    check('Client cannot select another subject',api('bob','access',['user_id'=>'1'])[0]===400);
    check('Trusted checks cannot be overridden',api('alice','restriction',['checks'=>['data_correct'=>true]])[0]===400);
    check('Cross-session CSRF rejected',api('bob','consent',token:csrf('alice'))[0]===403);
    check('Strict boolean validation',api('alice','consent',['value'=>'yes','withdrawn'=>'false'],'PATCH')[0]===400);
    check('Unknown operations rejected',api('alice','unknown')[0]===400);
    check('Invalid JSON rejected',api('alice','consent','{')[0]===400);
    check('Array JSON rejected',api('alice','consent','[]')[0]===400);
    check('Unsupported method rejected',http('alice','/gdpr.php?policy=access')[0]===405);
    check('Consent withdrawal saved',api('alice','consent',['value'=>'yes','withdrawn'=>true],'PATCH')[0]===200);
    foreach(['consent','lawful'] as $policy)check($policy.' rejects after withdrawal',api('alice',$policy)[1]===false);
    check('Portability rejects after withdrawal',api('alice','portability')[1]['decision']==='reject');
    check('Access remains available after withdrawal',api('alice','access')[1]['request']['validated']===true);
    check('Eligible erasure returns delete plan',api('alice','erasure',['fields'=>['username'],'reason'=>'withdrawn_consent'])[1]['decision']==='accept');
    check('Plans did not modify original account',str_contains(http('alice','/account.php')[1],'AlicePrivate'));
    http('alice','/logout.php');check('Original logout invalidates GDPR session',http('alice','/gdpr.php')[0]===401);
    check('Original username login still works',login('alice','AlicePrivate')[0]===302&&str_contains(http('alice','/account.php')[1],'AlicePrivate'));
    stop($opa);check('OPA outage returns 502',api('alice','consent')[0]===502);
    check('Router and policies remain unchanged',sourceHashes()===$original);
    echo "Integration result: $checks/$checks passed\n";
} catch(Throwable $error) {fwrite(STDERR,$error->getMessage()."\n".(@file_get_contents($temp.'/server.log')?:''));$exit=1;}
finally {
    foreach(array_reverse($processes) as $process)stop($process);
    $db=null;
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($temp);
}
exit($exit);
