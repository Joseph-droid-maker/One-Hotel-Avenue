<?php
/**
 * import.php — Bulk product import via Excel (.xlsx / .xls)
 *
 * Replaces the old CSV-based import. Uses PhpSpreadsheet to:
 *   1. Parse the uploaded Excel workbook directly (no client-side conversion).
 *   2. Extract embedded per-row images from the drawing collection.
 *   3. Auto-create any category that doesn't already exist in the DB.
 *   4. Insert or update products according to the chosen mode (skip | overwrite).
 *
 * Concepts / technologies used:
 *   - PhpSpreadsheet IOFactory       – format-agnostic Excel reader (xlsx & xls)
 *   - PhpSpreadsheet MemoryDrawing   – in-memory GD resource from embedded images
 *   - PHP GD library                 – renders MemoryDrawing resource to raw bytes
 *   - MySQLi prepared statements     – fully parameterised (SQL-injection proof)
 *   - LAST_INSERT_ID(id) MySQL trick – recovers existing PK after ON DUPLICATE KEY UPDATE
 *   - In-memory category cache array – avoids repeated SELECT/INSERT per row (O(1) lookup)
 *   - $isInsertStatement flag        – correctly separates inserted vs updated counters
 */

require_once __DIR__ . '/../../../vendor/autoload.php'; // Composer PSR-4 autoloader (PhpSpreadsheet)
require_once __DIR__ . '/../../config/cors.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

requireAdmin();
verifyCsrf();

// ── 1. Request validation ─────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed.', 405);
}

// The frontend now sends the file under the key 'excel' (was 'csv')
if (empty($_FILES['excel'])) {
    respondError('No file uploaded.');
}

// Validate import mode; falls back to safe default 'skip'
$mode = in_array($_POST['mode'] ?? '', ['skip', 'overwrite'], true)
    ? $_POST['mode']
    : 'skip';

$file = $_FILES['excel'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    respondError('Upload failed. Error code: ' . $file['error']);
}

// Fast-fail on wrong extension before invoking PhpSpreadsheet
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['xlsx', 'xls'], true)) {
    respondError('Only .xlsx and .xls files are accepted.');
}

// ── 2. Load workbook and extract sheet data ───────────────────────────────────

try {
    // IOFactory::load() detects xlsx vs xls from file content, not extension
    $spreadsheet = IOFactory::load($file['tmp_name']);
} catch (\Exception $e) {
    respondError('Could not read Excel file: ' . $e->getMessage(), 500);
}

// Always operate on the first (active) sheet
$sheet = $spreadsheet->getActiveSheet();

// Dump the full sheet to a 0-indexed 2D array.
// $allRows[0] = header row; $allRows[N] = data row N (0-based).
// Columns are also 0-indexed integers.
// Parameters: nullValue=null, calculateFormulas=true, formatData=false, returnCellRef=false
$allRows = $sheet->toArray(null, true, false, false);

if (empty($allRows)) {
    respondError('The spreadsheet is empty.');
}

// ── 3. Extract embedded images, keyed by 0-based array row index ─────────────
//
// Two strategies run in sequence. Strategy A handles standard Excel/LibreOffice
// files via PhpSpreadsheet's drawing collection. Strategy B handles WPS Office,
// which uses a proprietary xl/cellimages.xml format that PhpSpreadsheet does not
// understand — so we crack open the ZIP and parse it ourselves.
//
// Excel uses 1-based row numbers; $allRows uses 0-based indices:
//   Excel row 1  →  $allRows[0]  (header)
//   Excel row N  →  $allRows[N-1]

$imagesByRowIndex = []; // [int $arrayIndex => ['data' => string, 'ext' => string]]

// ── Strategy A: PhpSpreadsheet MemoryDrawing (standard Excel / LibreOffice) ──
//
// Images embedded as drawings are exposed as MemoryDrawing objects. Each one
// carries a GD resource and an anchor cell coordinate (e.g. "H5") that we parse
// to get the row number.

foreach ($sheet->getDrawingCollection() as $drawing) {

    if (!($drawing instanceof MemoryDrawing)) {
        continue;
    }

    preg_match('/(\d+)$/', $drawing->getCoordinates(), $matches);
    if (empty($matches[1])) continue;

    $arrayIdx = (int) $matches[1] - 1; // 1-based Excel row → 0-based array index

    if ($arrayIdx === 0 || isset($imagesByRowIndex[$arrayIdx])) continue;

    $gdResource = $drawing->getImageResource();
    if (!($gdResource instanceof \GdImage) && !is_resource($gdResource)) continue;

    $mimeType = $drawing->getMimeType();

    ob_start();
    $renderOk = match ($mimeType) {
        'image/jpeg' => imagejpeg($gdResource, null, 90),
        'image/gif'  => imagegif($gdResource),
        default      => imagepng($gdResource),
    };
    $imageData = ob_get_clean();

    if (!$renderOk || $imageData === '' || $imageData === false) continue;
    if (strlen($imageData) > 5 * 1024 * 1024) continue;

    $imagesByRowIndex[$arrayIdx] = [
        'data' => $imageData,
        'ext'  => match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/gif'  => 'gif',
            default      => 'png',
        },
    ];
}

// ── Strategy B: WPS cellimages.xml (fires only when Strategy A finds nothing) ─
//
// WPS Office saves cell images in xl/cellimages.xml using its own namespace
// (http://www.wps.cn/...) — completely invisible to PhpSpreadsheet. The file
// stores each image's position as (x, y) in EMUs with no direct cell reference.
// We determine the row by accumulating row heights from the sheet XML until the
// cumulative total surpasses the image's Y offset — that row owns the image.
//
// EMU (English Metric Unit): 1 inch = 914400 EMUs, 1 pt = 12700 EMUs.
// Default Excel/WPS row height: 15pt = 190500 EMUs.

if (empty($imagesByRowIndex)) {

    $zip = new ZipArchive();

    if ($zip->open($file['tmp_name']) === true) {

        // ── B1. Parse xl/_rels/cellimages.xml.rels → rId → ZIP media path ────
        //
        // Example entry: Id="rId1" Target="media/image1.jpeg"
        // We prefix "xl/" to resolve it to the full ZIP-internal path.

        $rIdToMedia = []; // ['rId1' => 'xl/media/image1.jpeg']

        $relsRaw = $zip->getFromName('xl/_rels/cellimages.xml.rels');
        if ($relsRaw) {
            $relsDoc = new SimpleXMLElement($relsRaw);
            foreach ($relsDoc->Relationship as $rel) {
                $rId    = (string) $rel['Id'];
                $target = ltrim((string) $rel['Target'], '/'); // strip any leading slash
                $rIdToMedia[$rId] = 'xl/' . $target;
            }
        }

        // ── B2. Parse xl/cellimages.xml → rId + Y offset per image ───────────
        //
        // Structure (abbreviated):
        //   <etc:cellImages>
        //     <etc:cellImage>
        //       <xdr:pic>
        //         <xdr:blipFill><a:blip r:embed="rId1"/></xdr:blipFill>
        //         <xdr:spPr><a:xfrm><a:off x="..." y="209550"/></a:xfrm></xdr:spPr>
        //       </xdr:pic>
        //     </etc:cellImage>
        //   </etc:cellImages>

        $imagePositions = []; // [['rId' => string, 'y' => int]]

        $ciRaw = $zip->getFromName('xl/cellimages.xml');
        if ($ciRaw) {
            $ciDoc = new SimpleXMLElement($ciRaw);

            // Namespace URIs — needed for attribute() calls and XPath registration
            $ns_etc = 'http://www.wps.cn/officeDocument/2017/etCustomData';
            $ns_a   = 'http://schemas.openxmlformats.org/drawingml/2006/main';
            $ns_r   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

            $ciDoc->registerXPathNamespace('etc', $ns_etc);
            $ciDoc->registerXPathNamespace('a',   $ns_a);

            foreach ($ciDoc->xpath('//etc:cellImage') as $cellImage) {

                // a:blip carries r:embed — the relationship ID linking to the file
                $blips = $cellImage->xpath('.//a:blip');
                if (empty($blips)) continue;

                // attributes() requires the full namespace URI, not the prefix
                $rAttrs = $blips[0]->attributes($ns_r);
                $rId    = (string) ($rAttrs['embed'] ?? '');
                if (!$rId) continue;

                // a:off y is the image's top-left Y offset from the sheet top, in EMUs
                $offs = $cellImage->xpath('.//a:xfrm/a:off');
                if (empty($offs)) continue;

                $imagePositions[] = [
                    'rId' => $rId,
                    'y'   => (int) ($offs[0]['y'] ?? 0),
                ];
            }
        }

        // ── B3. Parse sheet XML for actual row heights ────────────────────────
        //
        // The <row r="N" ht="15"> attribute gives height in points.
        // Rows with no explicit ht attribute use the sheet default (15pt).
        // We build a 1-based map so the row-walk in B4 can look up any row.

        $defaultRowHeightEmu = 190500; // 15pt × 12700 EMUs/pt
        $rowHeights          = [];     // [1-based row number => EMUs]

        $sheetPath = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (preg_match('/^xl\/worksheets\/sheet\d+\.xml$/', $entry)) {
                $sheetPath = $entry;
                break; // use the first sheet
            }
        }

        if ($sheetPath) {
            $sheetRaw = $zip->getFromName($sheetPath);
            if ($sheetRaw) {
                $sheetDoc = new SimpleXMLElement($sheetRaw);
                $sheetDoc->registerXPathNamespace(
                    's', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
                );
                foreach ($sheetDoc->xpath('//s:row') as $rowEl) {
                    $rowNum = (int) $rowEl['r'];
                    // ht is in points; missing = sheet default 15pt
                    $htPts  = isset($rowEl['ht']) ? (float) $rowEl['ht'] : 15.0;
                    $rowHeights[$rowNum] = (int) round($htPts * 12700);
                }
            }
        }

        // ── B4. Walk row heights to map each image's Y offset to a row ────────
        //
        // We sum row heights starting from row 1. The moment the running total
        // exceeds the image's Y EMU value, we've found the row the image is in.
        // Example: default 15pt rows (190500 EMU each)
        //   Row 1: 0 – 190499 EMU  (header)
        //   Row 2: 190500 – 380999 EMU  ← y=209550 lands here → arrayIdx = 1

        foreach ($imagePositions as $imgPos) {

            if (!isset($rIdToMedia[$imgPos['rId']])) continue;

            $yEmu      = $imgPos['y'];
            $cumHeight = 0;
            $rowNumber = 1;

            while ($rowNumber < 10000) { // 10000-row safety cap
                $rowH = $rowHeights[$rowNumber] ?? $defaultRowHeightEmu;
                if ($yEmu < $cumHeight + $rowH) break; // image top is inside this row
                $cumHeight += $rowH;
                $rowNumber++;
            }

            $arrayIdx = $rowNumber - 1; // 1-based row → 0-based $allRows index

            if ($arrayIdx === 0 || isset($imagesByRowIndex[$arrayIdx])) continue;

            // Read raw bytes straight from the ZIP — no GD conversion needed
            $imageData = $zip->getFromName($rIdToMedia[$imgPos['rId']]);
            if ($imageData === false || $imageData === '') continue;
            if (strlen($imageData) > 5 * 1024 * 1024) continue; // 5 MB cap

            $imgExt = strtolower(pathinfo($rIdToMedia[$imgPos['rId']], PATHINFO_EXTENSION));
            if ($imgExt === 'jpeg') $imgExt = 'jpg';
            if (!in_array($imgExt, ['jpg', 'png', 'gif', 'webp'], true)) continue;

            $imagesByRowIndex[$arrayIdx] = [
                'data' => $imageData,
                'ext'  => $imgExt,
            ];
        }

        $zip->close();
    }
}

// ── 4. Map flexible column headers to canonical field names ──────────────────

// Normalise the header row for case-insensitive alias matching
$header = array_map(fn($h) => strtolower(trim((string) $h)), $allRows[0]);

// Each key is the canonical field name used in this script.
// The array lists all header names we consider equivalent (first match wins).
$colAliases = [
    'name'        => ['item name', 'name', 'product name', 'item', 'product'],
    'description' => ['description/size', 'description', 'size', 'desc', 'variant'],
    'stock'       => ['current stock', 'stock', 'current', 'qty', 'quantity'],
    'cost_price'  => ['cost price', 'cost', 'buying price', 'purchase price', 'cp'],
    'price'       => ['retail price', 'retail', 'selling price', 'price', 'rp', 'srp'],
    'sku'         => ['sku', 'barcode', 'code', 'item code'],
    'category'    => ['category', 'cat', 'type', 'group'],
    // 'image' column is recognised so it doesn't appear in unknown-header errors,
    // but its cell value is intentionally ignored — image data comes from
    // the drawing collection built in step 3, not from cell text.
    'image'       => ['image', 'photo', 'picture', 'img'],
];

$col = []; // canonical field name → 0-based column index
foreach ($colAliases as $field => $aliases) {
    foreach ($aliases as $alias) {
        $idx = array_search($alias, $header, true);
        if ($idx !== false) {
            $col[$field] = $idx;
            break; // stop at first matched alias for this field
        }
    }
}

// Only name and price are mandatory; everything else degrades gracefully
if (!isset($col['name'])) {
    respondError('Could not find a name column. Headers found: ' . implode(', ', $header));
}
if (!isset($col['price'])) {
    respondError('Could not find a price column. Headers found: ' . implode(', ', $header));
}

// ── 5. Load existing categories into an in-memory cache ──────────────────────
//
// Keying by lowercase name lets us do case-insensitive lookups without extra
// queries and also deduplicate new-category INSERTs within the same import.

$db = getDB();

$categories = []; // [string $lowercaseName => int $id]
$catResult  = $db->query("SELECT id, LOWER(TRIM(name)) AS name FROM categories");
while ($catRow = $catResult->fetch_assoc()) {
    $categories[$catRow['name']] = (int) $catRow['id'];
}

// ── 6. Ensure upload directory exists (same path as upload.php) ──────────────

$uploadDir = __DIR__ . '/../../uploads/products/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// ── 7. Process each data row ──────────────────────────────────────────────────

$inserted = 0;
$updated  = 0;
$skipped  = 0;
$errors   = [];

foreach ($allRows as $idx => $row) {

    if ($idx === 0) {
        continue; // header row — skip
    }

    $rowNum = $idx + 1; // 1-based Excel row number for human-readable error messages

    // ── 7a. Extract and sanitise field values ─────────────────────────────────

    $name = trim((string) ($row[$col['name']] ?? ''));
    if ($name === '') {
        $skipped++;
        continue; // silently skip blank-name rows (trailing empty rows are common)
    }

    // Closure strips ₱, thousands commas, and spaces before numeric casting
    $cleanNum = static fn($v) => str_replace(['₱', ',', ' '], '', (string) $v);

    $price     = floatval($cleanNum($row[$col['price']]           ?? 0));
    $costPrice = isset($col['cost_price'])
                   ? floatval($cleanNum($row[$col['cost_price']]  ?? 0)) : 0.0;
    $stock     = isset($col['stock'])
                   ? intval($row[$col['stock']]                   ?? 0)  : 0;
    $sku       = isset($col['sku'])
                   ? trim((string) ($row[$col['sku']]             ?? '')) : '';
    $desc      = isset($col['description'])
                   ? trim((string) ($row[$col['description']]     ?? '')) : '';

    // Keep original casing for DB insertion; normalise for cache lookup
    $catNameRaw = isset($col['category'])
                    ? trim((string) ($row[$col['category']]       ?? '')) : '';
    $catNameKey = strtolower($catNameRaw);

    // ── 7b. Validate ──────────────────────────────────────────────────────────

    if ($price <= 0) {
        $errors[] = "Row $rowNum ($name): invalid price.";
        $skipped++;
        continue;
    }

    if ($stock < 0) {
        $errors[] = "Row $rowNum ($name): negative stock ($stock) is not allowed.";
        $skipped++;
        continue;
    }

    // ── 7c. Category: auto-create if it doesn't exist in the DB ──────────────
    //
    // The in-memory $categories cache prevents duplicate INSERTs when multiple
    // rows share the same new category name within one import batch.

    $catId = null;

    if ($catNameRaw !== '') {
        if (!isset($categories[$catNameKey])) {
            // Category is genuinely new — INSERT it, preserving original casing
            $catIns = $db->prepare('INSERT INTO categories (name) VALUES (?)');
            $catIns->bind_param('s', $catNameRaw);
            $catIns->execute();
            $newCatId = $db->insert_id;
            $catIns->close();

            // Cache it immediately so later rows reuse this ID without another query
            $categories[$catNameKey] = $newCatId;
        }

        $catId = $categories[$catNameKey];
    }

    // ── 7d. Normalise optional string fields: empty string → null ─────────────
    //  Storing null instead of '' keeps DB values consistent with the UI path.

    $sku  = ($sku  !== '') ? $sku  : null;
    $desc = ($desc !== '') ? $desc : null;

    // ── 7e. Build the correct INSERT / UPDATE statement ───────────────────────
    //
    // $isInsertStatement distinguishes INSERT from plain UPDATE for the counter
    // logic in 7f. Without this flag, an UPDATE with affected_rows = 1 would
    // be wrongly counted as an INSERT (bug present in the original CSV version).

    $productId         = null;  // resolved after execute(); used for image assignment
    $isInsertStatement = true;  // overridden to false for plain UPDATE statements

    if ($sku !== null) {
        // ── SKU-keyed path ────────────────────────────────────────────────────

        if ($mode === 'skip') {
            // Check whether this SKU is already in the DB
            $chk = $db->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
            $chk->bind_param('s', $sku);
            $chk->execute();
            $existsBySku = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($existsBySku) {
                $skipped++;
                continue; // leave existing product untouched
            }

            // No duplicate — plain INSERT
            $stmt = $db->prepare(
                'INSERT INTO products (name, description, sku, price, cost_price, stock, category_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('sssddii', $name, $desc, $sku, $price, $costPrice, $stock, $catId);

        } else {
            // Overwrite: upsert keyed on the SKU unique index.
            //
            // The "id = LAST_INSERT_ID(id)" clause is the key trick here:
            // after an ON DUPLICATE KEY UPDATE, $db->insert_id normally returns 0.
            // Adding this clause makes MySQL set LAST_INSERT_ID to the existing
            // row's PK, so $db->insert_id correctly returns the product ID in
            // both the INSERT and the UPDATE branch — essential for image saving.
            $stmt = $db->prepare(
                'INSERT INTO products (name, description, sku, price, cost_price, stock, category_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   name        = VALUES(name),
                   description = VALUES(description),
                   price       = VALUES(price),
                   cost_price  = VALUES(cost_price),
                   stock       = stock + VALUES(stock),
                   category_id = VALUES(category_id),
                   is_active   = 1,
                   id          = LAST_INSERT_ID(id)'
            );
            $stmt->bind_param('sssddii', $name, $desc, $sku, $price, $costPrice, $stock, $catId);
        }

    } else {
        // ── Name-keyed path (no SKU provided) ────────────────────────────────

        // Find an active product whose name matches case-insensitively
        $chk = $db->prepare(
            'SELECT id FROM products
              WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) AND is_active = 1
              LIMIT 1'
        );
        $chk->bind_param('s', $name);
        $chk->execute();
        $existingByName = $chk->get_result()->fetch_assoc();
        $chk->close();

        if ($mode === 'skip') {
            if ($existingByName) {
                $skipped++;
                continue;
            }

            $stmt = $db->prepare(
                'INSERT INTO products (name, description, price, cost_price, stock, category_id)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('ssddii', $name, $desc, $price, $costPrice, $stock, $catId);

        } else {
            if ($existingByName) {
                // We already know the PK — set it before execute() so we don't
                // need to rely on insert_id (which returns 0 for plain UPDATEs)
                $productId = (int) $existingByName['id'];

                // Flag as a plain UPDATE so 7f counts this as 'updated', not 'inserted'
                $isInsertStatement = false;

                $stmt = $db->prepare(
                    'UPDATE products
                     SET description = ?,
                         price       = ?,
                         cost_price  = ?,
                         stock       = stock + ?,
                         category_id = ?
                     WHERE id = ?'
                );
                $stmt->bind_param('sddiii', $desc, $price, $costPrice, $stock, $catId, $productId);

            } else {
                // No existing product found → INSERT as new
                $stmt = $db->prepare(
                    'INSERT INTO products (name, description, price, cost_price, stock, category_id)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('ssddii', $name, $desc, $price, $costPrice, $stock, $catId);
            }
        }
    }

    // ── 7f. Execute, resolve product ID, and update counters ─────────────────

    if (!$stmt->execute()) {
        $errors[] = "Row $rowNum ($name): " . $db->error;
        $skipped++;
        $stmt->close();
        continue;
    }

    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    // For INSERT paths and the SKU ON DUPLICATE KEY UPDATE path, the product ID
    // comes from insert_id. For the name-based plain UPDATE, $productId was
    // already set above and we skip the insert_id (which would return 0).
    if ($productId === null) {
        $productId = (int) $db->insert_id;
    }

    if ($isInsertStatement) {
        // affected_rows = 1 → new row inserted
        // affected_rows = 2 → ON DUPLICATE KEY UPDATE hit a duplicate and changed it
        // affected_rows = 0 → ON DUPLICATE KEY UPDATE found a duplicate but nothing changed
        if ($affectedRows === 1) {
            $inserted++;
        } else {
            $updated++; // covers both the "2" case and the "0" no-op case
        }
    } else {
        // Plain UPDATE on a known existing product — always counts as updated
        $updated++;
    }

    // ── 7g. Save the embedded image for this row (if one was found) ───────────

    if ($productId > 0 && isset($imagesByRowIndex[$idx])) {
        $img = $imagesByRowIndex[$idx];

        // Build a unique filename using the same pattern as upload.php
        $filename = uniqid('prod_', true) . '.' . $img['ext'];
        $destPath = $uploadDir . $filename;

        if (file_put_contents($destPath, $img['data']) !== false) {
            // Store the same relative path convention used by upload.php
            $relPath = 'uploads/products/' . $filename;
            $imgStmt = $db->prepare('UPDATE products SET image_path = ? WHERE id = ?');
            $imgStmt->bind_param('si', $relPath, $productId);
            $imgStmt->execute();
            $imgStmt->close();
        } else {
            $errors[] = "Row $rowNum ($name): image could not be written to disk.";
        }
    }
}

// ── 8. Clean up and respond ───────────────────────────────────────────────────

$db->close();

respond(true, [
    'inserted' => $inserted,
    'updated'  => $updated,
    'skipped'  => $skipped,
    'errors'   => $errors,
    'mode'     => $mode,
], "Import complete ({$mode} mode). Added: $inserted, Updated: $updated, Skipped: $skipped.");