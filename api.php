<?php
// ── Database ──────────────────────────────────────────────────────────────────
// Single shared PDO connection. The rest of this file calls db() everywhere,
// so it must exist as a function (it previously did not).
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $host = getenv('DB_HOST') ?: 'db';
    $name = getenv('DB_NAME') ?: 'phcapp';
    $user = getenv('DB_USER') ?: 'appuser';
    $pass = getenv('DB_PASS') ?: 'apppass';
    $port = getenv('DB_PORT') ?: '3306';

    try {
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                // Required: code reads rows as $r['col']. Without this, PDO also
                // returns duplicate numeric keys, which corrupts JSON responses.
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        exit(json_encode(['error' => 'Database connection failed']));
    }
    return $pdo;
}

// ── Schema ────────────────────────────────────────────────────────────────────
function initSchema(): void {
    $db = db();
    foreach ([
        "CREATE TABLE IF NOT EXISTS users (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            username      VARCHAR(100) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS phcs (
            id             VARCHAR(50)  PRIMARY KEY,
            name           VARCHAR(500) NOT NULL,
            mandal         VARCHAR(500) NOT NULL DEFAULT '',
            location       VARCHAR(500) NOT NULL DEFAULT '',
            upgraded_on    VARCHAR(20)  NOT NULL DEFAULT '',
            notes          TEXT,
            overall_impact MEDIUMTEXT,
            created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS monthly_data (
            phc_id     VARCHAR(50) NOT NULL,
            month_key  VARCHAR(7)  NOT NULL,
            data_json  LONGTEXT    NOT NULL,
            updated_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (phc_id, month_key),
            FOREIGN KEY (phc_id) REFERENCES phcs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS impact_case_stories (
            id         VARCHAR(50)   PRIMARY KEY,
            phc_id     VARCHAR(50)   NOT NULL,
            title      VARCHAR(1000) NOT NULL DEFAULT '',
            `date`     VARCHAR(20)   NOT NULL DEFAULT '',
            content    MEDIUMTEXT,
            images_json LONGTEXT,
            sort_order INT           NOT NULL DEFAULT 0,
            FOREIGN KEY (phc_id) REFERENCES phcs(id) ON DELETE CASCADE,
            INDEX idx_phc (phc_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS impact_videos (
            id          VARCHAR(50)   PRIMARY KEY,
            phc_id      VARCHAR(50)   NOT NULL,
            title       VARCHAR(1000) NOT NULL DEFAULT '',
            url         TEXT,
            video_data  LONGTEXT,
            description TEXT,
            sort_order  INT           NOT NULL DEFAULT 0,
            FOREIGN KEY (phc_id) REFERENCES phcs(id) ON DELETE CASCADE,
            INDEX idx_phc (phc_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS impact_dignitary_visits (
            id         VARCHAR(50)   PRIMARY KEY,
            phc_id     VARCHAR(50)   NOT NULL,
            dignitary  VARCHAR(1000) NOT NULL DEFAULT '',
            `date`     VARCHAR(20)   NOT NULL DEFAULT '',
            purpose    TEXT,
            photo      LONGTEXT,
            photos_json LONGTEXT,
            sort_order INT           NOT NULL DEFAULT 0,
            FOREIGN KEY (phc_id) REFERENCES phcs(id) ON DELETE CASCADE,
            INDEX idx_phc (phc_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS impact_pictures (
            id         VARCHAR(50)   PRIMARY KEY,
            phc_id     VARCHAR(50)   NOT NULL,
            caption    VARCHAR(1000) NOT NULL DEFAULT '',
            data_url   LONGTEXT,
            sort_order INT           NOT NULL DEFAULT 0,
            FOREIGN KEY (phc_id) REFERENCES phcs(id) ON DELETE CASCADE,
            INDEX idx_phc (phc_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS custom_pilots (
            id         VARCHAR(80)   PRIMARY KEY,
            label      VARCHAR(1000) NOT NULL,
            fields_json LONGTEXT     NOT NULL,
            sort_order INT           NOT NULL DEFAULT 0,
            created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
    ] as $sql) {
        $db->exec($sql);
    }
    // Add indexes — silently ignore if they already exist
    try { $db->exec('ALTER TABLE phcs ADD INDEX idx_name (name)'); } catch (Throwable $e) {}
    try { $db->exec('ALTER TABLE monthly_data ADD INDEX idx_month_key (month_key)'); } catch (Throwable $e) {}
    // Add columns — silently ignore if they already exist
    try { $db->exec('ALTER TABLE impact_case_stories ADD COLUMN images_json LONGTEXT NULL'); } catch (Throwable $e) {}
    try { $db->exec('ALTER TABLE impact_dignitary_visits ADD COLUMN photos_json LONGTEXT NULL'); } catch (Throwable $e) {}
    try { $db->exec('ALTER TABLE impact_videos ADD COLUMN video_data LONGTEXT NULL'); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin'"); } catch (Throwable $e) {}
    // FIX #4: Expand password_hash column to support bcrypt hashes (255 chars)
    try { $db->exec("ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NOT NULL"); } catch (Throwable $e) {}

    $seedUser = getenv('APP_ADMIN_USER') ?: 'admin';
    $seedPass = getenv('APP_ADMIN_PASS') ?: 'admin@123';
    $st = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    $st->execute([$seedUser]);
    if ((int)$st->fetchColumn() === 0) {
        $db->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')
           ->execute([$seedUser, $seedPass, 'admin']);
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function generateToken(): string { return bin2hex(random_bytes(32)); }

function respond($data, int $code = 200): void {
    http_response_code($code);
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    echo ($json !== false) ? $json : '{"error":"Response encoding failed"}';
    exit;
}

function getToken(): ?string {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    // Token is fed straight into session_id(), so only allow safe characters.
    return preg_match('/^Bearer\s+([A-Za-z0-9,-]{16,128})$/i', $auth, $m) ? $m[1] : null;
}

function requireAdmin(): string {
    $tok = getToken();
    if (!$tok) respond(['error' => 'Unauthorized'], 401);
    session_id($tok);
    @session_start();
    $username = $_SESSION['username'] ?? null;
    $role = $_SESSION['role'] ?? 'admin';
    if (!$username || $role !== 'admin') respond(['error' => 'Unauthorized'], 401);
    return $username;
}

function requireAnyUser(): string {
    $tok = getToken();
    if (!$tok) respond(['error' => 'Unauthorized'], 401);
    session_id($tok);
    @session_start();
    $username = $_SESSION['username'] ?? null;
    if (!$username) respond(['error' => 'Unauthorized'], 401);
    return $username;
}

function coalesce($v) { return $v ?? ''; }

function numOrZero($v): int {
    if ($v === '' || $v === null) return 0;
    return is_numeric($v) ? (int)$v : 0;
}

function ynOrBlank($v): string {
    if ($v === null) return '';
    $s = strtolower(trim((string)$v));
    if ($s === 'yes') return 'Yes';
    if ($s === 'no') return 'No';
    return '';
}

function normalizeMonthlyRecord($data): array {
    $d = is_array($data) ? $data : [];
    return [
        'opd' => ['value' => numOrZero($d['opd']['value'] ?? 0)],
        'ipd' => ['value' => numOrZero($d['ipd']['value'] ?? 0)],
        'deliveries' => ['value' => numOrZero($d['deliveries']['value'] ?? 0)],
        'labTests' => ['value' => numOrZero($d['labTests']['value'] ?? 0)],
        'oral' => [
            'screened' => numOrZero($d['oral']['screened'] ?? 0),
            'positive' => numOrZero($d['oral']['positive'] ?? 0),
            'referred' => numOrZero($d['oral']['referred'] ?? ($d['referral']['referred'] ?? 0)),
            'treatmentInitiated' => numOrZero($d['oral']['treatmentInitiated'] ?? ($d['referral']['treatmentInitiated'] ?? 0)),
            'notApplicable' => (bool)($d['oral']['notApplicable'] ?? false),
        ],
        'breast' => [
            'screened' => numOrZero($d['breast']['screened'] ?? 0),
            'positive' => numOrZero($d['breast']['positive'] ?? 0),
            'referred' => numOrZero($d['breast']['referred'] ?? ($d['referral']['referred'] ?? 0)),
            'treatmentInitiated' => numOrZero($d['breast']['treatmentInitiated'] ?? ($d['referral']['treatmentInitiated'] ?? 0)),
            'notApplicable' => (bool)($d['breast']['notApplicable'] ?? false),
        ],
        'cervical' => [
            'screened' => numOrZero($d['cervical']['screened'] ?? 0),
            'positive' => numOrZero($d['cervical']['positive'] ?? 0),
            'referred' => numOrZero($d['cervical']['referred'] ?? ($d['referral']['referred'] ?? 0)),
            'treatmentInitiated' => numOrZero($d['cervical']['treatmentInitiated'] ?? ($d['referral']['treatmentInitiated'] ?? 0)),
            'notApplicable' => (bool)($d['cervical']['notApplicable'] ?? false),
        ],
        'training' => [
            'count' => numOrZero($d['training']['count'] ?? (
                numOrZero($d['training']['bemonc'] ?? 0) +
                numOrZero($d['training']['ipc'] ?? 0) +
                numOrZero($d['training']['bls'] ?? 0) +
                numOrZero($d['training']['genEmerg'] ?? 0)
            )),
        ],
        'drugIndent' => ['status' => ynOrBlank($d['drugIndent']['status'] ?? '')],
        'hr' => [
            'moSanc' => numOrZero($d['hr']['moSanc'] ?? 0),
            'moFilled' => numOrZero($d['hr']['moFilled'] ?? 0),
            'snSanc' => numOrZero($d['hr']['snSanc'] ?? 0),
            'snFilled' => numOrZero($d['hr']['snFilled'] ?? 0),
            'ltSanc' => numOrZero($d['hr']['ltSanc'] ?? 0),
            'ltFilled' => numOrZero($d['hr']['ltFilled'] ?? 0),
            'poSanc' => numOrZero($d['hr']['poSanc'] ?? 0),
            'poFilled' => numOrZero($d['hr']['poFilled'] ?? 0),
        ],
        'equipment' => [
            'computers' => numOrZero($d['equipment']['computers'] ?? 0),
            'deliveryBed' => numOrZero($d['equipment']['deliveryBed'] ?? 0),
            'lamp' => numOrZero($d['equipment']['lamp'] ?? 0),
            'paraMonitor' => numOrZero($d['equipment']['paraMonitor'] ?? 0),
            'coag' => numOrZero($d['equipment']['coag'] ?? ($d['equipment']['bp'] ?? 0)),
            'doppler' => numOrZero($d['equipment']['doppler'] ?? 0),
        ],
        'bmw' => ['status' => ynOrBlank($d['bmw']['status'] ?? '')],
    ];
}

function getCustomPilots(): array {
    $db = db();
    $rows = $db->query('SELECT id,label,fields_json FROM custom_pilots ORDER BY sort_order, created_at')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $fields = json_decode($r['fields_json'] ?? '[]', true);
        if (!is_array($fields) || count($fields) === 0) {
            $fields = [
                ['k' => 'screened', 'l' => 'Cases Screened'],
                ['k' => 'positive', 'l' => 'Positive Cases'],
                ['k' => 'referred', 'l' => 'Referred to Higher Facility'],
                ['k' => 'treatmentInitiated', 'l' => 'Treatment Initiated'],
            ];
        }
        $out[] = ['id' => $r['id'], 'label' => $r['label'], 'custom' => true, 'pilot' => true, 'fields' => $fields];
    }
    return $out;
}

const IMPACT_MAP = [
    'caseStories'     => ['table' => 'impact_case_stories',     'cols' => ['title', 'date', 'content', 'images_json'],              'jsMap' => ['images_json' => 'images']],
    'videos'          => ['table' => 'impact_videos',           'cols' => ['title', 'url', 'video_data', 'description'],           'jsMap' => ['video_data' => 'videoData']],
    'dignitaryVisits' => ['table' => 'impact_dignitary_visits', 'cols' => ['dignitary', 'date', 'purpose', 'photo', 'photos_json'], 'jsMap' => ['photos_json' => 'photos']],
    'pictures'        => ['table' => 'impact_pictures',         'cols' => ['caption', 'data_url'],                   'jsMap' => ['data_url' => 'dataUrl']],
];

// Used only for single-PHC responses (POST/PUT mutations).
function getImpact(string $phcId): array {
    $db = db();
    $q  = fn($sql) => (function() use ($db, $sql, $phcId) {
        $st = $db->prepare($sql); $st->execute([$phcId]); return $st->fetchAll();
    })();
    $c = fn($v) => $v ?? '';
    return [
        'caseStories'     => array_map(function($r) use ($c){ $imgs=json_decode($r['images_json'] ?? '[]', true); if(!is_array($imgs)) $imgs=[]; return ['id'=>$r['id'],'title'=>$c($r['title']),'date'=>$c($r['date']),'content'=>$c($r['content']),'images'=>$imgs]; }, $q('SELECT id,title,`date`,content,images_json FROM impact_case_stories WHERE phc_id=? ORDER BY sort_order')),
        'videos'          => array_map(fn($r) => ['id'=>$r['id'],'title'=>$c($r['title']),'url'=>$c($r['url']),'videoData'=>$c($r['video_data']),'description'=>$c($r['description'])], $q('SELECT id,title,url,video_data,description FROM impact_videos WHERE phc_id=? ORDER BY sort_order')),
        'dignitaryVisits' => array_map(function($r) use ($c){ $photos=json_decode($r['photos_json'] ?? '[]', true); if(!is_array($photos)) $photos=[]; if(!$photos && !empty($r['photo'])) $photos=[$r['photo']]; return ['id'=>$r['id'],'dignitary'=>$c($r['dignitary']),'date'=>$c($r['date']),'purpose'=>$c($r['purpose']),'photo'=>$c($r['photo']),'photos'=>$photos]; }, $q('SELECT id,dignitary,`date`,purpose,photo,photos_json FROM impact_dignitary_visits WHERE phc_id=? ORDER BY sort_order')),
        // FIX #3: Exclude data_url from bulk load — fetch via /api/phcs/:id/pictures
        'pictures'        => array_map(fn($r) => ['id'=>$r['id'],'caption'=>$c($r['caption']),'dataUrl'=>''], $q('SELECT id,caption FROM impact_pictures WHERE phc_id=? ORDER BY sort_order')),
    ];
}

// FIX #3: Bulk-load all PHCs — pictures caption only (no data_url to keep response small)
function buildAllPHCs(): array {
    $db   = db();
    $phcs = $db->query('SELECT id,name,mandal,location,upgraded_on,notes,overall_impact FROM phcs ORDER BY name')->fetchAll();
    if (!$phcs) return [];

    $ids = array_column($phcs, 'id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $c   = fn($v) => $v ?? '';

    // Monthly data
    $st = $db->prepare("SELECT phc_id,month_key,data_json FROM monthly_data WHERE phc_id IN ($in) ORDER BY month_key");
    $st->execute($ids);
    $monthly = [];
    foreach ($st->fetchAll() as $r)
        $monthly[$r['phc_id']][$r['month_key']] = normalizeMonthlyRecord(json_decode($r['data_json'], true) ?? []);

    // Case stories
    $st = $db->prepare("SELECT phc_id,id,title,`date`,content,images_json FROM impact_case_stories WHERE phc_id IN ($in) ORDER BY sort_order");
    $st->execute($ids);
    $stories = [];
    foreach ($st->fetchAll() as $r) {
        $imgs = json_decode($r['images_json'] ?? '[]', true);
        if (!is_array($imgs)) $imgs = [];
        $stories[$r['phc_id']][] = ['id'=>$r['id'],'title'=>$c($r['title']),'date'=>$c($r['date']),'content'=>$c($r['content']),'images'=>$imgs];
    }

    // Videos
    $st = $db->prepare("SELECT phc_id,id,title,url,video_data,description FROM impact_videos WHERE phc_id IN ($in) ORDER BY sort_order");
    $st->execute($ids);
    $videos = [];
    foreach ($st->fetchAll() as $r)
        $videos[$r['phc_id']][] = ['id'=>$r['id'],'title'=>$c($r['title']),'url'=>$c($r['url']),'videoData'=>$c($r['video_data']),'description'=>$c($r['description'])];

    // Dignitary visits
    $st = $db->prepare("SELECT phc_id,id,dignitary,`date`,purpose,photo,photos_json FROM impact_dignitary_visits WHERE phc_id IN ($in) ORDER BY sort_order");
    $st->execute($ids);
    $dignitary = [];
    foreach ($st->fetchAll() as $r) {
        $photos = json_decode($r['photos_json'] ?? '[]', true);
        if (!is_array($photos)) $photos = [];
        if (!$photos && !empty($r['photo'])) $photos = [$r['photo']];
        $dignitary[$r['phc_id']][] = ['id'=>$r['id'],'dignitary'=>$c($r['dignitary']),'date'=>$c($r['date']),'purpose'=>$c($r['purpose']),'photo'=>$c($r['photo']),'photos'=>$photos];
    }

    // FIX #3: Pictures — caption only, NO data_url (saves massive bandwidth)
    $st = $db->prepare("SELECT phc_id,id,caption FROM impact_pictures WHERE phc_id IN ($in) ORDER BY sort_order");
    $st->execute($ids);
    $pictures = [];
    foreach ($st->fetchAll() as $r)
        $pictures[$r['phc_id']][] = ['id'=>$r['id'],'caption'=>$c($r['caption']),'dataUrl'=>''];

    return array_map(function($row) use ($monthly, $stories, $videos, $dignitary, $pictures, $c) {
        $id = $row['id'];
        return [
            'id'          => $id,
            'name'        => $c($row['name']),
            'mandal'      => $c($row['mandal']),
            'location'    => $c($row['location']),
            'upgradedOn'  => $c($row['upgraded_on']),
            'notes'       => $c($row['notes']),
            'monthlyData' => $monthly[$id] ?? [],
            'impact'      => [
                'overallImpact'   => $c($row['overall_impact']),
                'caseStories'     => $stories[$id]   ?? [],
                'videos'          => $videos[$id]    ?? [],
                'dignitaryVisits' => $dignitary[$id] ?? [],
                'pictures'        => $pictures[$id]  ?? [],
            ],
        ];
    }, $phcs);
}

function buildPHC(array $row): array {
    $db = db();
    $st = $db->prepare('SELECT month_key, data_json FROM monthly_data WHERE phc_id=? ORDER BY month_key');
    $st->execute([$row['id']]);
    $monthly = [];
    foreach ($st->fetchAll() as $r) $monthly[$r['month_key']] = normalizeMonthlyRecord(json_decode($r['data_json'], true) ?? []);
    $impact = getImpact($row['id']);
    $c = fn($v) => $v ?? '';
    return [
        'id'          => $row['id'],
        'name'        => $c($row['name']),
        'mandal'      => $c($row['mandal']),
        'location'    => $c($row['location']),
        'upgradedOn'  => $c($row['upgraded_on']),
        'notes'       => $c($row['notes']),
        'monthlyData' => $monthly,
        'impact'      => array_merge($impact, ['overallImpact' => $c($row['overall_impact'])]),
    ];
}

// ── Boot ──────────────────────────────────────────────────────────────────────
try { initSchema(); } catch (Throwable $e) { respond(['error' => 'DB init failed: ' . $e->getMessage()], 500); }

$method = $_SERVER['REQUEST_METHOD'];

// FIX #1: Accept both ?p= and ?_p= for routing
$uriRaw = $_GET['_p'] ?? $_GET['p'] ?? null;
if ($uriRaw === null) {
    $uriRaw = parse_url($_SERVER['REDIRECT_URL'] ?? $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
}
$uri  = trim($uriRaw, '/');
$segs = explode('/', $uri);
$body = (array)(json_decode(file_get_contents('php://input'), true) ?? []);
$db   = db();

// ── Routes ────────────────────────────────────────────────────────────────────
try {

    // GET /reset-admin  (remove after first use)
    if ($uri === 'reset-admin' && $method === 'GET') {
        $db->prepare('DELETE FROM users WHERE username = ?')->execute(['admin']);
        $db->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')->execute(['admin', 'admin@123', 'admin']);
        header('Content-Type: text/plain');
        echo 'Admin reset. Login with admin / admin@123 — then remove this route from api.php';
        exit;
    }

    // GET /api.php?_p=create-user&u=USERNAME&pw=PASSWORD  (remove after use)
    if ($uri === 'create-user' && $method === 'GET') {
        $u  = $_GET['u']  ?? '';
        $pw = $_GET['pw'] ?? '';
        if (!$u || !$pw) { header('Content-Type: text/plain'); echo 'Pass ?u=username&pw=password'; exit; }
        $db->prepare('DELETE FROM users WHERE username = ?')->execute([$u]);
        $db->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')->execute([$u, $pw, 'admin']);
        header('Content-Type: text/plain');
        echo "User '$u' created. Login with $u / $pw — then remove create-user route from api.php";
        exit;
    }

    $route = $segs[1] ?? '';
    $n     = count($segs);

    // POST /api/login
    if ($route === 'login' && $method === 'POST') {
        ['username' => $u, 'password' => $p] = $body + ['username' => '', 'password' => ''];
        if (!$u || !$p) respond(['error' => 'Username and password required'], 400);
        $st = $db->prepare('SELECT * FROM users WHERE username = ?');
        $st->execute([$u]);
        $user = $st->fetch();
        if (!$user || $user['password_hash'] !== $p) respond(['error' => 'Invalid credentials'], 401);
        $token = generateToken();
        session_id($token);
        session_start();
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'] ?? 'admin';
        session_write_close();
        respond(['token' => $token, 'username' => $user['username'], 'role' => $user['role'] ?? 'admin']);
    }

    // POST /api/logout
    if ($route === 'logout' && $method === 'POST') {
        $tok = getToken();
        if ($tok) { session_id($tok); @session_start(); session_destroy(); }
        respond(['ok' => true]);
    }

    // GET /api/phcs
    if ($route === 'phcs' && $method === 'GET' && $n === 2) {
        header('Cache-Control: public, max-age=60, stale-while-revalidate=120');
        respond(buildAllPHCs());
    }

    // FIX #3: GET /api/phcs/:id/pictures — lazy-load full picture data per PHC
    if ($route === 'phcs' && $method === 'GET' && $n === 3) {
        // Single PHC picture fetch (full data_url)
        $phcId = $segs[2];
        // Check if it's a pictures sub-route — handled below at n===4
        // This route returns a lightweight single-PHC summary (no pictures data_url)
        $st = $db->prepare('SELECT * FROM phcs WHERE id=?');
        $st->execute([$phcId]);
        $row = $st->fetch();
        if (!$row) respond(['error' => 'PHC not found'], 404);
        respond(buildPHC($row));
    }

    // FIX #3: GET /api/phcs/:id/pictures — returns full data_url for one PHC
    if ($route === 'phcs' && $method === 'GET' && $n === 4 && $segs[3] === 'pictures') {
        header('Cache-Control: public, max-age=300, stale-while-revalidate=600');
        $st = $db->prepare('SELECT id,caption,data_url FROM impact_pictures WHERE phc_id=? ORDER BY sort_order');
        $st->execute([$segs[2]]);
        respond($st->fetchAll());
    }

    // GET /api/pilots
    if ($route === 'pilots' && $method === 'GET' && $n === 2) {
        header('Cache-Control: public, max-age=60, stale-while-revalidate=120');
        respond(getCustomPilots());
    }

    // POST /api/pilots
    if ($route === 'pilots' && $method === 'POST' && $n === 2) {
        requireAnyUser();
        $id = $body['id'] ?? '';
        $label = trim((string)($body['label'] ?? ''));
        $fields = $body['fields'] ?? [];
        if (!$id || !$label || !is_array($fields)) respond(['error' => 'id, label, fields required'], 400);
        $st = $db->query('SELECT COALESCE(MAX(sort_order),0) FROM custom_pilots');
        $ord = (int)$st->fetchColumn() + 1;
        $db->prepare('REPLACE INTO custom_pilots (id,label,fields_json,sort_order) VALUES (?,?,?,?)')->execute([$id,$label,json_encode($fields),$ord]);
        respond(['ok' => true], 201);
    }

    // PUT /api/pilots/:id
    if ($route === 'pilots' && $method === 'PUT' && $n === 3) {
        requireAnyUser();
        $id = $segs[2];
        $label = trim((string)($body['label'] ?? ''));
        $fields = $body['fields'] ?? [];
        if (!$label || !is_array($fields)) respond(['error' => 'label and fields required'], 400);
        $db->prepare('UPDATE custom_pilots SET label=?, fields_json=? WHERE id=?')->execute([$label, json_encode($fields), $id]);
        respond(['ok' => true]);
    }

    // DELETE /api/pilots/:id
    if ($route === 'pilots' && $method === 'DELETE' && $n === 3) {
        requireAdmin();
        $db->prepare('DELETE FROM custom_pilots WHERE id=?')->execute([$segs[2]]);
        respond(['ok' => true]);
    }

    // POST /api/phcs
    if ($route === 'phcs' && $method === 'POST' && $n === 2) {
        requireAnyUser();
        ['id' => $id, 'name' => $name] = $body + ['id' => '', 'name' => ''];
        if (!$id || !$name) respond(['error' => 'id and name required'], 400);
        $db->prepare('INSERT INTO phcs (id,name,mandal,location,upgraded_on,notes) VALUES (?,?,?,?,?,?)')->execute([
            $id, $name, $body['mandal'] ?? '', $body['location'] ?? '', $body['upgradedOn'] ?? '', $body['notes'] ?? ''
        ]);
        $st = $db->prepare('SELECT * FROM phcs WHERE id=?'); $st->execute([$id]);
        respond(buildPHC($st->fetch()), 201);
    }

    // PUT /api/phcs/:id
    if ($route === 'phcs' && $method === 'PUT' && $n === 3) {
        requireAnyUser();
        $id = $segs[2]; $name = $body['name'] ?? '';
        if (!$name) respond(['error' => 'name required'], 400);
        $db->prepare('UPDATE phcs SET name=?,mandal=?,location=?,upgraded_on=?,notes=? WHERE id=?')->execute([
            $name, $body['mandal'] ?? '', $body['location'] ?? '', $body['upgradedOn'] ?? '', $body['notes'] ?? '', $id
        ]);
        $st = $db->prepare('SELECT * FROM phcs WHERE id=?'); $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) respond(['error' => 'PHC not found'], 404);
        respond(buildPHC($row));
    }

    // DELETE /api/phcs/:id
    if ($route === 'phcs' && $method === 'DELETE' && $n === 3) {
        requireAdmin();
        $db->prepare('DELETE FROM phcs WHERE id=?')->execute([$segs[2]]);
        respond(['ok' => true]);
    }

    // PUT /api/phcs/:id/monthly/:month
    if ($route === 'phcs' && $method === 'PUT' && $n === 5 && $segs[3] === 'monthly') {
        requireAnyUser();
        $normalized = normalizeMonthlyRecord($body);
        $db->prepare('INSERT INTO monthly_data (phc_id,month_key,data_json) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data_json=VALUES(data_json),updated_at=CURRENT_TIMESTAMP')
           ->execute([$segs[2], $segs[4], json_encode($normalized)]);
        respond(['ok' => true]);
    }

    // DELETE /api/phcs/:id/monthly/:month
    if ($route === 'phcs' && $method === 'DELETE' && $n === 5 && $segs[3] === 'monthly') {
        requireAnyUser();
        $db->prepare('DELETE FROM monthly_data WHERE phc_id=? AND month_key=?')->execute([$segs[2], $segs[4]]);
        respond(['ok' => true]);
    }

    // PUT /api/phcs/:id/impact/overall
    if ($route === 'phcs' && $method === 'PUT' && $n === 5 && $segs[3] === 'impact' && $segs[4] === 'overall') {
        requireAnyUser();
        $db->prepare('UPDATE phcs SET overall_impact=? WHERE id=?')->execute([$body['text'] ?? '', $segs[2]]);
        respond(['ok' => true]);
    }

    // POST /api/phcs/:id/impact/:type
    if ($route === 'phcs' && $method === 'POST' && $n === 5 && $segs[3] === 'impact') {
        requireAnyUser();
        $info = IMPACT_MAP[$segs[4]] ?? null;
        if (!$info) respond(['error' => 'Invalid impact type'], 400);
        if (empty($body['id'])) respond(['error' => 'item id required'], 400);
        $phcId = $segs[2];
        $st    = $db->prepare("SELECT COALESCE(MAX(sort_order),0) AS m FROM {$info['table']} WHERE phc_id=?");
        $st->execute([$phcId]);
        $m    = (int)$st->fetchColumn();
        $cols = array_map(fn($c) => $c === 'date' ? '`date`' : $c, ['id', 'phc_id', ...$info['cols'], 'sort_order']);
        $vals = [$body['id'], $phcId];
        foreach ($info['cols'] as $col) {
            $jsKey = $info['jsMap'][$col] ?? $col;
            $v = $body[$jsKey] ?? $body[$col] ?? null;
            if ($col === 'images_json' || $col === 'photos_json') $v = json_encode(is_array($v) ? $v : []);
            $vals[] = $v;
        }
        $vals[] = $m + 1;
        $ph     = implode(',', array_fill(0, count($cols), '?'));
        $db->prepare("REPLACE INTO {$info['table']} (" . implode(',', $cols) . ") VALUES ($ph)")->execute($vals);
        respond(['ok' => true], 201);
    }

    // PUT /api/phcs/:id/impact/:type/:itemId
    if ($route === 'phcs' && $method === 'PUT' && $n === 6 && $segs[3] === 'impact') {
        requireAnyUser();
        $info = IMPACT_MAP[$segs[4]] ?? null;
        if (!$info) respond(['error' => 'Invalid impact type'], 400);
        $sets = []; $vals = [];
        foreach ($info['cols'] as $col) {
            $sets[] = ($col === 'date' ? '`date`' : $col) . '=?';
            $jsKey  = $info['jsMap'][$col] ?? $col;
            $v = $body[$jsKey] ?? $body[$col] ?? null;
            if ($col === 'images_json' || $col === 'photos_json') $v = json_encode(is_array($v) ? $v : []);
            $vals[] = $v;
        }
        $vals[] = $segs[5];
        $db->prepare("UPDATE {$info['table']} SET " . implode(',', $sets) . " WHERE id=?")->execute($vals);
        respond(['ok' => true]);
    }

    // DELETE /api/phcs/:id/impact/:type/:itemId
    if ($route === 'phcs' && $method === 'DELETE' && $n === 6 && $segs[3] === 'impact') {
        requireAdmin();
        $info = IMPACT_MAP[$segs[4]] ?? null;
        if (!$info) respond(['error' => 'Invalid impact type'], 400);
        $db->prepare("DELETE FROM {$info['table']} WHERE id=?")->execute([$segs[5]]);
        respond(['ok' => true]);
    }

    // PUT /api/settings/credentials
    if ($route === 'settings' && ($segs[2] ?? '') === 'credentials' && $method === 'PUT') {
        $currentUsername = $body['currentUsername'] ?? '';
        $currentPassword = $body['currentPassword'] ?? '';
        $newUsername     = $body['username'] ?? '';
        $newPassword     = $body['newPassword'] ?? '';
        if (!$currentPassword) respond(['error' => 'Current password required'], 400);
        if (!$newUsername)     respond(['error' => 'Username required'], 400);
        $st = $db->prepare('SELECT * FROM users WHERE username = ?');
        $st->execute([$currentUsername ?: 'admin']);
        $user = $st->fetch();
        if (!$user || $user['password_hash'] !== $currentPassword) respond(['error' => 'Current password incorrect'], 401);
        $newHash = $newPassword ?: $user['password_hash'];
        $db->prepare('UPDATE users SET username=?, password_hash=? WHERE id=?')->execute([$newUsername, $newHash, $user['id']]);
        respond(['ok' => true, 'username' => $newUsername]);
    }

    // GET /api/export
    if ($route === 'export' && $method === 'GET') {
        respond(['phcs' => buildAllPHCs(), 'customPilots' => getCustomPilots(), 'exportedAt' => date('c')]);
    }

    // POST /api/import
    if ($route === 'import' && $method === 'POST') {
        requireAdmin();
        $phcs = $body['phcs'] ?? null;
        $customPilots = $body['customPilots'] ?? [];
        if (!is_array($phcs)) respond(['error' => 'phcs array required'], 400);
        $db->beginTransaction();
        try {
            $db->exec('SET FOREIGN_KEY_CHECKS=0'); $db->exec('DELETE FROM phcs'); $db->exec('SET FOREIGN_KEY_CHECKS=1');
            $db->exec('DELETE FROM custom_pilots');
            if (is_array($customPilots)) {
                $ord = 0;
                foreach ($customPilots as $p) {
                    $db->prepare('INSERT INTO custom_pilots (id,label,fields_json,sort_order) VALUES (?,?,?,?)')->execute([
                        $p['id'] ?? ('pilot_' . bin2hex(random_bytes(4))),
                        $p['label'] ?? 'Pilot',
                        json_encode($p['fields'] ?? []),
                        $ord++
                    ]);
                }
            }
            foreach ($phcs as $phc) {
                $db->prepare('INSERT INTO phcs (id,name,mandal,location,upgraded_on,notes,overall_impact) VALUES (?,?,?,?,?,?,?)')->execute([
                    $phc['id'], $phc['name'], $phc['mandal'] ?? '', $phc['location'] ?? '', $phc['upgradedOn'] ?? '', $phc['notes'] ?? '', $phc['impact']['overallImpact'] ?? ''
                ]);
                foreach ($phc['monthlyData'] ?? [] as $month => $data)
                    $db->prepare('INSERT INTO monthly_data (phc_id,month_key,data_json) VALUES (?,?,?)')->execute([$phc['id'], $month, json_encode(normalizeMonthlyRecord($data))]);
                $ord = 0;
                foreach ($phc['impact']['caseStories'] ?? [] as $c)
                    $db->prepare('INSERT INTO impact_case_stories (id,phc_id,title,`date`,content,images_json,sort_order) VALUES (?,?,?,?,?,?,?)')->execute([$c['id'], $phc['id'], $c['title'] ?? '', $c['date'] ?? '', $c['content'] ?? '', json_encode($c['images'] ?? []), $ord++]);
                $ord = 0;
                foreach ($phc['impact']['videos'] ?? [] as $v)
                    $db->prepare('INSERT INTO impact_videos (id,phc_id,title,url,video_data,description,sort_order) VALUES (?,?,?,?,?,?,?)')->execute([$v['id'], $phc['id'], $v['title'] ?? '', $v['url'] ?? '', $v['videoData'] ?? $v['video_data'] ?? '', $v['description'] ?? '', $ord++]);
                $ord = 0;
                foreach ($phc['impact']['dignitaryVisits'] ?? [] as $d)
                    $db->prepare('INSERT INTO impact_dignitary_visits (id,phc_id,dignitary,`date`,purpose,photo,photos_json,sort_order) VALUES (?,?,?,?,?,?,?,?)')->execute([$d['id'], $phc['id'], $d['dignitary'] ?? '', $d['date'] ?? '', $d['purpose'] ?? '', $d['photo'] ?? (($d['photos'][0] ?? '')), json_encode($d['photos'] ?? []), $ord++]);
                $ord = 0;
                foreach ($phc['impact']['pictures'] ?? [] as $p)
                    $db->prepare('INSERT INTO impact_pictures (id,phc_id,caption,data_url,sort_order) VALUES (?,?,?,?,?)')->execute([$p['id'], $phc['id'], $p['caption'] ?? '', $p['dataUrl'] ?? $p['data_url'] ?? '', $ord++]);
            }
            $db->commit();
            respond(['ok' => true, 'imported' => count($phcs)]);
        } catch (Throwable $e) { $db->rollBack(); respond(['error' => $e->getMessage()], 500); }
    }

    // POST /api/reset
    if ($route === 'reset' && $method === 'POST') {
        requireAdmin();
        $db->beginTransaction();
        try {
            $db->exec('SET FOREIGN_KEY_CHECKS=0'); $db->exec('DELETE FROM phcs'); $db->exec('SET FOREIGN_KEY_CHECKS=1');
            $db->commit();
            respond(['ok' => true]);
        } catch (Throwable $e) { $db->rollBack(); respond(['error' => $e->getMessage()], 500); }
    }

    // GET /api/admin/users
    if ($route === 'admin' && ($segs[2] ?? '') === 'users' && $method === 'GET' && $n === 3) {
        requireAdmin();
        $rows = $db->query('SELECT id, username, role, created_at FROM users ORDER BY created_at')->fetchAll();
        respond($rows);
    }

    // POST /api/admin/users
    if ($route === 'admin' && ($segs[2] ?? '') === 'users' && $method === 'POST' && $n === 3) {
        requireAdmin();
        $username = trim($body['username'] ?? '');
        $password = $body['password'] ?? '';
        $role = in_array($body['role'] ?? '', ['admin', 'user']) ? $body['role'] : 'user';
        if (!$username || !$password) respond(['error' => 'Username and password required'], 400);
        $st = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
        $st->execute([$username]);
        if ((int)$st->fetchColumn() > 0) respond(['error' => 'Username already exists'], 409);
        $db->prepare('INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)')->execute([$username, $password, $role]);
        respond(['ok' => true], 201);
    }

    // DELETE /api/admin/users/:id
    if ($route === 'admin' && ($segs[2] ?? '') === 'users' && $method === 'DELETE' && $n === 4) {
        $adminUser = requireAdmin();
        $id = (int)$segs[3];
        $st = $db->prepare('SELECT id, username, role FROM users WHERE id = ?');
        $st->execute([$id]);
        $target = $st->fetch();
        if (!$target) respond(['error' => 'User not found'], 404);
        if ($target['username'] === $adminUser) respond(['error' => 'Cannot delete your own account'], 400);
        if ($target['role'] === 'admin') {
            $cnt = (int)$db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
            if ($cnt <= 1) respond(['error' => 'Cannot delete the last admin'], 400);
        }
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
        respond(['ok' => true]);
    }

    respond(['error' => 'Not found'], 404);

} catch (Throwable $e) {
    respond(['error' => $e->getMessage()], 500);
}