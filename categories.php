<?php
/**
 * api/categories.php - Kategori CRUD API
 * 
 * Bu dosya yeni kategori oluşturma, listeleme, adını güncelleme ve 
 * kategoriyi tamamen silme işlemlerini yönetir.
 */

$subPath = str_replace('/api/categories', '', $path);
$subPath = rtrim($subPath, '/');

// -------------------------------------------------------------
// KATEGORİLERİ LİSTELEME (Giriş yapan her personel görebilir)
// GET /api/categories
// -------------------------------------------------------------
if ($method === 'GET' && empty($subPath)) {
    requireLogin(); // En azından giriş yapmış olmalı
    
    $categories = $db->query("SELECT * FROM categories ORDER BY id ASC")->fetchAll();
    
    echo json_encode([
        "success" => true,
        "data" => $categories
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// -------------------------------------------------------------
// BUNDAN SONRAKİ İŞLEMLER (EKLEME, GÜNCELLEME, SİLME) ADMIN YETKİSİ GEREKTİRİR!
// -------------------------------------------------------------
requireAdmin();

// -------------------------------------------------------------
// YENİ KATEGORİ EKLEME
// POST /api/categories
// -------------------------------------------------------------
if ($method === 'POST' && empty($subPath)) {
    $name = trim($inputData['name'] ?? '');

    if (empty($name)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Lütfen kategori adını (name) belirtin."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $stmt = $db->prepare("INSERT INTO categories (name) VALUES (:name)");
        $stmt->execute([':name' => $name]);

        echo json_encode([
            "success" => true,
            "message" => "Kategori başarıyla eklendi!",
            "data" => [
                "id" => $db->lastInsertId(),
                "name" => $name
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Bu kategori adı zaten mevcut!"
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// -------------------------------------------------------------
// KATEGORİ GÜNCELLEME
// PUT /api/categories/<id>
// -------------------------------------------------------------
if ($method === 'PUT' && preg_match('/^\/(\d+)$/', $subPath, $matches)) {
    $categoryId = (int)$matches[1];
    $name = trim($inputData['name'] ?? '');

    if (empty($name)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Lütfen güncellenecek yeni kategori adını (name) belirtin."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Kategori var mı kontrol et
    $stmt = $db->prepare("SELECT id FROM categories WHERE id = :id");
    $stmt->execute([':id' => $categoryId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Güncellenecek kategori bulunamadı!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $stmt = $db->prepare("UPDATE categories SET name = :name WHERE id = :id");
        $stmt->execute([
            ':name' => $name,
            ':id' => $categoryId
        ]);

        echo json_encode([
            "success" => true,
            "message" => "Kategori başarıyla güncellendi!"
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Bu kategori adı başka bir kategoride zaten mevcut!"
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// -------------------------------------------------------------
// KATEGORİ SİLME
// DELETE /api/categories/<id>
// -------------------------------------------------------------
if ($method === 'DELETE' && preg_match('/^\/(\d+)$/', $subPath, $matches)) {
    $categoryId = (int)$matches[1];

    // Kategori var mı kontrol et
    $stmt = $db->prepare("SELECT id FROM categories WHERE id = :id");
    $stmt->execute([':id' => $categoryId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Silinecek kategori bulunamadı!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Kategori silindiğinde SQLite ON DELETE CASCADE kuralı sayesinde o kategoriye ait ürünler de otomatik silinecektir.
    $stmt = $db->prepare("DELETE FROM categories WHERE id = :id");
    $stmt->execute([':id' => $categoryId]);

    echo json_encode([
        "success" => true,
        "message" => "Kategori ve kategoriye ait tüm ürünler başarıyla silindi!"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
