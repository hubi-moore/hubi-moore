<?php
/**
 * Standalone generator pliku llms.txt dla Magento 2
 * Kompatybilny z: Magento 2.4.x, PHP 7.4+
 *
 * Uruchomienie:
 *   php generate_llms_txt.php
 *   lub przez HTTP: https://twoj-sklep.pl/generate_llms_txt.php (usuń po wygenerowaniu!)
 *
 * Plik llms.txt zostanie zapisany w katalogu pub/ (document root Magento).
 */

declare(strict_types=1);

// ─── KONFIGURACJA ────────────────────────────────────────────────────────────

define('MAGENTO_ROOT', __DIR__); // Skrypt umieszczony w katalogu głównym Magento

/**
 * Odczytuje konfigurację DB automatycznie z app/etc/env.php Magento.
 * Nie musisz nic ręcznie wpisywać — dane są już tam zapisane przez instalator.
 */
function loadDbConfigFromEnv(string $magentoRoot): array
{
    $envFile = $magentoRoot . '/app/etc/env.php';

    if (!file_exists($envFile)) {
        throw new RuntimeException(
            "Nie znaleziono pliku app/etc/env.php w: {$magentoRoot}\n" .
            "Upewnij się, że skrypt znajduje się w głównym katalogu Magento."
        );
    }

    $env = require $envFile;

    $conn = $env['db']['connection']['default'] ?? null;
    if (!$conn) {
        throw new RuntimeException(
            "Brak sekcji db->connection->default w env.php. " .
            "Plik może być uszkodzony lub to niestandardowa konfiguracja."
        );
    }

    return [
        'host'   => $conn['host']           ?? 'localhost',
        'dbname' => $conn['dbname']         ?? '',
        'user'   => $conn['username']       ?? '',
        'pass'   => $conn['password']       ?? '',
        'prefix' => $env['db']['table_prefix'] ?? '',
    ];
}

$dbConfig   = loadDbConfigFromEnv(MAGENTO_ROOT);
$outputFile = MAGENTO_ROOT . '/pub/llms.txt';

// ─── POŁĄCZENIE Z BAZĄ ───────────────────────────────────────────────────────

function getDb(array $cfg): PDO
{
    $dsn = "mysql:host={$cfg['host']};dbname={$cfg['dbname']};charset=utf8mb4";
    $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

// ─── POMOCNIKI ───────────────────────────────────────────────────────────────

function p(string $prefix, string $table): string
{
    return $prefix . $table;
}

/**
 * Pobiera wartość konfiguracyjną z core_config_data.
 */
function getConfig(PDO $db, string $path, string $prefix, int $storeId = 0): string
{
    $t = p($prefix, 'core_config_data');
    $stmt = $db->prepare(
        "SELECT value FROM {$t}
         WHERE path = :path AND scope_id = :scope
         ORDER BY scope DESC LIMIT 1"
    );
    $stmt->execute([':path' => $path, ':scope' => $storeId]);
    return (string)($stmt->fetchColumn() ?? '');
}

/**
 * Zwraca atrybut EAV produktu (varchar) dla danego attribute_code.
 */
function getProductAttributeId(PDO $db, string $prefix, string $code): ?int
{
    $t = p($prefix, 'eav_attribute');
    $stmt = $db->prepare(
        "SELECT attribute_id FROM {$t}
         WHERE entity_type_id = 4 AND attribute_code = :code LIMIT 1"
    );
    $stmt->execute([':code' => $code]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

function slugify(string $text): string
{
    return trim(preg_replace('/\s+/', '-', strtolower($text)), '-');
}

// ─── POBIERANIE DANYCH ───────────────────────────────────────────────────────

function getStoreInfo(PDO $db, string $prefix): array
{
    return [
        'name'        => getConfig($db, 'general/store_information/name', $prefix),
        'url'         => rtrim(getConfig($db, 'web/secure/base_url', $prefix)
                            ?: getConfig($db, 'web/unsecure/base_url', $prefix), '/'),
        'description' => getConfig($db, 'general/store_information/street_line1', $prefix),
        'locale'      => getConfig($db, 'general/locale/code', $prefix),
        'currency'    => getConfig($db, 'currency/options/default', $prefix),
        'country'     => getConfig($db, 'general/country/default', $prefix),
        'phone'       => getConfig($db, 'general/store_information/phone', $prefix),
        'email'       => getConfig($db, 'trans_email/ident_general/email', $prefix),
    ];
}

function getCategories(PDO $db, string $prefix, string $baseUrl): array
{
    $catTable  = p($prefix, 'catalog_category_entity');
    $attrTable = p($prefix, 'eav_attribute');
    $varTable  = p($prefix, 'catalog_category_entity_varchar');
    $intTable  = p($prefix, 'catalog_category_entity_int');

    // attribute_id dla 'name', 'url_path', 'is_active'
    $stmt = $db->prepare(
        "SELECT attribute_code, attribute_id FROM {$attrTable}
         WHERE entity_type_id = 3 AND attribute_code IN ('name','url_path','is_active')"
    );
    $stmt->execute();
    $attrs = [];
    foreach ($stmt->fetchAll() as $row) {
        $attrs[$row['attribute_code']] = (int)$row['attribute_id'];
    }

    // Pobierz aktywne kategorie (poziom >= 2, wyklucz root)
    $sql = "
        SELECT
            c.entity_id,
            c.level,
            c.parent_id,
            n.value  AS name,
            u.value  AS url_path,
            ia.value AS is_active
        FROM {$catTable} c
        LEFT JOIN {$varTable} n
            ON n.entity_id = c.entity_id AND n.attribute_id = :nameId AND n.store_id = 0
        LEFT JOIN {$varTable} u
            ON u.entity_id = c.entity_id AND u.attribute_id = :urlId  AND u.store_id = 0
        LEFT JOIN {$intTable} ia
            ON ia.entity_id = c.entity_id AND ia.attribute_id = :activeId AND ia.store_id = 0
        WHERE c.level >= 2
        HAVING (is_active IS NULL OR is_active = 1)
        ORDER BY c.level, c.position
        LIMIT 200
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':nameId'   => $attrs['name']     ?? 0,
        ':urlId'    => $attrs['url_path'] ?? 0,
        ':activeId' => $attrs['is_active'] ?? 0,
    ]);

    $categories = [];
    foreach ($stmt->fetchAll() as $row) {
        if (empty($row['name'])) continue;
        $url = $row['url_path']
            ? $baseUrl . '/' . $row['url_path']
            : $baseUrl . '/' . slugify($row['name']);
        $categories[] = [
            'name'  => $row['name'],
            'url'   => $url,
            'level' => (int)$row['level'],
        ];
    }
    return $categories;
}

function getProducts(PDO $db, string $prefix, string $baseUrl, int $limit = 500): array
{
    $cpeTable  = p($prefix, 'catalog_product_entity');
    $attrTable = p($prefix, 'eav_attribute');
    $varTable  = p($prefix, 'catalog_product_entity_varchar');
    $decTable  = p($prefix, 'catalog_product_entity_decimal');
    $intTable  = p($prefix, 'catalog_product_entity_int');
    $urlTable  = p($prefix, 'url_rewrite');

    // Pobierz attribute_ids
    $stmt = $db->prepare(
        "SELECT attribute_code, attribute_id FROM {$attrTable}
         WHERE entity_type_id = 4
           AND attribute_code IN ('name','description','price','status','visibility','url_key')"
    );
    $stmt->execute();
    $attrs = [];
    foreach ($stmt->fetchAll() as $row) {
        $attrs[$row['attribute_code']] = (int)$row['attribute_id'];
    }

    $sql = "
        SELECT
            p.entity_id,
            p.sku,
            n.value   AS name,
            d.value   AS description,
            pr.value  AS price,
            s.value   AS status,
            v.value   AS visibility,
            uk.value  AS url_key,
            ur.request_path AS url_path
        FROM {$cpeTable} p
        LEFT JOIN {$varTable} n
            ON n.entity_id = p.entity_id AND n.attribute_id = :nameId AND n.store_id = 0
        LEFT JOIN {$varTable} d
            ON d.entity_id = p.entity_id AND d.attribute_id = :descId AND d.store_id = 0
        LEFT JOIN {$decTable} pr
            ON pr.entity_id = p.entity_id AND pr.attribute_id = :priceId AND pr.store_id = 0
        LEFT JOIN {$intTable} s
            ON s.entity_id = p.entity_id AND s.attribute_id = :statusId AND s.store_id = 0
        LEFT JOIN {$intTable} v
            ON v.entity_id = p.entity_id AND v.attribute_id = :visId AND v.store_id = 0
        LEFT JOIN {$varTable} uk
            ON uk.entity_id = p.entity_id AND uk.attribute_id = :urlKeyId AND uk.store_id = 0
        LEFT JOIN {$urlTable} ur
            ON ur.entity_id = p.entity_id AND ur.entity_type = 'product' AND ur.store_id = 1
        WHERE (s.value IS NULL OR s.value = 1)
          AND (v.value IS NULL OR v.value IN (2,3,4))
        ORDER BY p.entity_id DESC
        LIMIT {$limit}
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':nameId'   => $attrs['name']        ?? 0,
        ':descId'   => $attrs['description'] ?? 0,
        ':priceId'  => $attrs['price']       ?? 0,
        ':statusId' => $attrs['status']      ?? 0,
        ':visId'    => $attrs['visibility']  ?? 0,
        ':urlKeyId' => $attrs['url_key']     ?? 0,
    ]);

    $products = [];
    foreach ($stmt->fetchAll() as $row) {
        if (empty($row['name'])) continue;
        $urlPath = $row['url_path'] ?: ($row['url_key'] ? $row['url_key'] . '.html' : null);
        $url = $urlPath ? $baseUrl . '/' . $urlPath : null;

        // Skróć opis do ~300 znaków
        $desc = strip_tags((string)$row['description']);
        $desc = preg_replace('/\s+/', ' ', $desc);
        if (mb_strlen($desc) > 300) {
            $desc = mb_substr($desc, 0, 297) . '...';
        }

        $products[] = [
            'name'  => $row['name'],
            'sku'   => $row['sku'],
            'price' => $row['price'] ? number_format((float)$row['price'], 2, '.', '') : null,
            'url'   => $url,
            'desc'  => $desc,
        ];
    }
    return $products;
}

function getCmsPages(PDO $db, string $prefix, string $baseUrl): array
{
    $table = p($prefix, 'cms_page');
    $stmt  = $db->prepare(
        "SELECT title, identifier, meta_description
         FROM {$table}
         WHERE is_active = 1
           AND identifier NOT IN ('home','no-route','enable-cookies','privacy-policy-cookie-restriction-mode')
         ORDER BY page_id
         LIMIT 50"
    );
    $stmt->execute();
    $pages = [];
    foreach ($stmt->fetchAll() as $row) {
        $pages[] = [
            'title'       => $row['title'],
            'url'         => $baseUrl . '/' . ltrim($row['identifier'], '/'),
            'description' => $row['meta_description'] ?? '',
        ];
    }
    return $pages;
}

function getCmsBlocks(PDO $db, string $prefix): array
{
    $table = p($prefix, 'cms_block');
    $stmt  = $db->prepare(
        "SELECT title, identifier FROM {$table}
         WHERE is_active = 1
         ORDER BY block_id
         LIMIT 30"
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

// ─── BUDOWANIE TREŚCI llms.txt ────────────────────────────────────────────────

function buildLlmsTxt(array $store, array $categories, array $products, array $pages): string
{
    $now  = date('Y-m-d');
    $name = $store['name'] ?: 'Sklep internetowy';
    $url  = $store['url']  ?: 'https://example.com';

    $lines = [];

    // ── Nagłówek główny ──
    $lines[] = "# {$name}";
    $lines[] = "";
    $lines[] = "> {$name} to sklep internetowy dostępny pod adresem {$url}.";
    if ($store['currency']) {
        $lines[] = "> Waluta: {$store['currency']}.";
    }
    if ($store['country']) {
        $lines[] = "> Kraj: {$store['country']}.";
    }
    if ($store['phone']) {
        $lines[] = "> Telefon: {$store['phone']}.";
    }
    if ($store['email']) {
        $lines[] = "> E-mail: {$store['email']}.";
    }
    $lines[] = "> Wygenerowano: {$now}.";
    $lines[] = "";

    // ── Sekcja: Kategorie ──
    $lines[] = "## Kategorie produktów";
    $lines[] = "";
    if (empty($categories)) {
        $lines[] = "Brak dostępnych kategorii.";
    } else {
        // Grupuj po poziomie 2 jako "działy"
        $grouped = [];
        foreach ($categories as $cat) {
            $grouped[$cat['level']][] = $cat;
        }
        foreach ($grouped as $level => $cats) {
            $indent = str_repeat('  ', max(0, $level - 2));
            foreach ($cats as $cat) {
                $lines[] = "{$indent}- [{$cat['name']}]({$cat['url']})";
            }
        }
    }
    $lines[] = "";

    // ── Sekcja: Strony CMS ──
    if (!empty($pages)) {
        $lines[] = "## Informacje i strony serwisu";
        $lines[] = "";
        foreach ($pages as $page) {
            $lines[] = "### {$page['title']}";
            if (!empty($page['description'])) {
                $lines[] = "";
                $lines[] = $page['description'];
            }
            $lines[] = "";
            $lines[] = "URL: {$page['url']}";
            $lines[] = "";
        }
    }

    // ── Sekcja: Produkty ──
    $lines[] = "## Produkty";
    $lines[] = "";
    $lines[] = "Poniżej lista produktów dostępnych w sklepie (limit: " . count($products) . " pozycji).";
    $lines[] = "";
    foreach ($products as $p) {
        $lines[] = "### {$p['name']}";
        $lines[] = "";
        if (!empty($p['desc'])) {
            $lines[] = $p['desc'];
            $lines[] = "";
        }
        $meta = [];
        if ($p['sku'])   $meta[] = "SKU: {$p['sku']}";
        if ($p['price']) $meta[] = "Cena: {$p['price']} {$store['currency']}";
        if ($p['url'])   $meta[] = "URL: {$p['url']}";
        if ($meta) {
            $lines[] = implode(' | ', $meta);
            $lines[] = "";
        }
    }

    // ── Stopka ──
    $lines[] = "---";
    $lines[] = "";
    $lines[] = "Plik wygenerowany automatycznie przez generator llms.txt dla Magento 2.";
    $lines[] = "Data generacji: {$now}";
    $lines[] = "Sklep: {$url}";
    $lines[] = "";

    return implode("\n", $lines);
}

// ─── MAIN ────────────────────────────────────────────────────────────────────

try {
    echo "Łączenie z bazą danych...\n";
    $db = getDb($dbConfig);

    echo "Pobieranie konfiguracji sklepu...\n";
    $store = getStoreInfo($db, $dbConfig['prefix']);

    if (empty($store['url'])) {
        throw new RuntimeException("Nie można odczytać base_url ze sklepu. Sprawdź konfigurację.");
    }

    echo "Baza URL sklepu: {$store['url']}\n";

    echo "Pobieranie kategorii...\n";
    $categories = getCategories($db, $dbConfig['prefix'], $store['url']);
    echo "  -> Znaleziono kategorii: " . count($categories) . "\n";

    echo "Pobieranie produktów (max 500)...\n";
    $products = getProducts($db, $dbConfig['prefix'], $store['url'], 500);
    echo "  -> Znaleziono produktów: " . count($products) . "\n";

    echo "Pobieranie stron CMS...\n";
    $pages = getCmsPages($db, $dbConfig['prefix'], $store['url']);
    echo "  -> Znaleziono stron CMS: " . count($pages) . "\n";

    echo "Generowanie llms.txt...\n";
    $content = buildLlmsTxt($store, $categories, $products, $pages);

    // Upewnij się że katalog docelowy istnieje
    $dir = dirname($outputFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    file_put_contents($outputFile, $content);
    $size = number_format(strlen($content) / 1024, 1);
    echo "\n✓ Plik zapisany: {$outputFile} ({$size} KB)\n";
    echo "  Dostępny pod: {$store['url']}/llms.txt\n\n";

} catch (PDOException $e) {
    echo "\n✗ Błąd bazy danych: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    echo "\n✗ Błąd: " . $e->getMessage() . "\n";
    exit(1);
}
