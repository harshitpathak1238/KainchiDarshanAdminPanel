<?php
require __DIR__ . '/lib.php';

$path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
$parts = explode('/', $path);
$resource = $parts[2] ?? '';
$id = isset($parts[3]) && $parts[3] !== '' ? $parts[3] : null;
$action = $parts[3] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($resource === 'auth') {
        if ($action === 'csrf' && $method === 'GET') {
            respond(['csrf' => csrf_token(), 'user' => actor()]);
        }
        if ($action === 'login' && $method === 'POST') {
            $input = json_input();
            $email = strtolower(trim($input['email'] ?? ''));
            $approved = allowed_admin($email);
            if (!$approved) {
                fail('This email is not authorized for admin access', 401);
            }
            $stmt = db()->prepare('SELECT id FROM `User` WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            $userId = $user['id'] ?? null;
            $_SESSION['admin_user'] = ['id' => $userId, 'name' => $approved['name'], 'email' => $email, 'role' => $approved['role']];
            audit('login', 'User', $userId);
            respond(['user' => $_SESSION['admin_user'], 'csrf' => csrf_token()]);
        }
        if ($action === 'logout' && $method === 'POST') {
            require_auth();
            require_csrf();
            $_SESSION = [];
            session_destroy();
            respond(['loggedOut' => true]);
        }
        fail('Endpoint not found', 404);
    }

    if ($resource === 'health' && $method === 'GET') {
        try {
            db()->query('SELECT 1');
            respond(['database' => 'ok', 'php' => PHP_VERSION, 'time' => gmdate('c')]);
        } catch (Throwable $error) {
            error_log($error->getMessage());
            respond(['database' => 'degraded', 'time' => gmdate('c')]);
        }
    }

    if ($resource === 'blog' && $action === 'public' && $method === 'GET') {
        $where = "status = 'PUBLISHED' AND publishedAt IS NOT NULL AND publishedAt <= UTC_TIMESTAMP()";
        $params = [];
        if (!empty($_GET['tag'])) {
            $where .= ' AND JSON_CONTAINS(tags, ?, "$")';
            $params[] = json_encode((string) $_GET['tag']);
        }
        $stmt = db()->prepare("SELECT id, slug, title, metaTitle, metaDescription, excerpt, body, authorName, category, tags, featuredImage, imageAltText, publishedAt FROM `BlogPost` WHERE $where ORDER BY publishedAt DESC");
        $stmt->execute($params);
        $posts = $stmt->fetchAll();
        foreach ($posts as &$post) {
            $post['body'] = sanitize_html((string) $post['body']);
            $post['excerpt'] = sanitize_html((string) $post['excerpt']);
            $post['tags'] = json_decode($post['tags'] ?? '[]', true) ?: [];
        }
        respond($posts);
    }

    require_auth();

    if ($resource === 'blog-authors' && $method === 'GET') {
        try {
            $authors = db()->query("SELECT id, name, email, role FROM `User` WHERE role IN ('OWNER','ADMIN','STAFF') AND isActive = 1 ORDER BY name ASC")->fetchAll();
            respond($authors);
        } catch (Throwable $error) {
            global $config;
            $database = $config['db'] ?? [];
            error_log(sprintf(
                '[blog-authors] Database failure: %s | SQLSTATE=%s | host=%s | database=%s | user=%s | request=%s',
                $error->getMessage(),
                $error instanceof PDOException ? (string) $error->getCode() : 'n/a',
                $database['host'] ?? 'unknown',
                $database['name'] ?? 'unknown',
                $database['user'] ?? 'unknown',
                $_SERVER['REQUEST_URI'] ?? '/api/blog-authors'
            ));
            fail('Staff accounts could not be loaded. Check the server database log.', 503);
        }
    }

    if ($resource === 'dashboard' && $method === 'GET') {
        $result = ['bookingsToday' => 0, 'revenue30' => 0, 'unassignedPickups' => 0, 'pendingPartners' => 0, 'failedPayments' => 0, 'categoryCounts' => [], 'recentOrders' => [], 'degraded' => []];
        $queries = [
            'bookingsToday' => "SELECT COUNT(*) FROM `Booking` WHERE DATE(createdAt) = UTC_DATE()",
            'revenue30' => "SELECT COALESCE(SUM(totalPrice), 0) FROM `Booking` WHERE createdAt >= UTC_TIMESTAMP() - INTERVAL 30 DAY AND status <> 'CANCELLED'",
            'unassignedPickups' => "SELECT COUNT(*) FROM `PickupRequest` WHERE status = 'UNASSIGNED'",
            'pendingPartners' => "SELECT COUNT(*) FROM `Partner` WHERE verificationStatus = 'PENDING'",
            'failedPayments' => "SELECT COUNT(*) FROM `Payment` WHERE status = 'FAILED' AND createdAt >= UTC_TIMESTAMP() - INTERVAL 30 DAY",
        ];
        foreach ($queries as $key => $query) {
            try { $result[$key] = (float) db()->query($query)->fetchColumn(); }
            catch (Throwable $error) { error_log($error->getMessage()); $result['degraded'][] = $key; }
        }
        try {
            $result['categoryCounts'] = db()->query("SELECT category, COUNT(*) AS count FROM `Booking` WHERE createdAt >= UTC_TIMESTAMP() - INTERVAL 30 DAY GROUP BY category")->fetchAll();
        } catch (Throwable $error) { $result['degraded'][] = 'categoryCounts'; }
        try {
            $result['recentOrders'] = db()->query("SELECT t.id, t.reference, t.status, t.createdAt AS created_at, COALESCE(SUM(b.totalPrice), 0) AS amount, COALESCE(MAX(b.guestName), 'Guest') AS guest, COUNT(b.id) AS booking_count, COALESCE(MAX(p.status), 'CREATED') AS payment_status FROM `Trip` t LEFT JOIN `Booking` b ON b.tripId = t.id LEFT JOIN `Payment` p ON p.tripId = t.id GROUP BY t.id, t.reference, t.status, t.createdAt ORDER BY t.createdAt DESC LIMIT 8")->fetchAll();
        } catch (Throwable $error) { $result['degraded'][] = 'recentOrders'; }
        respond($result);
    }

    if ($resource === 'listings') {
        if ($method === 'GET') {
            $where = ['1 = 1']; $params = [];
            if (!empty($_GET['search'])) { $where[] = '(l.title LIKE ? OR l.location LIKE ? OR p.businessName LIKE ?)'; $query = '%' . clean_text($_GET['search'], 100) . '%'; $params = [$query, $query, $query]; }
            if (!empty($_GET['category'])) { $where[] = 'l.category = ?'; $params[] = $_GET['category']; }
            if (!empty($_GET['status'])) { $where[] = 'l.status = ?'; $params[] = $_GET['status']; }
            $condition = implode(' AND ', $where);
            paged("SELECT l.id, l.slug, l.title, l.description, l.location, l.category, l.basePrice AS base_price, l.sellPrice AS sell_price, l.status, l.createdAt AS created_at, l.images, l.amenities, p.businessName AS partner_name FROM `Listing` l LEFT JOIN `Partner` p ON p.id = l.partnerId WHERE $condition ORDER BY l.createdAt DESC", $params, "SELECT COUNT(*) FROM `Listing` l LEFT JOIN `Partner` p ON p.id = l.partnerId WHERE $condition", $params);
        }
        require_csrf();
        if ($method === 'DELETE' && $id) {
            $check = db()->prepare('SELECT COUNT(*) FROM `Booking` WHERE listingId = ?'); $check->execute([$id]);
            if ((int) $check->fetchColumn() > 0) fail('Listing has bookings and cannot be deleted', 409);
            db()->prepare('DELETE FROM `Listing` WHERE id = ?')->execute([$id]); respond(['deleted' => true]);
        }
        if (in_array($method, ['POST', 'PATCH'], true)) {
            $input = json_input(); $errors = [];
            foreach (['title', 'category', 'location'] as $field) if (empty($input[$field])) $errors[$field] = 'Required';
            $base = (float) ($input['base_price'] ?? $input['basePrice'] ?? 0); $sell = (float) ($input['sell_price'] ?? $input['sellPrice'] ?? 0);
            if ($base < 0 || $sell < $base) $errors['sell_price'] = 'Selling price must be greater than or equal to base price';
            if ($errors) fail('Please fix the highlighted fields', 422, $errors);
            $slug = clean_text($input['slug'] ?? preg_replace('/[^a-z0-9]+/i', '-', strtolower($input['title'])), 191);
            $images = json_encode($input['images'] ?? []); $amenities = json_encode($input['amenities'] ?? []);
            if ($method === 'POST') {
                $newId = bin2hex(random_bytes(12));
                $stmt = db()->prepare('INSERT INTO `Listing` (id, slug, partnerId, category, title, description, location, basePrice, sellPrice, images, amenities, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$newId, $slug, $input['partnerId'] ?? '', $input['category'], clean_text($input['title'], 191), clean_text($input['description'] ?? '', 191), clean_text($input['location'], 191), $base, $sell, $images, $amenities, $input['status'] ?? 'DRAFT']);
                respond(['id' => $newId]);
            }
            $stmt = db()->prepare('UPDATE `Listing` SET slug=?, title=?, description=?, location=?, category=?, basePrice=?, sellPrice=?, status=?, images=?, amenities=? WHERE id=?');
            $stmt->execute([$slug, clean_text($input['title'], 191), clean_text($input['description'] ?? '', 191), clean_text($input['location'], 191), $input['category'], $base, $sell, $input['status'] ?? 'DRAFT', $images, $amenities, $id]);
            respond(['id' => $id]);
        }
    }

    if ($resource === 'orders' && $method === 'GET') {
        $where = ['1 = 1']; $params = [];
        if (!empty($_GET['search'])) { $where[] = '(t.reference LIKE ? OR b.guestName LIKE ? OR b.guestEmail LIKE ? OR b.guestPhone LIKE ?)'; $query = '%' . clean_text($_GET['search'], 100) . '%'; $params = [$query, $query, $query, $query]; }
        if (!empty($_GET['status'])) { $where[] = 't.status = ?'; $params[] = $_GET['status']; }
        $condition = implode(' AND ', $where);
        paged("SELECT t.id, t.reference, t.status, t.createdAt AS created_at, COALESCE(MAX(b.guestName), 'Guest') AS guest, COALESCE(SUM(b.totalPrice), 0) AS amount, COUNT(b.id) AS booking_count FROM `Trip` t LEFT JOIN `Booking` b ON b.tripId = t.id WHERE $condition GROUP BY t.id, t.reference, t.status, t.createdAt ORDER BY t.createdAt DESC", $params, "SELECT COUNT(DISTINCT t.id) FROM `Trip` t LEFT JOIN `Booking` b ON b.tripId = t.id WHERE $condition", $params);
    }

    if ($resource === 'orders' && $id && $action === 'status' && in_array($method, ['POST', 'PATCH'], true)) {
        require_csrf(); $next = json_input()['status'] ?? '';
        $allowed = ['PENDING' => ['CONFIRMED', 'CANCELLED'], 'CONFIRMED' => ['COMPLETED', 'CANCELLED'], 'COMPLETED' => [], 'CANCELLED' => []];
        $stmt = db()->prepare('SELECT status FROM `Trip` WHERE id = ?'); $stmt->execute([$id]); $current = $stmt->fetchColumn();
        if (!$current) fail('Order not found', 404); if (!in_array($next, $allowed[$current] ?? [], true)) fail('Invalid order status transition', 409);
        db()->prepare("UPDATE `Trip` SET status = ?, confirmedAt = IF(? = 'CONFIRMED', UTC_TIMESTAMP(3), confirmedAt) WHERE id = ?")->execute([$next, $next, $id]);
        respond(['id' => $id, 'status' => $next]);
    }

    if ($resource === 'media' && $id && $method === 'POST') {
        require_csrf();
        $filename = media_filename($id);
        $directory = media_directory();
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) fail('Media file not found.', 404);
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) fail('Replacement file was not received.', 422);
        $file = $_FILES['file'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov'];
        $limit = str_starts_with($mime, 'video/') ? 100 * 1024 * 1024 : 10 * 1024 * 1024;
        if (!isset($allowed[$mime]) || (int) $file['size'] > $limit) fail('Replacement file type or size is not supported.', 422);
        $temporary = $path . '.replacement-' . bin2hex(random_bytes(6));
        if (!move_uploaded_file($file['tmp_name'], $temporary) || !rename($temporary, $path)) fail('Replacement file could not be stored.', 500);
        $metadata = media_metadata();
        $metadata[$filename]['display_name'] = $metadata[$filename]['display_name'] ?? $file['name'];
        $metadata[$filename]['updated_at'] = gmdate('c');
        save_media_metadata($metadata);
        audit('replace', 'media', $filename, ['mime' => $mime, 'size' => (int) $file['size']]);
        respond(['filename' => $filename, 'public_url' => rtrim($config['app']['uploads_url'] ?? 'storage/uploads', '/') . '/' . rawurlencode($filename), 'mime_type' => $mime, 'size_bytes' => (int) $file['size']]);
    }

    if ($resource === 'media' && $method === 'POST') {
        require_csrf();
        global $config;
        $directory = media_directory();
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            fail('Upload failed: the storage directory is not writable.', 500);
        }
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            fail('Upload failed: no file was received or the upload was interrupted.', 422);
        }
        $file = $_FILES['file'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/quicktime' => 'mov'];
        $isVideo = str_starts_with($mime, 'video/');
        $limit = $isVideo ? 100 * 1024 * 1024 : 10 * 1024 * 1024;
        if (!isset($allowed[$mime])) fail('Upload failed: only JPG, PNG, WebP, MP4, and MOV files are supported.', 422);
        if ((int) $file['size'] > $limit) fail('Upload failed: this file is larger than the allowed limit.', 422);
        $safeName = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
        if (!move_uploaded_file($file['tmp_name'], $directory . DIRECTORY_SEPARATOR . $safeName)) {
            fail('Upload failed: Hostinger could not write the file to storage.', 500);
        }
        $url = rtrim($config['app']['uploads_url'] ?? 'storage/uploads', '/') . '/' . rawurlencode($safeName);
        $metadata = media_metadata();
        $metadata[$safeName] = ['display_name' => $file['name'], 'alt_text' => '', 'updated_at' => gmdate('c')];
        save_media_metadata($metadata);
        audit('upload', 'media', null, ['filename' => $safeName, 'mime' => $mime, 'size' => (int) $file['size']]);
        respond(['filename' => $safeName, 'public_url' => $url, 'mime_type' => $mime, 'size_bytes' => (int) $file['size'], 'usage_count' => 0]);
    }

    if ($resource === 'media' && $id && $method === 'PATCH') {
        require_csrf();
        $filename = media_filename($id);
        $path = media_directory() . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) fail('Media file not found.', 404);
        $input = json_input();
        $metadata = media_metadata();
        $current = $metadata[$filename] ?? [];
        $displayName = clean_text($input['display_name'] ?? $current['display_name'] ?? $filename, 190);
        if ($displayName === '') $displayName = $filename;
        $metadata[$filename] = ['display_name' => $displayName, 'alt_text' => clean_text($input['alt_text'] ?? $current['alt_text'] ?? '', 255), 'updated_at' => gmdate('c')];
        save_media_metadata($metadata);
        audit('edit', 'media', $filename);
        respond(['filename' => $filename, 'display_name' => $displayName, 'alt_text' => $metadata[$filename]['alt_text']]);
    }

    if ($resource === 'media' && $id && $method === 'DELETE') {
        require_csrf();
        $filename = media_filename($id);
        $path = media_directory() . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) fail('Media file not found.', 404);
        if (!unlink($path)) fail('Media file could not be deleted.', 500);
        $metadata = media_metadata(); unset($metadata[$filename]); save_media_metadata($metadata);
        audit('delete', 'media', $filename);
        respond(['deleted' => true]);
    }

    if ($resource === 'media' && $method === 'GET') {
        global $config;
        $directory = media_directory();
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $files = []; $metadata = media_metadata();
        foreach (scandir($directory) ?: [] as $filename) {
            if ($filename === '.' || $filename === '..' || str_starts_with($filename, '.')) continue;
            $path = $directory . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($path)) continue;
            $mime = function_exists('mime_content_type') ? mime_content_type($path) : 'application/octet-stream';
            $files[] = ['filename' => $filename, 'display_name' => $metadata[$filename]['display_name'] ?? $filename, 'public_url' => rtrim($config['app']['uploads_url'] ?? 'storage/uploads', '/') . '/' . rawurlencode($filename), 'mime_type' => $mime, 'size_bytes' => filesize($path), 'alt_text' => $metadata[$filename]['alt_text'] ?? '', 'usage_count' => 0, 'created_at' => gmdate('Y-m-d H:i:s', filemtime($path))];
        }
        usort($files, static fn(array $left, array $right): int => strcmp($right['created_at'], $left['created_at']));
        respond($files);
    }

    if ($resource === 'settings' && $method === 'GET') {
        try {
            $settings = db()->query('SELECT `key`, typedValue AS typed_value, updatedAt AS updated_at FROM `AdminSetting` ORDER BY `key`')->fetchAll();
            respond($settings, 200, ['persistent' => true]);
        } catch (Throwable $error) {
            respond([
                ['key' => 'business_name', 'typed_value' => 'Pahadi Stay'],
                ['key' => 'commission_percent', 'typed_value' => '12'],
                ['key' => 'timezone', 'typed_value' => $config['app']['timezone'] ?? 'Asia/Kolkata'],
                ['key' => 'currency', 'typed_value' => 'INR'],
            ], 200, ['persistent' => false, 'message' => 'Import admin_migration.sql to enable persistent settings.']);
        }
    }

    if ($resource === 'settings' && in_array($method, ['POST', 'PATCH'], true)) {
        require_csrf();
        require_auth(['OWNER', 'ADMIN']);
        $input = json_input();
        try {
            $stmt = db()->prepare('INSERT INTO `AdminSetting` (`key`, typedValue, updatedBy) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE typedValue = VALUES(typedValue), updatedBy = VALUES(updatedBy), updatedAt = UTC_TIMESTAMP(3)');
            foreach ($input as $key => $value) {
                if (!preg_match('/^[a-z0-9_]+$/', $key)) continue;
                $stmt->execute([$key, is_scalar($value) ? (string) $value : json_encode($value), actor()['id'] ?? null]);
            }
            audit('edit', 'AdminSetting', null, ['keys' => array_keys($input)]);
            respond(['saved' => true]);
        } catch (Throwable $error) {
            fail('Settings were not saved. Import admin_migration.sql first.', 409);
        }
    }

    if ($resource === 'blog' && $method === 'DELETE' && $id) {
        require_csrf();
        require_auth(['OWNER', 'ADMIN', 'STAFF']);
        db()->prepare('DELETE FROM `BlogPost` WHERE id = ?')->execute([$id]);
        audit('delete', 'BlogPost', $id);
        respond(['deleted' => true]);
    }

    if ($resource === 'blog' && in_array($method, ['POST', 'PATCH'], true)) {
        require_csrf();
        require_auth(['OWNER', 'ADMIN', 'STAFF']);
        $input = json_input();
        $errors = [];
        foreach (['title', 'body'] as $field) if (trim((string) ($input[$field] ?? '')) === '') $errors[$field] = 'This field is required.';
        $status = strtoupper(trim((string) ($input['status'] ?? 'DRAFT')));
        if (!in_array($status, ['DRAFT', 'SCHEDULED', 'PUBLISHED', 'ARCHIVED'], true)) $errors['status'] = 'Invalid publication status.';
        $scheduledAt = !empty($input['scheduledAt']) ? str_replace('T', ' ', substr((string) $input['scheduledAt'], 0, 19)) : null;
        if ($status === 'SCHEDULED' && (!$scheduledAt || strtotime($scheduledAt) <= time())) $errors['scheduledAt'] = 'Scheduled publish time must be in the future.';
        if ($errors) fail('Blog post could not be saved.', 422, $errors);
        $slug = trim((string) ($input['slug'] ?? preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) $input['title']))), '-');
        $body = sanitize_html((string) $input['body']);
        $excerpt = sanitize_html((string) ($input['excerpt'] ?? ''));
        if (trim(strip_tags($body)) === '') fail('Blog post could not be saved.', 422, ['body' => 'Add meaningful post content before saving.']);
        $authorId = clean_text($input['authorId'] ?? actor()['id'] ?? '', 191);
        $authorStmt = db()->prepare("SELECT id, name FROM `User` WHERE id = ? AND role IN ('OWNER','ADMIN','STAFF') AND isActive = 1 LIMIT 1");
        $authorStmt->execute([$authorId]);
        $author = $authorStmt->fetch();
        if (!$author) fail('Blog post could not be saved.', 422, ['authorId' => 'Select an active owner, admin, or staff account.']);
        $authorName = $author['name'];
        $tags = is_array($input['tags'] ?? null) ? $input['tags'] : array_values(array_filter(array_map('trim', explode(',', (string) ($input['tags'] ?? '')))));
        $duplicate = db()->prepare('SELECT id FROM `BlogPost` WHERE slug = ? AND id <> ? LIMIT 1');
        $duplicate->execute([$slug, $id ?? '']);
        if ($duplicate->fetchColumn()) fail('Blog post could not be saved.', 409, ['slug' => 'This slug is already in use.']);
        $publishedAt = $status === 'PUBLISHED' ? gmdate('Y-m-d H:i:s.v') : null;
        if ($method === 'POST') {
            $newId = bin2hex(random_bytes(12));
            $stmt = db()->prepare('INSERT INTO `BlogPost` (id, slug, title, metaTitle, metaDescription, excerpt, body, authorName, category, primaryKeyword, tags, featuredImage, imageAltText, status, publishedAt, createdAt, updatedAt, authorId, imageUrls, scheduledAt) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3), ?, ?, ?)');
            $stmt->execute([$newId, $slug, clean_text($input['title'], 191), clean_text($input['metaTitle'] ?? $input['title'], 191), clean_text($input['metaDescription'] ?? '', 500), $excerpt, $body, $authorName, clean_text($input['category'] ?? '', 191), clean_text($input['primaryKeyword'] ?? '', 191), json_encode($tags), $input['featuredImage'] ?? null, clean_text($input['imageAltText'] ?? '', 191), $status, $publishedAt, $authorId, json_encode($input['imageUrls'] ?? []), $scheduledAt]);
            audit('create', 'BlogPost', $newId);
            respond(['id' => $newId]);
        }
        $stmt = db()->prepare('UPDATE `BlogPost` SET slug=?, title=?, metaTitle=?, metaDescription=?, excerpt=?, body=?, authorName=?, category=?, primaryKeyword=?, tags=?, featuredImage=?, imageAltText=?, status=?, publishedAt=?, updatedAt=UTC_TIMESTAMP(3), scheduledAt=? WHERE id=?');
        $stmt->execute([$slug, clean_text($input['title'], 191), clean_text($input['metaTitle'] ?? $input['title'], 191), clean_text($input['metaDescription'] ?? '', 500), $excerpt, $body, $authorName, clean_text($input['category'] ?? '', 191), clean_text($input['primaryKeyword'] ?? '', 191), json_encode($tags), $input['featuredImage'] ?? null, clean_text($input['imageAltText'] ?? '', 191), $status, $publishedAt, $scheduledAt, $id]);
        audit('edit', 'BlogPost', $id);
        respond(['id' => $id]);
    }

    $resources = [
        'customers' => ['table' => 'User', 'where' => "role = 'CUSTOMER'", 'search' => '(name LIKE ? OR email LIKE ? OR phone LIKE ?)'],
        'users' => ['table' => 'User', 'where' => "role IN ('OWNER','ADMIN','STAFF')", 'search' => '(name LIKE ? OR email LIKE ?)'],
        'partners' => ['table' => 'Partner', 'where' => '1 = 1', 'search' => 'businessName LIKE ?'],
        'vehicles' => ['table' => 'Vehicle', 'where' => '1 = 1', 'search' => '(type LIKE ? OR registrationNumber LIKE ? OR driverName LIKE ?)'],
        'pickups' => ['table' => 'PickupRequest', 'where' => '1 = 1', 'search' => '(pickupLocationText LIKE ? OR dropoffLocationText LIKE ?)'],
        'packages' => ['table' => 'Package', 'where' => '1 = 1', 'search' => 'title LIKE ?'],
        'blog' => ['table' => 'BlogPost', 'where' => '1 = 1', 'search' => '(title LIKE ? OR slug LIKE ? OR authorName LIKE ?)'],
    ];
    if (isset($resources[$resource])) {
        $definition = $resources[$resource];
        if ($method === 'GET') {
            $where = [$definition['where']]; $params = [];
            if (!empty($_GET['search'])) { $search = '%' . clean_text($_GET['search'], 100) . '%'; $where[] = $definition['search']; $params = array_fill(0, substr_count($definition['search'], '?'), $search); }
            $condition = implode(' AND ', $where); $table = '`' . $definition['table'] . '`';
            paged("SELECT * FROM $table WHERE $condition ORDER BY createdAt DESC", $params, "SELECT COUNT(*) FROM $table WHERE $condition", $params);
        }
        fail('This resource is read-only until its edit contract is enabled', 405);
    }

    fail('Endpoint not found', 404);
} catch (PDOException $error) {
    error_log($error->getMessage()); fail('A database operation failed', 500);
} catch (Throwable $error) {
    error_log($error->getMessage()); fail('Unexpected server error', 500);
}
