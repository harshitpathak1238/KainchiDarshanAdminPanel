<?php
$configFile = __DIR__ . '/../config.php';
$config = is_file($configFile) ? require $configFile : require __DIR__ . '/../config.example.php';
date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Kolkata');
$ADMIN_ACCOUNTS = [
    'harshitpathak1238@gmail.com' => ['name' => 'Harshit Pathak', 'role' => 'OWNER'],
    'nilanshnegi1717@gmail.com' => ['name' => 'Nilansh Negi', 'role' => 'ADMIN'],
    'singhrawatpawan135@gmail.com' => ['name' => 'Pawan Singh Rawat', 'role' => 'ADMIN'],
];
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('kainchi_admin');
    session_set_cookie_params(['httponly'=>true,'secure'=>(bool)($config['app']['secure_cookies'] ?? true),'samesite'=>'Lax','path'=>'/']);
    session_start();
}
function db(): PDO {
    static $pdo;
    global $config;
    if ($pdo) return $pdo;
    $d = $config['db'];
    $pdo = new PDO("mysql:host={$d['host']};dbname={$d['name']};charset={$d['charset']}", $d['user'], $d['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
    return $pdo;
}
function json_input(): array { $raw = file_get_contents('php://input'); $data = json_decode($raw ?: '{}', true); return is_array($data) ? $data : []; }
function respond($data = null, int $status = 200, array $meta = []): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($status >= 400 ? $data : ['data'=>$data,'meta'=>$meta], JSON_UNESCAPED_SLASHES); exit; }
function fail(string $message, int $status = 400, array $fields = []): never { respond(['error'=>$message] + ($fields ? ['fieldErrors'=>$fields] : []), $status); }
function actor(): ?array { return $_SESSION['admin_user'] ?? null; }
function allowed_admin(string $email): ?array { global $ADMIN_ACCOUNTS; $email = strtolower(trim($email)); return $ADMIN_ACCOUNTS[$email] ?? null; }
function require_auth(array $roles = []): array { $user = actor(); if (!$user) fail('Authentication required',401); if ($roles && !in_array($user['role'],$roles,true)) fail('You do not have permission for this action',403); return $user; }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function require_csrf(): void { $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''; if (!$sent || !hash_equals($_SESSION['csrf'] ?? '', $sent)) fail('Invalid or missing CSRF token',419); }
function audit(string $action,string $entity,$entityId=null,array $metadata=[]): void { $u=actor(); try { $s=db()->prepare('INSERT INTO audit_logs(actor_id,action,entity,entity_id,metadata,ip_address) VALUES(?,?,?,?,?,?)'); $s->execute([$u['id']??null,$action,$entity,$entityId,json_encode($metadata),$_SERVER['REMOTE_ADDR']??null]); } catch(Throwable $e) { error_log($e->getMessage()); } }
function page_params(): array { $page=max(1,(int)($_GET['page']??1)); $per=min(100,max(5,(int)($_GET['perPage']??20))); return [$page,$per,($page-1)*$per]; }
function paged(string $sql,array $params,string $countSql, array $countParams=[]): never { [$page,$per,$offset]=page_params(); $total=(int)db()->prepare($countSql)->execute($countParams); $countStmt=db()->prepare($countSql); $countStmt->execute($countParams); $total=(int)$countStmt->fetchColumn(); $stmt=db()->prepare($sql." LIMIT :per OFFSET :offset"); foreach($params as $k=>$v) $stmt->bindValue(is_int($k)?$k+1:$k,$v); $stmt->bindValue(':per',$per,PDO::PARAM_INT); $stmt->bindValue(':offset',$offset,PDO::PARAM_INT); $stmt->execute(); respond($stmt->fetchAll(),200,['page'=>$page,'perPage'=>$per,'total'=>$total,'pages'=>(int)ceil($total/$per)]); }
function clean_text($value,int $max=500): string { return mb_substr(trim((string)$value),0,$max); }
function money($value): string { return number_format((float)$value,2,'.',''); }
