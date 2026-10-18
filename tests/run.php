<?php

declare(strict_types=1);

// Plain-PHP checks of the Landing extension's own classes, on SQLite in memory:
//   MODULENTO_CORE=/path/to/modulento php tests/run.php
// The core is only needed for its classes (vendor/autoload.php); without
// the variable it is expected next to this repository, in ../modulento.
// Exit code 0 means every check passed.

use Modulento\Core\App;
use Modulento\Landing\AdminController;
use Modulento\Landing\LandingController;
use Modulento\Landing\LandingPage;

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

// --- Database ------------------------------------------------------------
$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$pdo->exec('CREATE TABLE setting (name TEXT PRIMARY KEY, value TEXT NOT NULL)');
$pdo->exec("CREATE TABLE extension (id TEXT PRIMARY KEY, version TEXT, enabled INTEGER, enabled_at TEXT)");
$pdo->exec("INSERT INTO extension VALUES ('landing', '0', 1, '2026-01-01 00:00:00')");
$pdo->exec("CREATE TABLE account (id INTEGER PRIMARY KEY, email TEXT UNIQUE, display_name TEXT, password_hash TEXT, status TEXT DEFAULT 'active',
    locale TEXT DEFAULT 'de', created_at TEXT, last_login_at TEXT)");
$pdo->exec('CREATE TABLE page (id INTEGER PRIMARY KEY, status TEXT, role TEXT, in_header INTEGER, in_footer INTEGER, position INTEGER)');
$pdo->exec('CREATE TABLE page_translation (page_id INTEGER, locale TEXT, title TEXT, slug TEXT, meta_description TEXT, body TEXT)');
$pdo->exec('CREATE TABLE account_preference (account_id INTEGER, name TEXT, value TEXT, PRIMARY KEY (account_id, name))');
$pdo->exec('CREATE TABLE role (id INTEGER PRIMARY KEY, name TEXT)');
$pdo->exec('CREATE TABLE role_permission (role_id INTEGER REFERENCES role (id) ON DELETE CASCADE, permission TEXT)');
$pdo->exec('CREATE TABLE account_role (account_id INTEGER REFERENCES account (id) ON DELETE CASCADE, role_id INTEGER REFERENCES role (id) ON DELETE CASCADE)');
$pdo->exec('CREATE TABLE x_landing_page (id INTEGER PRIMARY KEY, published INTEGER NOT NULL DEFAULT 0, content TEXT NOT NULL, updated_at TEXT NOT NULL)');

// The migration and the schema above must name the same columns.
$migration = (string) file_get_contents($here . '/migrations/001_landing.sql');
preg_match('/CREATE TABLE x_landing_page \((.*?)\n\) ENGINE/s', $migration, $body);
preg_match_all('/^\s{4}([a-z_]+) [A-Z]/m', $body[1] ?? '', $columns);
$inTest = array_column($pdo->query('PRAGMA table_info(x_landing_page)')->fetchAll(), 'name');
sort($columns[1]);
sort($inTest);
check('schema: x_landing_page has the same columns in the migration and in the tests', $columns[1] !== [] && $columns[1] === $inTest);

$uploads = sys_get_temp_dir() . '/modulento-landing-test-' . bin2hex(random_bytes(4));
mkdir($uploads . '/extensions', 0777, true);
symlink($here, $uploads . '/extensions/landing');

$app = new App([
    'app' => [
        'env' => 'dev', 'url' => 'https://landing.example', 'name' => 'Testseite', 'root' => $core,
        'extensions' => $uploads . '/extensions', 'uploads' => $uploads, 'secret_key' => $uploads . '/secret.key',
    ],
    'update' => ['token' => ''],
    'packages' => ['sources' => []],
], $pdo, 'de');
$app->translator->load($core . '/core/lang', 'core');
$app->extensions->loadEnabled($app);
check('extension: the core loads it as an addon', isset($app->extensions->loaded()['landing']) && $app->extensions->loaded()['landing']->type === 'addon');
check('extension: permission and admin menu entry are announced, nothing else', ($app->permissions()['landing.manage'] ?? null) === 'landing.permission.manage'
    && $app->adminMenu() === [['label_key' => 'landing.admin.menu', 'path' => '/admin/landing', 'permission' => 'landing.manage', 'group' => 'content']]
    && $app->navigation() === [] && $app->footerLinks() === []);

// --- LandingPage: a fresh install has nothing, published is off ------------
$page = new LandingPage($pdo);
check('get: nothing stored yet', $page->get() === ['published' => false, 'meta' => [], 'blocks' => []]);

// --- save()/get(): round-trips, cleans, drops what it does not understand ---
$hero = LandingPage::newBlock('hero');
check('newBlock: a fresh block has an id, the right type, and is on', preg_match('/^[a-f0-9]{8}$/', $hero['id']) === 1 && $hero['type'] === 'hero' && $hero['enabled'] === true);

$blocks = [
    ['id' => $hero['id'], 'type' => 'hero', 'enabled' => true, 'eyebrow' => ['de' => 'Selbst gehostet'], 'heading' => ['de' => 'Deine Plattform'], 'subheading' => [],
        'primary_label' => ['de' => 'Los'], 'primary_url' => '/los', 'secondary_label' => [], 'secondary_url' => 'javascript:alert(1)'],
    ['id' => 'zzzzzzzz', 'type' => 'not-a-type', 'enabled' => true],
];
$page->save(true, ['de' => ['title' => 'Willkommen', 'meta_description' => '  trimmed  ']], $blocks);
$stored = $page->get();
check('save: published flag and meta are stored', $stored['published'] === true && $stored['meta']['de']['title'] === 'Willkommen' && $stored['meta']['de']['meta_description'] === 'trimmed');
check('save: a block of an unknown type is dropped', count($stored['blocks']) === 1 && $stored['blocks'][0]['id'] === $hero['id']);
check('save: an unsafe URL is dropped, a safe one kept', $stored['blocks'][0]['primary_url'] === '/los' && $stored['blocks'][0]['secondary_url'] === '');
check('save: a second save overwrites the one row rather than inserting another', (int) $pdo->query('SELECT COUNT(*) FROM x_landing_page')->fetchColumn() === 1);

// --- applyLocaleEdit(): only the given language changes, everything else stays ---
$withEnglish = LandingPage::applyLocaleEdit($stored['blocks'][0], 'en', [
    'eyebrow' => 'Self-hosted', 'heading' => 'Your platform', 'subheading' => '', 'primary_label' => 'Go', 'primary_url' => '/go', 'secondary_label' => '', 'secondary_url' => '',
]);
check('applyLocaleEdit: the new language is added', $withEnglish['heading'] === ['de' => 'Deine Plattform', 'en' => 'Your platform']);
check('applyLocaleEdit: German, untouched by an English edit, is unchanged', $withEnglish['eyebrow']['de'] === 'Selbst gehostet');
check('applyLocaleEdit: a URL has no language of its own - the English form\'s value wins outright', $withEnglish['primary_url'] === '/go');

// --- view(): resolves to one language with fallback, drops what has nothing to show ---
$blocks = [
    $hero,
    array_merge(LandingPage::newBlock('stats'), ['heading' => ['de' => 'Zahlen'], 'items' => [['value' => '0 %', 'label' => ['de' => 'Provision']], ['value' => '', 'label' => []]]]),
    array_merge(LandingPage::newBlock('cards'), ['heading' => [], 'items' => []]),
    array_merge(LandingPage::newBlock('text'), ['heading' => ['de' => 'Lizenz'], 'body' => ['de' => '<p>GPL<script>alert(1)</script></p>']]),
    array_merge(LandingPage::newBlock('cta'), ['heading' => ['de' => 'Los geht\'s'], 'text' => [], 'button_label' => ['de' => 'Kontakt'], 'button_url' => '/kontakt']),
];
$blocks[0] = ['id' => $hero['id'], 'type' => 'hero', 'enabled' => true, 'eyebrow' => ['de' => 'Selbst gehostet', 'en' => 'Self-hosted'], 'heading' => ['de' => 'Deine Plattform', 'en' => 'Your platform'],
    'subheading' => [], 'primary_label' => ['de' => 'Los'], 'primary_url' => '/los', 'secondary_label' => [], 'secondary_url' => ''];
$page->save(true, ['de' => ['title' => 'Willkommen', 'meta_description' => '']], $blocks);

$de = (new LandingPage($pdo))->view('de', 'de');
check('view: an empty cards block (no items) is left out', array_column($de['blocks'], 'type') === ['hero', 'stats', 'text', 'cta']);
check('view: an item with neither value nor label is dropped from the stats block', count($de['blocks'][1]['items']) === 1 && $de['blocks'][1]['items'][0]['label'] === 'Provision');
check('view: the text block was sanitised when it was saved, and is output as stored', $de['blocks'][2]['body'] === '<p>GPL</p>');

$en = (new LandingPage($pdo))->view('en', 'de');
check('view: a language with its own text uses it', $en['blocks'][0]['eyebrow'] === 'Self-hosted');
check('view: a language with no text of its own falls back to the default language', $en['blocks'][0]['primary_label'] === 'Los');

$disabled = $blocks;
$disabled[0]['enabled'] = false;
$page->save(true, ['de' => ['title' => '', 'meta_description' => '']], $disabled);
check('view: a disabled block is left out', !in_array('hero', array_column((new LandingPage($pdo))->view('de', 'de')['blocks'], 'type'), true));

// --- MAX_ITEMS / MAX_BLOCKS are enforced ------------------------------------
$manyItems = array_merge(LandingPage::newBlock('stats'), ['heading' => [], 'items' => array_fill(0, 20, ['value' => '1', 'label' => ['de' => 'x']])]);
$page->save(true, [], [$manyItems]);
check('save: items beyond MAX_ITEMS are dropped', count((new LandingPage($pdo))->get()['blocks'][0]['items']) === LandingPage::MAX_ITEMS);

$manyBlocks = array_map(fn () => LandingPage::newBlock('text'), range(1, 30));
$page->save(true, [], $manyBlocks);
check('save: blocks beyond MAX_BLOCKS are dropped', count((new LandingPage($pdo))->get()['blocks']) === LandingPage::MAX_BLOCKS);

// --- LandingController: unpublished is a 404, published renders the view ---
$page->save(false, ['de' => ['title' => 'x', 'meta_description' => '']], []);
ob_start();
http_response_code(200);
(new LandingController($app))->index([]);
$unpublished = ob_get_clean();
check('LandingController: an unpublished page is a 404', http_response_code() === 404);

$page->save(true, ['de' => ['title' => 'Modulento', 'meta_description' => '']], [array_merge(LandingPage::newBlock('text'), ['heading' => [], 'body' => ['de' => '<p>Hallo</p>']])]);
ob_start();
http_response_code(200);
(new LandingController($app))->index([]);
$published = ob_get_clean();
check('LandingController: a published page renders its blocks', http_response_code() === 200 && str_contains($published, '<p>Hallo</p>') && str_contains($published, 'Modulento'));

// --- AdminController: add, edit, reorder, delete, through the form itself --
$page->save(false, [], []);
$_POST = ['action' => 'add', 'new_type' => 'hero'];
(new AdminController($app))->save([]);
$afterAdd = (new LandingPage($pdo))->get();
check('AdminController::save(): "add" appends one new block of the chosen type', count($afterAdd['blocks']) === 1 && $afterAdd['blocks'][0]['type'] === 'hero');
$firstId = $afterAdd['blocks'][0]['id'];

$_POST = ['action' => 'add', 'new_type' => 'cta'];
(new AdminController($app))->save([]);
$secondId = array_values(array_diff(array_column((new LandingPage($pdo))->get()['blocks'], 'id'), [$firstId]))[0];

$_POST = ['action' => 'save', 'published' => '1', 'meta' => ['title' => 'Verkaufsseite', 'meta_description' => ''],
    'blocks' => [$firstId => ['enabled' => '1', 'heading' => 'Hallo Welt', 'eyebrow' => '', 'subheading' => '', 'primary_label' => '', 'primary_url' => '', 'secondary_label' => '', 'secondary_url' => '']]];
(new AdminController($app))->save([]);
$afterSave = (new LandingPage($pdo))->get();
check('AdminController::save(): "save" is published and stores the edited field', $afterSave['published'] === true && $afterSave['meta']['de']['title'] === 'Verkaufsseite'
    && $afterSave['blocks'][0]['heading']['de'] === 'Hallo Welt');

$_POST = ['action' => 'up:' . $secondId];
(new AdminController($app))->save([]);
check('AdminController::save(): "up" moves a block one place, saving the form first', array_column((new LandingPage($pdo))->get()['blocks'], 'id') === [$secondId, $firstId]);

$_POST = ['order' => [$firstId, $secondId]];
ob_start();
(new AdminController($app))->reorder([]);
$reorderResponse = ob_get_clean();
check('AdminController::reorder(): the dragged order is saved and answered as JSON', array_column((new LandingPage($pdo))->get()['blocks'], 'id') === [$firstId, $secondId]
    && json_decode($reorderResponse, true) === ['ok' => true]);

$_POST = ['action' => 'delete:' . $secondId];
(new AdminController($app))->save([]);
check('AdminController::save(): "delete" removes the block', array_column((new LandingPage($pdo))->get()['blocks'], 'id') === [$firstId]);

// --- Clean up ----------------------------------------------------------------
$remove = function (string $dir) use (&$remove): void {
    foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
        is_dir("{$dir}/{$entry}") && !is_link("{$dir}/{$entry}") ? $remove("{$dir}/{$entry}") : unlink("{$dir}/{$entry}");
    }
    rmdir($dir);
};
$remove($uploads);

// --- Language files ----------------------------------------------------------
$de = require $here . '/lang/de.php';
$en = require $here . '/lang/en.php';
check('lang: de and en have the same keys', array_keys($de) === array_keys($en));
check('lang: every key starts with "landing."', array_filter(array_keys($de), fn (string $key) => !str_starts_with($key, 'landing.')) === []);
$used = [];
foreach ([...glob($here . '/templates/{,*/}*.twig', GLOB_BRACE), ...glob($here . '/src/*.php')] as $file) {
    preg_match_all("/'(landing\.[a-z0-9_.]+[a-z0-9])'/", (string) file_get_contents($file), $found);
    $used = array_merge($used, $found[1]);
}
foreach (LandingPage::BLOCK_TYPES as $type) {
    $used[] = 'landing.block.' . $type;
}
// "landing.manage" is the permission id, "landing.css" the stylesheet ext_asset() loads - neither is a language key.
$unknown = array_diff(array_unique($used), array_keys($de), ['landing.manage', 'landing.css']);
check('lang: every key used in templates and code exists: ' . implode(', ', $unknown), $unknown === []);

if ($failed !== []) {
    echo "FAILED:\n";
    foreach ($failed as $label) {
        echo "  - {$label}\n";
    }
    echo count($failed) . " of {$checks} checks failed\n";
    exit(1);
}

echo "OK ({$checks} checks)\n";
