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
function audit(string $action,string $entity,$entityId=null,array $metadata=[]): void { $u=actor(); try { $s=db()->prepare('INSERT INTO `AdminAuditLog` (id,actorId,action,entity,entityId,metadata,ipAddress) VALUES(?,?,?,?,?,?,?)'); $s->execute([bin2hex(random_bytes(12)),$u['id']??null,$action,$entity,$entityId,json_encode($metadata),$_SERVER['REMOTE_ADDR']??null]); } catch(Throwable $e) { error_log($e->getMessage()); } }
function page_params(): array { $page=max(1,(int)($_GET['page']??1)); $per=min(100,max(5,(int)($_GET['perPage']??20))); return [$page,$per,($page-1)*$per]; }
function paged(string $sql,array $params,string $countSql, array $countParams=[]): never { [$page,$per,$offset]=page_params(); $total=(int)db()->prepare($countSql)->execute($countParams); $countStmt=db()->prepare($countSql); $countStmt->execute($countParams); $total=(int)$countStmt->fetchColumn(); $stmt=db()->prepare($sql." LIMIT :per OFFSET :offset"); foreach($params as $k=>$v) $stmt->bindValue(is_int($k)?$k+1:$k,$v); $stmt->bindValue(':per',$per,PDO::PARAM_INT); $stmt->bindValue(':offset',$offset,PDO::PARAM_INT); $stmt->execute(); respond($stmt->fetchAll(),200,['page'=>$page,'perPage'=>$per,'total'=>$total,'pages'=>(int)ceil($total/$per)]); }
function clean_text($value,int $max=500): string { return mb_substr(trim((string)$value),0,$max); }
function money($value): string { return number_format((float)$value,2,'.',''); }
function media_directory(): string {
    global $config;
    return $config['app']['uploads_dir'] ?? (__DIR__ . '/../../uploads/images');
}
function media_metadata_path(): string { return media_directory() . DIRECTORY_SEPARATOR . '.media.json'; }
function media_metadata(): array {
    $path = media_metadata_path();
    if (!is_file($path)) return [];
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}
function save_media_metadata(array $metadata): void {
    file_put_contents(media_metadata_path(), json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
}
function media_filename(string $filename): string {
    $filename = basename($filename);
    if ($filename === '' || $filename === '.' || $filename === '..' || str_starts_with($filename, '.')) fail('Invalid media file.', 422);
    return $filename;
}
function html_fragment(string $html): string {
    $html = preg_replace('/^\s*<!doctype\s+html[^>]*>/i', '', $html);
    if (!class_exists('DOMDocument')) {
        $html = preg_replace('/<head\b[^>]*>.*?<\/head>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        return trim(preg_replace('/<\/?(?:html|body)\b[^>]*>/i', '', $html));
    }
    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML($html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body) return trim($html);
    $fragment = '';
    foreach (iterator_to_array($body->childNodes) as $child) $fragment .= $dom->saveHTML($child);
    return trim($fragment);
}
function sanitize_html(string $html): string {
    $html = html_fragment($html);
    $allowedTags = ['p','br','strong','b','em','i','u','s','h2','h3','ul','ol','li','blockquote','a','img','table','thead','tbody','tr','th','td','div','span','iframe'];
    $allowedAttributes = ['class','href','src','alt','title','target','rel','width','height','colspan','rowspan','scope','frameborder','allow','allowfullscreen'];
    if (!class_exists('DOMDocument')) {
        $html = preg_replace('/<(script|style|object|embed|form|input|button|textarea|link|meta)\b[^>]*>.*?<\/\1>/is', '', $html);
        $html = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $html);
        return preg_replace_callback('/<\/?([a-z0-9]+)([^>]*)>/i', static function (array $match) use ($allowedTags, $allowedAttributes): string {
            $tag = strtolower($match[1]);
            if (!in_array($tag, $allowedTags, true) || str_starts_with($match[0], '</')) return in_array($tag, $allowedTags, true) ? "</$tag>" : '';
            $attributes = preg_replace_callback('/\s+([a-zA-Z_:][a-zA-Z0-9:._-]*)(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/i', static function (array $attribute) use ($allowedAttributes, $tag): string {
                $name = strtolower($attribute[1]);
                if (!in_array($name, $allowedAttributes, true) || str_starts_with($name, 'on') || $name === 'style') return '';
                $value = trim($attribute[2] ?? '', "\\\"'");
                if (in_array($name, ['src', 'href'], true) && !safe_html_url($value, $tag)) return '';
                if ($name === 'class') {
                    $classes = preg_split('/\s+/', $value) ?: [];
                    $value = implode(' ', array_filter($classes, static fn(string $class): bool => preg_match('/^(cta-box|cta-btn|responsive-image|video-embed|align-left|align-center|align-right)$/', $class) === 1));
                    if ($value === '') return '';
                }
                return ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
            }, $match[2]);
            return '<' . $tag . $attributes . '>';
        }, $html);
    }
    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<!doctype html><html><body><div id="content-root">' . $html . '</div></body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $root = $dom->getElementById('content-root');
    if (!$root) return '';
    $walk = static function (DOMNode $node) use (&$walk, $allowedTags, $allowedAttributes): void {
        if ($node->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($node->nodeName);
            if (!in_array($tag, $allowedTags, true)) {
                while ($node->firstChild) $node->parentNode->insertBefore($node->firstChild, $node);
                $node->parentNode->removeChild($node);
                return;
            }
            if ($node->hasAttributes()) {
                for ($index = $node->attributes->length - 1; $index >= 0; $index--) {
                    $attribute = $node->attributes->item($index);
                    $name = strtolower($attribute->name);
                    if (!in_array($name, $allowedAttributes, true) || str_starts_with($name, 'on') || $name === 'style') {
                        $node->removeAttributeNode($attribute);
                        continue;
                    }
                    if (in_array($name, ['src', 'href'], true) && !safe_html_url($attribute->value, $tag)) $node->removeAttribute($attribute->name);
                    if ($name === 'class') {
                        $classes = preg_split('/\s+/', trim($attribute->value)) ?: [];
                        $classes = array_filter($classes, static fn(string $class): bool => preg_match('/^(cta-box|cta-btn|responsive-image|video-embed|align-left|align-center|align-right)$/', $class) === 1);
                        if ($classes) $node->setAttribute('class', implode(' ', $classes)); else $node->removeAttribute('class');
                    }
                }
            }
            if ($tag === 'a' && $node->hasAttribute('target')) $node->setAttribute('rel', 'noopener noreferrer');
        }
        foreach (iterator_to_array($node->childNodes) as $child) $walk($child);
    };
    foreach (iterator_to_array($root->childNodes) as $child) $walk($child);
    $result = '';
    foreach (iterator_to_array($root->childNodes) as $child) $result .= $dom->saveHTML($child);
    return trim($result);
}
function safe_html_url(string $url, string $tag = ''): bool {
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x20]/', $url)) return false;
    if (str_starts_with($url, '//')) return false;
    if (str_starts_with($url, '/') || str_starts_with($url, '#')) return true;
    if (!str_contains($url, ':')) return true;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($tag === 'iframe') return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), ['www.youtube.com','youtube.com','www.youtube-nocookie.com','player.vimeo.com','vimeo.com'], true) && $scheme === 'https';
    return in_array($scheme, ['http','https'], true);
}
