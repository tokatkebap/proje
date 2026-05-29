<?php
/**
 * api/ingredients.php - Malzeme (Envanter) CRUD API
 * 
 * Bu dosya lavaş, çiğköfte harcı, sos, poşet gibi restoran ham maddelerinin 
 * eklenmesi, listelenmesi, güncellenmesi ve silinmesi işlemlerini yönetir.
 */

$subPath = str_replace('/api/ingredients', '', $path);
$subPath = rtrim($subPath, '/');

// -------------------------------------------------------------
// TÜM MALZEMELERİ LİSTELEME (Giriş yapan her personel görebilir)
// GET /api/ingredients
// -------------------------------------------------------------
if ($method === 'GET' && empty($subPath)) {
    requireLogin();
    
    $ingredients = $db->query("SELECT * FROM ingredients ORDER BY name ASC")->fetchAll();
    
    echo json_encode([
        "success" => true,
        "data" => $ingredients
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// -------------------------------------------------------------
// TEK BİR MALZEME DETAYINI ÇEKME
// GET /api/ingredients/<id>
// -------------------------------------------------------------
if ($method === 'GET' && preg_match('/^\/(\d+)$/', $subPath, $matches)) {
    requireLogin();
    $ingredientId = (int)$matches[1];
    
    $stmt = $db->prepare("SELECT * FROM ingredients WHERE id = :id");
    $stmt->execute([':id' => $ingredientId]);
    $ingredient = $stmt->fetch();
    
    if ($ingredient) {
        echo json_encode([
            "success" => true,
            "data" => $ingredient
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Malzeme bulunamadı!"
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// -------------------------------------------------------------
// BUNDAN SONRAKİ İŞLEMLER (EKLEME, GÜNCELLEME, SİLME) ADMIN YETKİSİ GEREKTİRİR!
// -------------------------------------------------------------
requireAdmin();

// -------------------------------------------------------------
// YENİ ENVANTER MALZEMESİ EKLEME
// POST /api/ingredients
// -------------------------------------------------------------
if ($method === 'POST' && empty($subPath)) {
    $name = trim($inputData['name'] ?? '');
    $unit = trim($inputData['unit'] ?? 'gram'); // Örn: gram, adet, ml
    $stockQuantity = (float)($inputData['stock_quantity'] ?? 0.0); // Başlangıç stok miktarı

    if (empty($name) || empty($unit)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Eksik bilgi! Lütfen malzeme adı (name) ve birimi (unit) girin."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $stmt = $db->prepare("INSERT INTO ingredients (name, unit, stock_quantity) VALUES (:name, :unit, :stock_quantity)");
        $stmt->execute([
            ':name' => $name,
            ':unit' => $unit,
            ':stock_quantity' => $stockQuantity
        ]);

        echo json_encode([
            "success" => true,
            "message" => "Yeni malzeme başarıyla envantere eklendi!",
            "data" => [
                "id" => $db->lastInsertId(),
                "name" => $name,
                "unit" => $unit,
                "stock_quantity" => $stockQuantity
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Bu isimde bir malzeme envanterde zaten mevcut!"
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// -------------------------------------------------------------
// MALZEME DETAYLARINI VE STOK MİKTARINI GÜNCELLEME
// PUT /api/ingredients/<id>
// -------------------------------------------------------------
if ($method === 'PUT' && preg_match('/^\/(\d+)$/', $subPath, $matches)) {
    $ingredientId = (int)$matches[1];

    // Malzeme var mı kontrol et
    $stmt = $db->prepare("SELECT id FROM ingredients WHERE id = :id");
    $stmt->execute([':id' => $ingredientId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Güncellenecek malzeme bulunamadı!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $name = trim($inputData['name'] ?? '');
    $unit = trim($inputData['unit'] ?? '');
    $stockQuantity = isset($inputData['stock_quantity']) ? (float)$inputData['stock_quantity'] : null;

    $updateFields = [];
    $params = [':id' => $ingredientId];

    if (!empty($name)) {
        $updateFields[] = "name = :name";
        $params[':name'] = $name;
    }
    if (!empty($unit)) {
        $updateFields[] = "unit = :unit";
        $params[':unit'] = $unit;
    }
    if ($stockQuantity !== null) {
        $updateFields[] = "stock_quantity = :stock_quantity";
        $params[':stock_quantity'] = $stockQuantity;
    }

    if (empty($updateFields)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Güncellenecek herhangi bir alan belirtilmedi!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $sql = "UPDATE ingredients SET " . implode(", ", $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            "success" => true,
            "message" => "Malzeme başarıyla güncellendi!"
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Güncelleme başarısız! Bu isimde başka bir malzeme olabilir."
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// -------------------------------------------------------------
// MALZEMEYİ ENVANTERDEN TAMAMEN SİLME
// DELETE /api/ingredients/<id>
// -------------------------------------------------------------
if ($method === 'DELETE' && preg_match('/^\/(\d+)$/', $subPath, $matches)) {
    $ingredientId = (int)$matches[1];

    // Malzeme var mı kontrol et
    $stmt = $db->prepare("SELECT id FROM ingredients WHERE id = :id");
    $stmt->execute([':id' => $ingredientId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Silinecek malzeme bulunamadı!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Malzemeyi sil (CASCADE sayesinde, bu malzemeyi kullanan reçete satırları da otomatik silinir)
    $stmt = $db->prepare("DELETE FROM ingredients WHERE id = :id");
    $stmt->execute([':id' => $ingredientId]);

    echo json_encode([
        "success" => true,
        "message" => "Malzeme ve bu malzemeyi içeren reçete tanımları başarıyla envanterden silindi!"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
