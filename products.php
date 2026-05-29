<?php
/**
 * api/products.php - Ürün & Ürün Boyutları CRUD API
 * 
 * Bu dosya yeni ürün ekleme, ürünlere boyut ve fiyat atama, 
 * ürün listeleme, güncelleme ve silme işlemlerini yönetir.
 */

$subPath = str_replace('/api/products', '', $path);
$subPath = rtrim($subPath, '/');

// -------------------------------------------------------------
// TÜM ÜRÜNLERİ LİSTELEME (Boyutları ve fiyatları ile birlikte)
// GET /api/products
// -------------------------------------------------------------
if ($method === 'GET' && empty($subPath)) {
    requireLogin(); // Giriş yapmış olmalı
    
    // Ürünleri kategorileriyle birlikte çekelim
    $products = $db->query("
        SELECT p.id, p.name, p.description, p.category_id, c.name as category_name 
        FROM products p 
        JOIN categories c ON p.category_id = c.id
        ORDER BY p.id DESC
    ")->fetchAll();
    
    // Her ürün için alt boyut ve fiyat bilgilerini ekleyelim
    foreach ($products as &$product) {
        $stmt = $db->prepare("SELECT id, size_name, price FROM product_sizes WHERE product_id = :product_id");
        $stmt->execute([':product_id' => $product['id']]);
        $product['sizes'] = $stmt->fetchAll();
    }
    
    echo json_encode([
        "success" => true,
        "data" => $products
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// -------------------------------------------------------------
// TEK BİR ÜRÜNÜN DETAYINI VE BOYUTLARINI ÇEKME
// GET /api/products/<id>
// -------------------------------------------------------------
if ($method === 'GET' && preg_match('/^\/(\d+)$/', $subPath, $matches)) {
    requireLogin();
    $productId = (int)$matches[1];
    
    $stmt = $db->prepare("
        SELECT p.id, p.name, p.description, p.category_id, c.name as category_name 
        FROM products p 
        JOIN categories c ON p.category_id = c.id
        WHERE p.id = :id
    ");
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch();
    
    if ($product) {
        $stmtSize = $db->prepare("SELECT id, size_name, price FROM product_sizes WHERE product_id = :product_id");
        $stmtSize->execute([':product_id' => $productId]);
        $product['sizes'] = $stmtSize->fetchAll();
        
        echo json_encode([
            "success" => true,
            "data" => $product
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Ürün bulunamadı!"
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// -------------------------------------------------------------
// BUNDAN SONRAKİ İŞLEMLER (EKLEME, GÜNCELLEME, SİLME) ADMIN YETKİSİ GEREKTİRİR!
// -------------------------------------------------------------
requireAdmin();

// -------------------------------------------------------------
// YENİ ÜRÜN VE BOYUTLARINI EKLEME
// POST /api/products
// -------------------------------------------------------------
if ($method === 'POST' && empty($subPath)) {
    $name = trim($inputData['name'] ?? '');
    $categoryId = (int)($inputData['category_id'] ?? 0);
    $description = trim($inputData['description'] ?? '');
    $sizes = $inputData['sizes'] ?? []; // Array formatında boyutlar: [{"size_name": "Dürüm", "price": 80}, ...]

    // Gerekli alanların kontrolü
    if (empty($name) || $categoryId <= 0) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Eksik bilgi! Lütfen ürün adı (name) ve kategori ID'si (category_id) alanlarını doldurun."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Kategori gerçekten var mı kontrol et
    $stmt = $db->prepare("SELECT id FROM categories WHERE id = :id");
    $stmt->execute([':id' => $categoryId]);
    if (!$stmt->fetch()) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Geçersiz kategori ID'si! Önce kategoriyi oluşturmalısınız."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        // Hata durumunda işlemi geri almak için veritabanı işlemini (Transaction) başlatalım
        $db->beginTransaction();

        // 1. Ürünü ekle
        $stmt = $db->prepare("INSERT INTO products (name, category_id, description) VALUES (:name, :category_id, :description)");
        $stmt->execute([
            ':name' => $name,
            ':category_id' => $categoryId,
            ':description' => $description
        ]);
        
        $productId = (int)$db->lastInsertId();

        // 2. Eğer boyut ve fiyatlar da gönderildiyse ekle
        $addedSizes = [];
        if (!empty($sizes) && is_array($sizes)) {
            $stmtSize = $db->prepare("INSERT INTO product_sizes (product_id, size_name, price) VALUES (:product_id, :size_name, :price)");
            foreach ($sizes as $sizeItem) {
                $sizeName = trim($sizeItem['size_name'] ?? '');
                $price = (float)($sizeItem['price'] ?? 0.0);
                
                if (!empty($sizeName) && $price >= 0) {
                    $stmtSize->execute([
                        ':product_id' => $productId,
                        ':size_name' => $sizeName,
                        ':price' => $price
                    ]);
                    $addedSizes[] = [
                        "id" => $db->lastInsertId(),
                        "size_name" => $sizeName,
                        "price" => $price
                    ];
                }
            }
        }

        // Değişiklikleri kalıcı yap
        $db->commit();

        echo json_encode([
            "success" => true,
            "message" => "Ürün ve boyut tanımları başarıyla eklendi!",
            "data" => [
                "id" => $productId,
                "name" => $name,
                "category_id" => $categoryId,
                "description" => $description,
                "sizes" => $addedSizes
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        // Hata durumunda yapılan tüm eklemeleri geri al
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Ürün eklenirken bir veritabanı hatası oluştu: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// -------------------------------------------------------------
// ÜRÜN BİLGİLERİNİ VE BOYUTLARINI GÜNCELLEME
// PUT /api/products/<id>
// -------------------------------------------------------------
if ($method === 'PUT' && preg_match('/^\/(\d+)$/', $subPath, $matches)) {
    $productId = (int)$matches[1];

    // Ürün var mı kontrol et
    $stmt = $db->prepare("SELECT id FROM products WHERE id = :id");
    $stmt->execute([':id' => $productId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Güncellenecek ürün bulunamadı!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $name = trim($inputData['name'] ?? '');
    $categoryId = isset($inputData['category_id']) ? (int)$inputData['category_id'] : null;
    $description = isset($inputData['description']) ? trim($inputData['description']) : null;
    $sizes = $inputData['sizes'] ?? null; // Eğer null ise boyutlara dokunmayacağız

    try {
        $db->beginTransaction();

        // 1. Ürün temel bilgilerini güncelle
        $updateFields = [];
        $params = [':id' => $productId];

        if (!empty($name)) {
            $updateFields[] = "name = :name";
            $params[':name'] = $name;
        }
        if ($categoryId !== null && $categoryId > 0) {
            // Kategori geçerli mi kontrol et
            $stmtCat = $db->prepare("SELECT id FROM categories WHERE id = :id");
            $stmtCat->execute([':id' => $categoryId]);
            if ($stmtCat->fetch()) {
                $updateFields[] = "category_id = :category_id";
                $params[':category_id'] = $categoryId;
            }
        }
        if ($description !== null) {
            $updateFields[] = "description = :description";
            $params[':description'] = $description;
        }

        if (!empty($updateFields)) {
            $sql = "UPDATE products SET " . implode(", ", $updateFields) . " WHERE id = :id";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
        }

        // 2. Eğer boyut dizisi gönderildiyse güncelle (Akıllı eşleştirme ve güncelleme logic'i)
        if ($sizes !== null && is_array($sizes)) {
            // Mevcut boyutları veritabanından çekelim
            $stmtExist = $db->prepare("SELECT id, size_name FROM product_sizes WHERE product_id = :product_id");
            $stmtExist->execute([':product_id' => $productId]);
            $existingSizes = $stmtExist->fetchAll();
            $existingSizesByName = array_column($existingSizes, 'id', 'size_name');

            // Gelen istekte bulunan boyut isimleri listesi
            $requestedSizeNames = [];

            $stmtInsert = $db->prepare("INSERT INTO product_sizes (product_id, size_name, price) VALUES (:product_id, :size_name, :price)");
            $stmtUpdate = $db->prepare("UPDATE product_sizes SET price = :price WHERE id = :id");

            foreach ($sizes as $sizeItem) {
                $sizeName = trim($sizeItem['size_name'] ?? '');
                $price = (float)($sizeItem['price'] ?? 0.0);
                
                if (empty($sizeName) || $price < 0) {
                    continue;
                }
                $requestedSizeNames[] = $sizeName;

                if (isset($existingSizesByName[$sizeName])) {
                    // Zaten var, sadece fiyatını güncelleyelim (Böylece reçete ve sipariş ilişkisi kopmaz!)
                    $stmtUpdate->execute([
                        ':price' => $price,
                        ':id' => $existingSizesByName[$sizeName]
                    ]);
                } else {
                    // Yeni boyut, sisteme ekleyelim
                    $stmtInsert->execute([
                        ':product_id' => $productId,
                        ':size_name' => $sizeName,
                        ':price' => $price
                    ]);
                }
            }

            // İstekte gönderilmeyen ama veritabanında olan eski boyutları silelim
            $stmtDelete = $db->prepare("DELETE FROM product_sizes WHERE id = :id");
            foreach ($existingSizesByName as $name => $id) {
                if (!in_array($name, $requestedSizeNames)) {
                    $stmtDelete->execute([':id' => $id]);
                }
            }
        }

        $db->commit();

        echo json_encode([
            "success" => true,
            "message" => "Ürün bilgileri ve boyutları başarıyla güncellendi!"
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Güncelleme sırasında hata oluştu: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// -------------------------------------------------------------
// ÜRÜNÜ TAMAMEN SİLME
// DELETE /api/products/<id>
// -------------------------------------------------------------
if ($method === 'DELETE' && preg_match('/^\/(\d+)$/', $subPath, $matches)) {
    $productId = (int)$matches[1];

    // Ürün var mı kontrol et
    $stmt = $db->prepare("SELECT id FROM products WHERE id = :id");
    $stmt->execute([':id' => $productId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Silinecek ürün bulunamadı!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Ürünü sil (ON DELETE CASCADE kısıtlaması sayesinde, o ürüne bağlı tüm boyutlar 
    // ve o boyutlara ait reçeteler de veritabanından otomatik silinir!)
    $stmt = $db->prepare("DELETE FROM products WHERE id = :id");
    $stmt->execute([':id' => $productId]);

    echo json_encode([
        "success" => true,
        "message" => "Ürün ve ürüne ait tüm boyut ve reçeteler başarıyla silindi!"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
