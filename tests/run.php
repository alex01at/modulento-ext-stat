<?php

declare(strict_types=1);

// Plain-PHP checks of the Statistik addon, on SQLite in memory, through a
// real App and the core's own router (same harness style modulento-ext-
// jobs's own tests/run.php uses):
//   MODULENTO_CORE=/path/to/modulento php tests/run.php
// Exit code 0 means every check passed.

use Modulento\Core\App;
use Modulento\Core\Kernel;
use Modulento\Core\Support\Auth;
use Modulento\Stat\ChartGeometry;
use Modulento\Stat\HitRecorder;
use Modulento\Stat\StatQuery;

$here = dirname(__DIR__);
$core = getenv('MODULENTO_CORE') ?: dirname($here) . '/modulento';

if (!is_file($core . '/vendor/autoload.php')) {
    fwrite(STDERR, "No Modulento core with vendor/ found at {$core}.\n"
        . "Set MODULENTO_CORE to a working copy of the core in which \"composer install\" has run.\n");
    exit(1);
}

require $core . '/vendor/autoload.php';

date_default_timezone_set('UTC');

$failed = [];
$checks = 0;

function check(string $label, bool $condition): void
{
    global $failed, $checks;
    $checks++;
    if (!$condition) {
        $failed[] = $label;
    }
}

// --- Database: the subset of the core's own schema the admin dashboard,
// the account dashboard and the conversion query actually touch.
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("CREATE TABLE account (id INTEGER PRIMARY KEY, email TEXT UNIQUE, display_name TEXT, password_hash TEXT, status TEXT,
    status_note TEXT, identity_status TEXT NOT NULL DEFAULT 'none', identity_note TEXT, identity_decided_at TEXT, identity_decided_by INTEGER,
    email_verified_at TEXT, terms_accepted_at TEXT, locale TEXT, created_at TEXT, last_login_at TEXT)");
$pdo->exec("CREATE TABLE account_billing_profile (account_id INTEGER PRIMARY KEY REFERENCES account (id) ON DELETE CASCADE,
    company_name TEXT, website TEXT, vat_id TEXT, tax_id TEXT, street TEXT, postal_code TEXT, city TEXT, country TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE notification (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    type TEXT, message_key TEXT, params TEXT, link TEXT, read_at TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE subscription_plan (id INTEGER PRIMARY KEY, slug TEXT UNIQUE, name TEXT, price_cents INTEGER, currency TEXT, period_months INTEGER, features TEXT NOT NULL DEFAULT '', offer_limits TEXT NOT NULL DEFAULT '{}', max_images_per_offer INTEGER NULL, per_seat INTEGER NOT NULL DEFAULT 0, min_quantity INTEGER NOT NULL DEFAULT 1, active INTEGER NOT NULL DEFAULT 1)");
$pdo->exec("CREATE TABLE subscription (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, plan_id INTEGER REFERENCES subscription_plan (id), status TEXT, period_end TEXT, provider_ref TEXT NULL, quantity INTEGER NOT NULL DEFAULT 1, stripe_item_ref TEXT NULL, reminded_for TEXT NULL, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE provider (id INTEGER PRIMARY KEY, account_id INTEGER UNIQUE REFERENCES account (id) ON DELETE CASCADE, type TEXT, status TEXT,
    status_note TEXT, name TEXT, slug TEXT UNIQUE, legal_name TEXT, street TEXT, postal_code TEXT, city TEXT, country TEXT, contact_email TEXT, phone TEXT,
    vat_id TEXT, tax_id TEXT, company_register TEXT, self_certified_at TEXT, details_changed_at TEXT, decided_at TEXT, decided_by INTEGER, created_at TEXT, updated_at TEXT,
    avg_response_minutes INTEGER, response_sample_count INTEGER NOT NULL DEFAULT 0, rating_count INTEGER NOT NULL DEFAULT 0, rating_sum INTEGER NOT NULL DEFAULT 0, is_demo INTEGER NOT NULL DEFAULT 0)");
$pdo->exec("CREATE TABLE provider_translation (provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, locale TEXT, headline TEXT, description TEXT, PRIMARY KEY (provider_id, locale))");
$pdo->exec("CREATE TABLE page (id INTEGER PRIMARY KEY, status TEXT, role TEXT UNIQUE, in_header INTEGER, in_footer INTEGER, position INTEGER, created_at TEXT, updated_at TEXT)");
$pdo->exec("CREATE TABLE page_translation (page_id INTEGER REFERENCES page (id) ON DELETE CASCADE, locale TEXT, title TEXT, slug TEXT,
    meta_description TEXT, body TEXT, PRIMARY KEY (page_id, locale), UNIQUE (locale, slug))");
$pdo->exec("CREATE TABLE account_token (id INTEGER PRIMARY KEY, account_id INTEGER REFERENCES account (id) ON DELETE CASCADE,
    purpose TEXT, token_hash TEXT UNIQUE, payload TEXT, expires_at TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE rate_limit_attempt (id INTEGER PRIMARY KEY, action TEXT, identifier TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE task_run (name TEXT PRIMARY KEY, last_started_at TEXT, last_finished_at TEXT, last_status TEXT, last_error TEXT)");
$pdo->exec("CREATE TABLE category (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES category (id), position INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE category_translation (category_id INTEGER REFERENCES category (id) ON DELETE CASCADE, locale TEXT, name TEXT, slug TEXT, PRIMARY KEY (category_id, locale), UNIQUE (locale, slug))");
$pdo->exec("CREATE TABLE offer (id INTEGER PRIMARY KEY, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, type TEXT, category_id INTEGER REFERENCES category (id) ON DELETE SET NULL,
    status TEXT, status_note TEXT, price_from INTEGER, currency TEXT, decided_at TEXT, decided_by INTEGER, published_at TEXT, created_at TEXT, updated_at TEXT,
    rating_count INTEGER NOT NULL DEFAULT 0, rating_sum INTEGER NOT NULL DEFAULT 0, is_demo INTEGER NOT NULL DEFAULT 0)");
$pdo->exec("CREATE TABLE offer_translation (offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, locale TEXT, title TEXT, slug TEXT, summary TEXT, description TEXT, PRIMARY KEY (offer_id, locale), UNIQUE (locale, slug))");
$pdo->exec("CREATE TABLE offer_image (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, name TEXT, extension TEXT, width INTEGER, height INTEGER, position INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE custom_field (id INTEGER PRIMARY KEY, entity TEXT, field_key TEXT, type TEXT, required INTEGER NOT NULL DEFAULT 0, position INTEGER NOT NULL DEFAULT 0, type_ids TEXT, created_at TEXT, UNIQUE (entity, field_key))");
$pdo->exec("CREATE TABLE custom_field_translation (field_id INTEGER REFERENCES custom_field (id) ON DELETE CASCADE, locale TEXT, label TEXT, option_labels TEXT, PRIMARY KEY (field_id, locale))");
$pdo->exec("CREATE TABLE custom_field_offer_value (field_id INTEGER REFERENCES custom_field (id) ON DELETE CASCADE, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, locale TEXT NOT NULL DEFAULT '', value TEXT, PRIMARY KEY (field_id, offer_id, locale))");
$pdo->exec("CREATE TABLE custom_field_provider_value (field_id INTEGER REFERENCES custom_field (id) ON DELETE CASCADE, provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, locale TEXT NOT NULL DEFAULT '', value TEXT, PRIMARY KEY (field_id, provider_id, locale))");
$pdo->exec("CREATE TABLE orders (id INTEGER PRIMARY KEY, buyer_id INTEGER REFERENCES account (id) ON DELETE SET NULL, provider_id INTEGER REFERENCES provider (id) ON DELETE SET NULL,
    offer_id INTEGER REFERENCES offer (id) ON DELETE SET NULL, flow TEXT, state TEXT, previous_state TEXT, state_actor TEXT, buyer_name TEXT, provider_name TEXT, offer_title TEXT,
    total INTEGER, currency TEXT, locale TEXT, payment_method TEXT, payment_state TEXT DEFAULT 'unpaid', paid_at TEXT, data TEXT, due_at TEXT, due_transition TEXT,
    terms_accepted_at TEXT, created_at TEXT, updated_at TEXT, closed_at TEXT)");
$pdo->exec("CREATE TABLE order_item (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, position INTEGER, label TEXT, quantity INTEGER, unit_price INTEGER)");
$pdo->exec("CREATE TABLE order_event (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, transition TEXT, from_state TEXT, to_state TEXT,
    actor_id INTEGER REFERENCES account (id) ON DELETE SET NULL, actor_role TEXT, note TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE order_message (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, account_id INTEGER REFERENCES account (id) ON DELETE SET NULL,
    author_role TEXT, body TEXT, flagged_word TEXT, status TEXT NOT NULL DEFAULT 'visible', decided_at TEXT, decided_by INTEGER REFERENCES account (id) ON DELETE SET NULL, created_at TEXT)");
$pdo->exec("CREATE TABLE order_file (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, event_id INTEGER REFERENCES order_event (id) ON DELETE CASCADE,
    message_id INTEGER REFERENCES order_message (id) ON DELETE CASCADE, account_id INTEGER REFERENCES account (id) ON DELETE SET NULL, author_role TEXT, original_name TEXT, stored_name TEXT, size INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE review (id INTEGER PRIMARY KEY, order_id INTEGER UNIQUE REFERENCES orders (id) ON DELETE CASCADE, offer_id INTEGER REFERENCES offer (id) ON DELETE SET NULL,
    provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, author_id INTEGER REFERENCES account (id) ON DELETE SET NULL, author_name TEXT, rating INTEGER, body TEXT, locale TEXT,
    status TEXT DEFAULT 'published', status_note TEXT, reply TEXT, replied_at TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE provider_payment (provider_id INTEGER REFERENCES provider (id) ON DELETE CASCADE, method TEXT, data TEXT, status TEXT, updated_at TEXT, PRIMARY KEY (provider_id, method))");
$pdo->exec("CREATE TABLE order_payment (id INTEGER PRIMARY KEY, order_id INTEGER REFERENCES orders (id) ON DELETE CASCADE, method TEXT, provider_reference TEXT, status TEXT,
    amount INTEGER, currency TEXT, created_at TEXT, updated_at TEXT, UNIQUE (method, provider_reference))");
$pdo->exec("CREATE TABLE account_avatar (account_id INTEGER PRIMARY KEY REFERENCES account (id) ON DELETE CASCADE, name TEXT, extension TEXT, created_at TEXT)");
$pdo->exec("CREATE TABLE offer_message (id INTEGER PRIMARY KEY, offer_id INTEGER REFERENCES offer (id) ON DELETE CASCADE, asker_id INTEGER REFERENCES account (id) ON DELETE CASCADE, author_id INTEGER REFERENCES account (id) ON DELETE SET NULL, body TEXT,
    flagged_word TEXT, status TEXT NOT NULL DEFAULT 'visible', decided_at TEXT, decided_by INTEGER REFERENCES account (id) ON DELETE SET NULL, created_at TEXT)");
$pdo->exec("CREATE TABLE message_seen (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, scope TEXT, ref_id INTEGER, sub_id INTEGER DEFAULT 0, seen_id INTEGER, PRIMARY KEY (account_id, scope, ref_id, sub_id))");
$pdo->exec("CREATE TABLE admin_log (id INTEGER PRIMARY KEY, created_at TEXT, actor_id INTEGER REFERENCES account (id) ON DELETE SET NULL, action TEXT, target_id INTEGER REFERENCES account (id) ON DELETE SET NULL, detail TEXT NOT NULL DEFAULT '')");
$pdo->exec("CREATE TABLE account_preference (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, name TEXT, value TEXT, PRIMARY KEY (account_id, name))");
$pdo->exec("CREATE TABLE account_rating (id INTEGER PRIMARY KEY, rater_id INTEGER REFERENCES account (id) ON DELETE SET NULL, rater_name TEXT,
    rated_id INTEGER REFERENCES account (id) ON DELETE CASCADE, rating INTEGER, body TEXT, locale TEXT, status TEXT DEFAULT 'published',
    status_note TEXT, reply TEXT, replied_at TEXT, created_at TEXT, UNIQUE (rater_id, rated_id))");
$pdo->exec("CREATE TABLE role (id INTEGER PRIMARY KEY, name TEXT)");
$pdo->exec("CREATE TABLE role_permission (role_id INTEGER REFERENCES role (id) ON DELETE CASCADE, permission TEXT)");
$pdo->exec("CREATE TABLE account_role (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, role_id INTEGER REFERENCES role (id) ON DELETE CASCADE)");
$pdo->exec("CREATE TABLE extension (id TEXT PRIMARY KEY, version TEXT, enabled INTEGER)");
$pdo->exec("CREATE TABLE setting (name TEXT PRIMARY KEY, value TEXT)");
$pdo->exec("CREATE TABLE media (id INTEGER PRIMARY KEY, file TEXT UNIQUE, title TEXT, width INTEGER, height INTEGER, bytes INTEGER, created_at TEXT)");
$pdo->exec("CREATE TABLE package (kind TEXT, id TEXT, repo TEXT, version TEXT, installed_at TEXT, PRIMARY KEY (kind, id))");

// --- This addon's own table ---------------------------------------------------
$pdo->exec('CREATE TABLE x_stat_hit (id INTEGER PRIMARY KEY, day TEXT NOT NULL, path TEXT NOT NULL, referrer_domain TEXT, device TEXT NOT NULL DEFAULT \'desktop\', country TEXT, visitor_hash TEXT NOT NULL)');
$pdo->exec('PRAGMA foreign_keys = ON');

// The migration and the schema above must name the same columns.
$migration = (string) file_get_contents($here . '/migrations/001_stat.sql');
preg_match('/CREATE TABLE x_stat_hit \((.*?)\n\) ENGINE/s', $migration, $body);
preg_match_all('/^\s{4}([a-z_]+) [A-Z]/m', $body[1] ?? '', $columns);
$inTest = array_column($pdo->query('PRAGMA table_info(x_stat_hit)')->fetchAll(), 'name');
sort($columns[1]);
sort($inTest);
check('schema: x_stat_hit has the same columns in the migration and in the tests', $columns[1] !== [] && $columns[1] === $inTest);

$pdo->exec("INSERT INTO setting VALUES ('core.languages', 'de,en'), ('core.default_language', 'de')");
$testHash = password_hash('correct horse battery', PASSWORD_BCRYPT, ['cost' => 4]);
$insertAccount = $pdo->prepare("INSERT INTO account (id, email, password_hash, status, email_verified_at, locale, created_at) VALUES (?, ?, ?, ?, '2026-01-01 00:00:00', 'de', '2026-01-01 00:00:00')");
foreach ([[1, 'provider@example.test', 'active'], [2, 'other-provider@example.test', 'active'], [3, 'admin@example.test', 'active'], [4, 'nobody@example.test', 'active']] as [$id, $email, $status]) {
    $insertAccount->execute([$id, $email, $testHash, $status]);
}
$pdo->exec("INSERT INTO role VALUES (1, 'admin')");
$pdo->exec("INSERT INTO role_permission VALUES (1, '*')");
$pdo->exec("INSERT INTO account_role VALUES (3, 1)");
$pdo->exec("INSERT INTO provider (id, account_id, status, name, slug, created_at, updated_at) VALUES (1, 1, 'approved', 'Anbieter GmbH', 'anbieter-gmbh', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO provider (id, account_id, status, name, slug, created_at, updated_at) VALUES (2, 2, 'approved', 'Other GmbH', 'other-gmbh', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO offer (id, provider_id, type, status, currency, published_at, created_at, updated_at) VALUES (1, 1, 'freelancer.service', 'published', 'EUR', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO offer_translation (offer_id, locale, title, slug) VALUES (1, 'de', 'Mein Angebot', 'mein-angebot')");
$pdo->exec("INSERT INTO offer (id, provider_id, type, status, currency, published_at, created_at, updated_at) VALUES (2, 2, 'freelancer.service', 'published', 'EUR', '2026-01-01 00:00:00', '2026-01-01 00:00:00', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO offer_translation (offer_id, locale, title, slug) VALUES (2, 'de', 'Fremdes Angebot', 'fremdes-angebot')");
$pdo->exec("INSERT INTO extension (id, version, enabled) VALUES ('stat', '0', 1)");

$uploads = sys_get_temp_dir() . '/modulento-stat-test-' . bin2hex(random_bytes(4));
mkdir($uploads . '/extensions', 0777, true);
symlink($here, $uploads . '/extensions/stat');

$testThemes = sys_get_temp_dir() . '/modulento-stat-test-themes-' . bin2hex(random_bytes(4));
mkdir($testThemes, 0777, true);
symlink($core . '/themes/default', $testThemes . '/default');
symlink($core . '/themes/admin', $testThemes . '/admin');

$config = [
    'app' => [
        'env' => 'dev', 'url' => 'https://stat.example', 'name' => 'Testseite', 'root' => $core,
        'extensions' => $uploads . '/extensions', 'uploads' => $uploads, 'secret_key' => $uploads . '/secret.key',
        'themes' => $testThemes, 'work' => sys_get_temp_dir() . '/modulento-stat-test-work-' . bin2hex(random_bytes(4)),
    ],
    'update' => ['token' => ''],
    'packages' => ['sources' => []],
    'mail' => ['from' => 'noreply@example.test', 'transport' => 'log', 'log_path' => sys_get_temp_dir() . '/modulento-stat-test-mail-' . bin2hex(random_bytes(4)) . '.log'],
];

file_put_contents($config['app']['secret_key'], bin2hex(random_bytes(32)));

/** @return array{status: int, body: string} */
function request(PDO $pdo, array $config, string $core, string $method, string $path, int|false|null $accountId, array $post = []): array
{
    if ($accountId !== false) {
        $_SESSION = [];
    }
    if (is_int($accountId)) {
        $hash = $pdo->query('SELECT password_hash FROM account WHERE id = ' . $accountId)->fetchColumn();
        $_SESSION = ['account_id' => $accountId, 'auth_stamp' => Auth::stamp((string) $hash)];
    }
    $_SESSION['_csrf'] = 'test-token';
    if ($method === 'POST') {
        $post += ['_csrf' => 'test-token'];
    }
    $_POST = $post;
    parse_str((string) parse_url($path, PHP_URL_QUERY), $_GET);
    $_SERVER['REQUEST_URI'] = $path;
    unset($_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_REFERER']);
    http_response_code(200);

    $app = new App($config, $pdo, 'de');
    $app->translator->load($core . '/core/lang', 'core');
    Kernel::registerCore($app);
    $app->extensions->loadEnabled($app);
    Kernel::registerLast($app);
    Kernel::loadThemeTexts($app);
    $redirect = Kernel::prepareRequest($app, $path, startSession: false);

    ob_start();
    if ($redirect === null) {
        $app->router->dispatch($method, $app->path);
    }
    $body = (string) ob_get_clean();

    return ['status' => (int) http_response_code(), 'body' => $body];
}

$provider = 1;
$admin = 3;
$nobody = 4;

$get = fn (string $path, int|false|null $who = null) => request($pdo, $config, $core, 'GET', $path, $who);
$post = fn (string $path, array $data, int|false|null $who = null) => request($pdo, $config, $core, 'POST', $path, $who, $data);

// --- Extension registration --------------------------------------------------
$app = new App($config, $pdo, 'de');
$app->translator->load($core . '/core/lang', 'core');
Kernel::registerCore($app);
$app->extensions->loadEnabled($app);
check('extension: the core loads it as an addon', isset($app->extensions->loaded()['stat']) && $app->extensions->loaded()['stat']->type === 'addon');
check('extension: the beacon is announced on every page', array_column($app->bodyScripts(), 'template') === ['@stat/script_tag.twig']);
check('extension: the permission is announced', array_key_exists('stat.view', $app->permissions()));

// --- HitRecorder: no cookie, no IP, a hash that rotates daily -----------------
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
HitRecorder::record($app, '/offers/mein-angebot?utm=x', 'Mozilla/5.0 (Macintosh)', 'https://google.com/search?q=x');
$row = $pdo->query('SELECT * FROM x_stat_hit ORDER BY id DESC LIMIT 1')->fetch();
check('HitRecorder: the query string is stripped off the stored path', $row['path'] === '/offers/mein-angebot');
check('HitRecorder: the referrer is reduced to its domain', $row['referrer_domain'] === 'google.com');
check('HitRecorder: a desktop User-Agent is recorded as desktop', $row['device'] === 'desktop');
check('HitRecorder: the hash is 16 hex characters, never the IP or User-Agent itself', preg_match('/^[0-9a-f]{16}$/', $row['visitor_hash']) === 1);

HitRecorder::record($app, '/', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0)', null);
$iphoneRow = $pdo->query("SELECT * FROM x_stat_hit WHERE path = '/'")->fetch();
check('HitRecorder: an iPhone User-Agent is recorded as mobile', $iphoneRow['device'] === 'mobile');
check('HitRecorder: no referrer is stored as null, not an empty string', $iphoneRow['referrer_domain'] === null);

HitRecorder::record($app, '/', 'Mozilla/5.0 (iPad; CPU OS 17_0)', null);
$ipadRow = $pdo->query("SELECT * FROM x_stat_hit WHERE device = 'tablet'")->fetch();
check('HitRecorder: an iPad User-Agent is recorded as tablet', $ipadRow !== false);

HitRecorder::record($app, '/offers/mein-angebot', 'Mozilla/5.0 (Macintosh)', 'https://google.com/search?q=x');
$sameDay = $pdo->query("SELECT COUNT(DISTINCT visitor_hash) FROM x_stat_hit WHERE visitor_hash = '{$row['visitor_hash']}'")->fetchColumn();
check('HitRecorder: the same IP/User-Agent on the same day hashes to the same value', (int) $sameDay === 1
    && (int) $pdo->query("SELECT COUNT(*) FROM x_stat_hit WHERE visitor_hash = '{$row['visitor_hash']}'")->fetchColumn() === 2);

HitRecorder::record($app, '/internal', 'Mozilla/5.0', 'https://stat.example/offers/mein-angebot');
$internalRow = $pdo->query("SELECT * FROM x_stat_hit WHERE path = '/internal'")->fetch();
check('HitRecorder: a same-site referrer is dropped, not stored as a domain', $internalRow['referrer_domain'] === null);

$_SERVER['HTTP_CF_IPCOUNTRY'] = 'AT';
HitRecorder::record($app, '/with-country', 'Mozilla/5.0', null);
$countryRow = $pdo->query("SELECT * FROM x_stat_hit WHERE path = '/with-country'")->fetch();
check('HitRecorder: a host-supplied country header (e.g. Cloudflare) is used when present', $countryRow['country'] === 'AT');
unset($_SERVER['HTTP_CF_IPCOUNTRY']);

// --- The beacon route ---------------------------------------------------------
// request() sends form-encoded $_POST, but hit() reads php://input (raw
// JSON) - this still proves the route exists and answers 204 with no body.
check('POST /stat/hit: answers 204 regardless of body shape', $post('/stat/hit', [], false)['status'] === 204);
check('GET /stat.js: served as JavaScript, with the beacon endpoint inside', str_contains($get('/stat.js')['body'], '/stat/hit'));

// --- Admin dashboard: behind the stat.view permission --------------------------
check('GET /admin/stat: refused without the permission', $get('/admin/stat', $provider)['status'] !== 200);
$adminResponse = $get('/admin/stat', $admin);
check('GET /admin/stat: shown to an account with the permission', $adminResponse['status'] === 200);
check('GET /admin/stat: the beacon is only on public pages, not the administration', !str_contains($adminResponse['body'], 'stat.js'));
check('GET /admin/stat: the privacy note is on the page', str_contains($adminResponse['body'], 'DSGVO') || str_contains($adminResponse['body'], 'Cookies'));

// --- Account dashboard: a provider's own numbers, by offer path ----------------
check('GET /account/stats: 404 for an account with no provider profile', $get('/account/stats', $nobody)['status'] === 404);
check('GET /account/stats: 200 for a provider', $get('/account/stats', $provider)['status'] === 200);

// --- StatQuery: aggregation -----------------------------------------------------
$today = date('Y-m-d');
$totals = StatQuery::totals($app, $today, $today);
check('StatQuery::totals(): counts every hit and every distinct visitor hash', $totals['views'] === 6 && $totals['visitors'] === 4);

$top = StatQuery::topPaths($app, $today, $today, 10);
check('StatQuery::topPaths(): an /offers/ path is sorted into "offers", not "pages"', array_column($top['offers'], 'path') === ['/offers/mein-angebot']
    && !in_array('/offers/mein-angebot', array_column($top['pages'], 'path'), true));
check('StatQuery::topPaths(): two hits on the same path count as one row with views = 2', $top['offers'][0]['views'] === 2);

$referrers = StatQuery::referrers($app, $today, $today, 10);
check('StatQuery::referrers(): the stored domain is grouped and counted', $referrers !== [] && $referrers[0]['domain'] === 'google.com' && $referrers[0]['views'] === 2);

$devices = StatQuery::devices($app, $today, $today);
check('StatQuery::devices(): desktop, mobile and tablet are all present, even one at a time', $devices === ['desktop' => 4, 'mobile' => 1, 'tablet' => 1]);

$ownOffers = StatQuery::topPathsForSlugs($app, $today, $today, ['mein-angebot']);
check('StatQuery::topPathsForSlugs(): only paths ending in the given slug are returned', count($ownOffers) === 1 && $ownOffers[0]['path'] === '/offers/mein-angebot');
check('StatQuery::topPathsForSlugs(): an empty slug list is not "every path"', StatQuery::topPathsForSlugs($app, $today, $today, []) === []);

// --- Conversion: orders when there is an OrderFlow, nothing when there is not --
check('StatQuery::conversion(): null when this installation has neither an OrderFlow nor jobs active', StatQuery::conversion($app, $today, $today, 4) === null);

$pdo->exec("INSERT INTO orders (id, buyer_id, provider_id, offer_id, flow, state, state_actor, buyer_name, provider_name, offer_title, total, currency, locale, created_at, updated_at)
    VALUES (1, 1, 1, 1, 'sale', 'paid', 'system', 'Buyer', 'Seller', 'Offer', 1000, 'EUR', 'de', '" . $today . " 10:00:00', '" . $today . " 10:00:00')");

// --- ChartGeometry: pure coordinate math, no database needed -------------------
$series = [
    ['day' => '2026-01-01', 'views' => 0, 'visitors' => 0],
    ['day' => '2026-01-02', 'views' => 10, 'visitors' => 5],
    ['day' => '2026-01-03', 'views' => 20, 'visitors' => 8],
];
$chart = ChartGeometry::lineChart($series);
check('ChartGeometry: one point per day in the series', substr_count($chart['points_views'], ' ') === 2);
check('ChartGeometry: the highest value in the series reaches the top of the plot area (y close to the top padding)', str_contains($chart['points_views'], ',10') || str_contains($chart['points_views'], ',10.0'));
check('ChartGeometry: grid labels run from 0 to the series max', $chart['grid'][0]['value'] === 0 && end($chart['grid'])['value'] === 20);
check('ChartGeometry: a flat, all-zero series does not divide by zero', ChartGeometry::lineChart([['day' => '2026-01-01', 'views' => 0, 'visitors' => 0]])['points_views'] !== '');

if ($failed !== []) {
    fwrite(STDERR, "FAILED:\n");
    foreach ($failed as $label) {
        fwrite(STDERR, "  - {$label}\n");
    }
    fwrite(STDERR, count($failed) . " of {$checks} checks failed\n");
    exit(1);
}

echo "OK ({$checks} checks)\n";
