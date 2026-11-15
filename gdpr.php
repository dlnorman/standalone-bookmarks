<?php
declare(strict_types=1);
require_once __DIR__ . '/integration/privacy-lib/GdprRouter.php';
$hostConfig = require __DIR__.'/config.php';
define('FORCE_SESSION_START', true);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/includes/csrf.php';
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function respond(mixed $value, int $status = 200): never {
    http_response_code($status); header('Content-Type: application/json');
    echo json_encode($value, JSON_THROW_ON_ERROR); exit;
}
function invalid(string $message): never { respond(['error' => $message], 400); }
function validateRequest(string $policy, stdClass $body, bool $writeConsent): array {
    $schemas = [
        'consent' => [], 'lawful' => [], 'information' => [], 'access' => [],
        'rectification' => ['request_id', 'updates'],
        'erasure' => ['request_id', 'reason', 'fields'],
        'restriction' => ['request_id', 'reason', 'fields'],
        'portability' => ['request_id', 'transfer_requested', 'transfer_service_id'],
        'objection' => ['request_id', 'reason', 'fields'],
        'complaint' => ['request_id', 'authority_id', 'description'],
        'breach' => ['incident_id', 'detected', 'hours_since_detected', 'risks', 'summary'],
    ];
    if (!array_key_exists($policy, $schemas)) invalid('Unknown GDPR operation');
    $allowed = $writeConsent ? ['value', 'withdrawn'] : $schemas[$policy];
    $value = get_object_vars($body);
    foreach ($value as $key => $item) {
        if (!in_array($key, $allowed, true)) invalid('Unknown request field: ' . $key);
        if (in_array($key, ['withdrawn', 'detected', 'transfer_requested'], true)) {
            if (!is_bool($item)) invalid($key . ' must be boolean');
        } elseif ($key === 'hours_since_detected') {
            if ((!is_int($item) && !is_float($item)) || $item < 0) invalid('Invalid incident time');
        } elseif ($key === 'fields' || $key === 'risks') {
            $choices = $key === 'fields' ? ['username', 'display_name'] : ['rights_freedoms', 'identity_theft', 'discrimination', 'financial_loss', 'reputational_damage', 'minor'];
            if (!is_array($item) || !$item || count($item) > count($choices)) invalid('Invalid ' . $key);
            foreach ($item as $field) if (!is_string($field) || !in_array($field, $choices, true)) invalid('Invalid ' . $key);
            if (count(array_unique($item)) !== count($item)) invalid('Duplicate ' . $key);
        } elseif ($key === 'updates') {
            if (!$item instanceof stdClass || !get_object_vars($item)) invalid('Updates must be a nonempty object');
            foreach (get_object_vars($item) as $field => $updated) {
                if (!in_array($field, ['username', 'display_name'], true) || !is_string($updated) || $updated === '' || strlen($updated) > 200) invalid('Invalid update');
                if ($field === 'unused_email' && !filter_var($updated, FILTER_VALIDATE_EMAIL)) invalid('Invalid email');
            }
        } else {
            $limit = $key === 'description' ? 2000 : ($key === 'summary' ? 2000 : 128);
            if (!is_string($item) || $item === '' || strlen($item) > $limit) invalid('Invalid ' . $key);
        }
    }
    foreach ($writeConsent ? ['value', 'withdrawn'] : match ($policy) {
        'rectification' => ['updates'], 'complaint' => ['authority_id', 'description'], 'breach' => ['incident_id'], default => [],
    } as $required) if (!array_key_exists($required, $value)) invalid('Missing ' . $required);
    if ($writeConsent && !in_array($value['value'], ['yes', 'no'], true)) invalid('Invalid consent value');
    return $value;
}
try {
    start_secure_session();
    if (!is_logged_in() || !isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) respond(['error' => 'Please sign in'], 401);
    $db = new PDO('sqlite:'.$hostConfig['db_path']);
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys=ON');
    $query = $db->prepare('SELECT id, username, display_name, role FROM users WHERE id = ?');
    $query->execute([(int) $_SESSION['user_id']]); $user = $query->fetch(PDO::FETCH_ASSOC);
    if (!$user) respond(['error' => 'Please sign in'], 401);
    $userId = (string) $user['id'];
    $csrf = csrf_get_token();
    $policy = $_GET['policy'] ?? null; $method = $_SERVER['REQUEST_METHOD'];
    if ($policy === null && $method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        require __DIR__ . '/integration/privacy-page.php'; exit;
    }
    if ($policy === 'csrf' && $method === 'GET') respond(['token' => $csrf]);
    if (!in_array($method, ['POST', 'PATCH'], true) || ($method === 'PATCH' && $policy !== 'consent')) respond(['error' => 'Method not allowed'], 405);
    if (!is_string($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null) || !csrf_validate_token($_SERVER['HTTP_X_CSRF_TOKEN'])) respond(['error' => 'Invalid or missing CSRF token'], 403);
    $canAssessBreach = is_admin() && $user['role'] === 'admin';
    session_write_close();
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') respond(['error' => 'JSON content type required'], 415);
    $raw = file_get_contents('php://input', false, null, 0, 16385);
    if (strlen($raw) > 16384) respond(['error' => 'Request too large'], 413);
    try { $body = json_decode($raw, false, 32, JSON_THROW_ON_ERROR); } catch (JsonException) { invalid('Invalid JSON'); }
    if (!$body instanceof stdClass) invalid('JSON object required');
    if (!is_string($policy)) invalid('Unknown GDPR operation');
    $writeConsent = $method === 'PATCH'; $value = validateRequest($policy, $body, $writeConsent);
    if ($policy === 'breach') {
        if (!$canAssessBreach) respond(['error' => 'Administrator access required'], 403);
    }
    if ($writeConsent) {
        $q = $db->prepare('INSERT INTO gdpr_consents(user_id,value,withdrawn) VALUES(?,?,?) ON CONFLICT(user_id) DO UPDATE SET value=excluded.value,withdrawn=excluded.withdrawn');
        $q->execute([(int) $userId, $value['value'], (int) $value['withdrawn']]); respond($value);
    }
    $q = $db->prepare('SELECT value, withdrawn FROM gdpr_consents WHERE user_id = ?');
    $q->execute([(int) $userId]); $consent = $q->fetch(PDO::FETCH_ASSOC);
    $config = json_decode(file_get_contents(__DIR__ . '/integration/privacy-config.json'), true, 512, JSON_THROW_ON_ERROR);
    $input = array_intersect_key($config, array_flip(['controller', 'dpo', 'storage', 'lawful_purposes', 'third_parties', 'services', 'exceptions', 'supervisory_authorities']));
    $input['consents'] = $consent ? (object) [$userId => ['value' => $consent['value'], 'withdrawn' => (bool) $consent['withdrawn']]] : new stdClass();
    $input['users'] = [['id' => $userId, 'data_items' => array_map(fn($field) => ['field' => $field, 'value' => $user[$field], 'purpose' => 'contract', 'source' => 'user', 'purpose_presented' => true], ['username', 'display_name'])]];
    $input['request'] = new stdClass();
    if ($policy === 'access') $input['request']->access = ['user_id' => $userId];
    if (in_array($policy, ['rectification', 'erasure', 'restriction', 'portability', 'objection', 'complaint'], true)) {
        $input['request']->$policy = array_merge($config['request_defaults'][$policy] ?? [], $policy === 'portability' ? ['transfer_requested' => false, 'transfer_service_id' => ''] : [], $value, ['request_id' => $value['request_id'] ?? bin2hex(random_bytes(16)), 'user_id' => $userId, 'target_user_id' => $userId]);
    }
    if ($policy === 'breach') $input['breach'] = array_merge($config['breach_defaults'], $value, ['affected_users' => [$userId]]);
    $router = new GdprRouter();
    try { $result = $router->route($policy, json_decode(json_encode($input, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR), $userId); }
    catch (Throwable) { respond(['error' => 'OPA evaluation failed'], 502); }
    respond($result);
} catch (Throwable $error) {
    error_log('PrivacyLib adapter: ' . $error->getMessage()); respond(['error' => 'Privacy service unavailable'], 500);
}
