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
function nativeToken(string $client, string $path): string { $html=http($client,$path)[1];if(!preg_match('/name="csrf_token" value="([^"]+)"/',$html,$m))throw new RuntimeException('Native CSRF missing');return html_entity_decode($m[1]); }
function register(string $client, string $name, string $email): array {
    return form($client,'/auth/register.php',['csrf_token'=>nativeToken($client,'/auth/register.php'),'first_name'=>$name,'middle_name'=>'','last_name'=>'PrivateLast','address'=>$name.' PrivateAddress','mobile'=>$client==='alice'?'0000000001':'0000000002','username'=>$name,'email'=>$email,'password'=>'Integration123!','confirm_password'=>'Integration123!']);
}
function login(string $client, string $name): array {
    form($client,'/auth/login.php',['csrf_token'=>nativeToken($client,'/auth/login.php'),'username'=>$name,'password'=>'Integration123!']);
    return http($client,'/dashboard.php');
}
function transaction(string $client,string $description,string $type='expense'): array {
    return form($client,'/add-transaction.php',['csrf_token'=>csrf($client),'type'=>$type,'amount'=>'12.34','txn_date'=>'2025-01-10','category'=>$type==='income'?'Salary':'Food','description'=>$description]);
}
function sourceHashes(): array { global $root; $files = array_merge([$root . '/integration/privacy-lib/GdprRouter.php'], glob($root . '/integration/privacy-lib/gdpr/*.rego')); return array_map(fn($p) => hash_file('sha256', $p), $files); }
$original = sourceHashes(); $exit = 0;
$admin = null; $created = false; $createdAccounts = [];
$database = 'privacy_ledgerly_test_' . bin2hex(random_bytes(8));
$testUser = 'privacy_test_' . bin2hex(random_bytes(6));
$testPassword = bin2hex(random_bytes(32));
$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$dbPort = (int) (getenv('TEST_DB_PORT') ?: 3306);

try {
    $admin = new mysqli($host, getenv('TEST_DB_USER') ?: 'root', getenv('TEST_DB_PASSWORD') ?: '', '', $dbPort);
    $admin->query("CREATE DATABASE `$database` CHARACTER SET utf8mb4"); $created = true;
    $admin->select_db($database);
    $schema=preg_replace('/^\s*--.*$/m','',file_get_contents($root.'/database.sql'));
    $schema=preg_replace('/^\s*(DROP DATABASE[^;]*;|CREATE DATABASE[^;]*;|USE\s+[^;]*;)/mi','',$schema);
    $admin->multi_query($schema);do {if($result=$admin->store_result())$result->free();}while($admin->more_results()&&$admin->next_result());
    $admin->query(file_get_contents($root.'/integration/schema.sql'));
    foreach (['localhost', '127.0.0.1'] as $accountHost) {
        $account = "'$testUser'@'$accountHost'";
        $admin->query("CREATE USER $account IDENTIFIED BY '$testPassword'"); $createdAccounts[] = $account;
        $admin->query("GRANT SELECT, INSERT, UPDATE, DELETE ON `$database`.* TO $account");
    }
    $port = freePort(); $opaPort = freePort(); $base = 'http://127.0.0.1:' . $port;
    $env = array_merge(getenv(), ['LEDGERLY_DB_HOST' => $host . ';port=' . $dbPort, 'LEDGERLY_DB_NAME' => $database, 'LEDGERLY_DB_USER' => $testUser, 'LEDGERLY_DB_PASS' => $testPassword, 'OPA_QUERY_URL' => 'http://127.0.0.1:' . $opaPort . '/v1/query', 'GDPR_ADMIN_USER_IDS' => '1']);
    $opa = launch([$opaBinary, 'run', '--server', '--addr', '127.0.0.1:' . $opaPort, $root . '/integration/privacy-lib/gdpr'], $root);
    $app = launch(array_merge($phpArgs, ['-d', 'session.save_path=' . $temp . '/sessions', '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/server.php']), $root, $env);
    $ready = false;
    for ($i=0; $i<100; $i++) { try { if (http('anon', '/auth/login.php')[0] === 200) { $socket = @stream_socket_client('tcp://127.0.0.1:' . $opaPort); if ($socket) {fclose($socket);$ready=true;break;} } } catch (Throwable) {} usleep(100000); }
    if (!$ready) throw new RuntimeException('Startup timed out');
    check('Original login page renders', str_contains(http('alice', '/auth/login.php')[1], 'Log in'));
    check('Original registration page renders', str_contains(http('alice', '/auth/register.php')[1], 'name="username"'));
    check('Anonymous GDPR API denied', api('anon', 'consent', token:false)[0] === 401);
    check('Anonymous privacy page denied', http('anon', '/gdpr.php')[0] === 401);
    check('Database configuration is not served', http('anon', '/includes/db.php')[0] === 404);
    check('Installation SQL is not served', http('anon', '/integration/schema.sql')[0] === 404);
    check('Original registration creates account',register('alice','AlicePrivate','alice@example.org')[0]===302);
    check('Original login works', str_contains(login('alice', 'AlicePrivate')[1], 'Welcome back, AlicePrivate'));
    check('Original expense creation works',transaction('alice','AlicePrivateExpense')[0]===302);
    check('Original income creation works',transaction('alice','AlicePrivateIncome','income')[0]===302);
    check('Account links privacy page', str_contains(http('alice', '/dashboard.php')[1], 'Privacy checks'));
    check('Privacy page renders real identity', str_contains(http('alice', '/gdpr.php')[1], 'AlicePrivate'));
    check('Missing CSRF rejected', api('alice', 'consent', token:false)[0] === 403);
    check('Invalid CSRF rejected', api('alice', 'consent', token:'invalid')[0] === 403);
    check('Missing consent rejects processing', api('alice', 'consent')[1] === false);
    check('Consent is persisted', api('alice', 'consent', ['value'=>'yes','withdrawn'=>false], 'PATCH')[1] === ['value'=>'yes','withdrawn'=>false]);
    $db = new mysqli($host, $testUser, $testPassword, $database, $dbPort); $stored=$db->query('SELECT user_id,value,withdrawn FROM gdpr_consents')->fetch_assoc(); check('Consent belongs to authenticated ID', (int)$stored['user_id']===1 && $stored['value']==='yes' && (int)$stored['withdrawn']===0); $db->close();
    foreach (['consent','lawful'] as $policy) check($policy . ' accepts stored consent', api('alice',$policy)[1] === true);
    check('Information report uses stored consent', api('alice','information')[1]['consent_valid'] === true);
    $access = api('alice','access')[1]; $data=json_decode($access['download']['content'],true);$fields=array_column($data['data_collected_from_user'],'field');sort($fields);
    check('Access maps real profile metadata', $data['user_id']==='1' && $fields===['address','email','first_name','last_name','middle_name','mobile','transactions','username']);
    $rect=api('alice','rectification',['updates'=>['username'=>'UpdatedAlice']])[1];
    check('Rectification returns scoped update plan', $rect['request']['validated'] && count(array_filter($rect['db_update_plan'],fn($x)=>$x['user_id']==='1'&&$x['field']==='username'))>0);
    check('Retention exception rejects email erasure', api('alice','erasure',['fields'=>['email']])[1]['decision']==='reject');
    check('Restriction returns acceptance plan', api('alice','restriction')[1]['decision']==='accept');
    $export=api('alice','portability')[1];$exportText=json_encode($export);
    check('Portability exports real values in two formats', $export['decision']==='accept'&&count($export['actions']['downloads'])===2&&str_contains($exportText,'AlicePrivate')&&str_contains($exportText,'alice@example.org'));
    check('Passwords and hashes excluded', !str_contains($exportText,'Integration123')&&!str_contains($exportText,'user_password_hash')&&!str_contains(json_encode($access),'password'));
    check('Objection returns stop plan',api('alice','objection',['reason'=>'marketing'])[1]['decision']==='accept');
    check('Complaint returns forwarding plan',api('alice','complaint',['authority_id'=>'cy-0000','description'=>'Test only'])[1]['decision']==='accept');
    check('Configured admin may assess breach',api('alice','breach',['incident_id'=>'test'])[1]['decision']==='notify_users');
    register('bob','BobPrivate','bob@example.org');login('bob','BobPrivate');
    check('Second user expense creation works',transaction('bob','BobPrivateExpense')[0]===302);
    check('Second user has independent consent',api('bob','consent')[1]===false);
    check('Non-admin breach request denied',api('bob','breach',['incident_id'=>'test'])[0]===403);
    api('bob','consent',['value'=>'yes','withdrawn'=>false],'PATCH');$bob=json_encode(api('bob','portability')[1]);
    check('Bob export excludes Alice',str_contains($bob,'bob@example.org')&&!str_contains($bob,'alice@example.org'));
    check('Bob export includes owned expense',str_contains($bob,'BobPrivateExpense'));
    check('Bob export excludes Alice finance and address',!str_contains($bob,'AlicePrivateExpense')&&!str_contains($bob,'AlicePrivateIncome')&&!str_contains($bob,'AlicePrivate PrivateAddress'));
    $aliceExport=json_encode(api('alice','portability')[1]);
    check('Admin export includes only own income and expenses',str_contains($aliceExport,'AlicePrivateIncome')&&str_contains($aliceExport,'AlicePrivateExpense')&&!str_contains($aliceExport,'BobPrivateExpense'));
    check('Public contact messages excluded',!str_contains($aliceExport,'ruwan@example.com')&&!str_contains($aliceExport,'school fees'));
    check('Alice export excludes Bob',!str_contains(json_encode(api('alice','portability')[1]),'bob@example.org'));
    check('Client cannot select another subject',api('bob','access',['user_id'=>'1'])[0]===400);
    check('Trusted checks cannot be overridden',api('alice','restriction',['checks'=>['data_correct'=>true]])[0]===400);
    check('Cross-session CSRF rejected',api('bob','consent',token:csrf('alice'))[0]===403);
    check('Strict boolean validation',api('alice','consent',['value'=>'yes','withdrawn'=>'false'],'PATCH')[0]===400);
    check('Unknown operations rejected',api('alice','unknown')[0]===400);
    check('Invalid JSON rejected',api('alice','consent','{')[0]===400);
    check('Array JSON rejected',api('alice','consent','[]')[0]===400);
    check('Unsupported method rejected',http('alice','/gdpr.php?policy=access')[0]===405);
    check('Oversized request rejected',api('alice','consent',str_repeat('x',16385))[0]===413);
    check('Array-valued update rejected',api('alice','rectification',['updates'=>['email'=>['other@example.org']]])[0]===400);
    check('Native username rules respected',api('alice','rectification',['updates'=>['username'=>'x']])[0]===400);
    check('Consent withdrawal saved',api('alice','consent',['value'=>'yes','withdrawn'=>true],'PATCH')[0]===200);
    foreach(['consent','lawful'] as $policy)check($policy.' rejects after withdrawal',api('alice',$policy)[1]===false);
    check('Portability rejects after withdrawal',api('alice','portability')[1]['decision']==='reject');
    check('Access remains available after withdrawal',api('alice','access')[1]['request']['validated']===true);
    check('Eligible erasure returns delete plan',api('alice','erasure',['fields'=>['username'],'reason'=>'withdrawn_consent'])[1]['decision']==='accept');
    $db=new mysqli($host,$testUser,$testPassword,$database,$dbPort);
    check('Plans did not modify financial records',(int)$db->query("SELECT COUNT(*) AS n FROM expenses WHERE description IN ('AlicePrivateExpense','AlicePrivateIncome')")->fetch_assoc()['n']===2);$db->close();
    check('Plans did not modify original account',str_contains(http('alice','/dashboard.php')[1],'AlicePrivate'));
    http('alice','/auth/logout.php');check('Original logout invalidates GDPR session',http('alice','/gdpr.php')[0]===401);
    check('Original username login still works',str_contains(login('alice','AlicePrivate')[1],'Welcome back, AlicePrivate'));
    stop($opa);check('OPA outage returns 502',api('alice','consent')[0]===502);
    check('Router and policies remain unchanged',sourceHashes()===$original);
    echo "Integration result: $checks/$checks passed\n";
} catch(Throwable $error) {fwrite(STDERR,$error->getMessage()."\n".(@file_get_contents($temp.'/server.log')?:''));$exit=1;}
finally {
    foreach(array_reverse($processes) as $process)stop($process);
    if ($admin instanceof mysqli) {
        if ($created && preg_match('/^privacy_ledgerly_test_[a-f0-9]{16}$/', $database)) $admin->query("DROP DATABASE `$database`");
        foreach ($createdAccounts as $account) $admin->query("DROP USER $account");
        $admin->close();
    }
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($temp);
}
exit($exit);
