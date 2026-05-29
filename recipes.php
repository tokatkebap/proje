<?php
/**
 * api/recipes.php - Reçete Tanımlama ve Yönetim API
 * 
 * Bu dosya hangi ürün boyutunun hangi envanter malzemesinden ne kadar (gram/adet/ml) 
 * eksilteceğini tanımlayan reçete motorudur.
 */

$subPath = str_replace('/api/recipes', '', $path);
$subPath = rtrim($subPath, '/');

// -------------------------------------------------------------
// TÜM REÇETELERİ GÖRÜNTÜLEME
// GET /api/recipes
// -------------------------------------------------------------
if ($method === 'GET' && empty($subPath)) {
    requireLogin();
    
    // Hangi ürün boyutlarının reçeteleri var, onları çekelim
    $recipes = $db->query("
        SELECT 
            r.id,
            r.product_size_id,
            p.name as product_name,
            ps.size_name,
            r.ingredient_id,
            i.name as ingredient_name,
            r.quantity,
            i.unit
        FROM recipes r
        JOIN product_sizes ps ON r.product_size_id = ps.id
        JOIN products p ON ps.product_id = p.id
        JOIN ingredients i ON r.ingredient_id = i.id
        ORDER BY p.name ASC, ps.size_name ASC
    ")->fetchAll();

    // Çıktıyı daha düzenli hale getirip, ürün boyutuna göre gruplayalım
    $groupedRecipes = [];
    foreach ($recipes as $row) {
        $sizeId = $row['product_size_id'];
        if (!isset($groupedRecipes[$sizeId])) {
            $groupedRecipes[$sizeId] = [
                "product_size_id" => $sizeId,
                "product_name" => $row['product_name'],
                "size_name" => $row['size_name'],
                "recipe_items" => []
            ];
        }
        $groupedRecipes[$sizeId]['recipe_items'][] = [
            "recipe_id" => $row['id'],
            "ingredient_id" => $row['ingredient_id'],
            "ingredient_name" => $row['ingredient_name'],
            "quantity" => $row['quantity'],
            "unit" => $row['unit']
        ];
    }
    
    echo json_encode([
        "success" => true,
        "data" => array_values($groupedRecipes)
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// -------------------------------------------------------------
// BELİRLİ BİR ÜRÜN BOYUTUNUN REÇETESİNİ ÇEKME
// GET /api/recipes/<product_size_id>
// -------------------------------------------------------------
if ($method === 'GET' && preg_match('/^\/(\d+)$/', $subPath, $matches)) {
    requireLogin();
    $productSizeId = (int)$matches[1];
    
    // Ürün boyutunun varlığını kontrol et
    $stmtSize = $db->prepare("
        SELECT ps.id, p.name as product_name, ps.size_name 
        FROM product_sizes ps
        JOIN products p ON ps.product_id = p.id
        WHERE ps.id = :id
    ");
    $stmtSize->execute([':id' => $productSizeId]);
    $sizeDetails = $stmtSize->fetch();
    
    if (!$sizeDetails) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Belirtilen ürün boyutu bulunamadı!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    
    // Bu boyuta ait reçete kalemlerini çekelim
    $stmtRecipe = $db->prepare("
        SELECT r.id as recipe_id, r.ingredient_id, i.name as ingredient_name, r.quantity, i.unit 
        FROM recipes r
        JOIN ingredients i ON r.ingredient_id = i.id
        WHERE r.product_size_id = :product_size_id
    ");
    $stmtRecipe->execute([':product_size_id' => $productSizeId]);
    $recipeItems = $stmtRecipe->fetchAll();
    
    echo json_encode([
        "success" => true,
        "product_size_id" => $productSizeId,
        "product_name" => $sizeDetails['product_name'],
        "size_name" => $sizeDetails['size_name'],
        "recipe" => $recipeItems
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// -------------------------------------------------------------
// BUNDAN SONRAKİ İŞLEMLER (REÇETE TANIMLAMA / GÜNCELLEME / SİLME) ADMIN YETKİSİ GEREKTİRİR!
// -------------------------------------------------------------
requireAdmin();

// -------------------------------------------------------------
// REÇETE TANIMLAMA VEYA GÜNCELLEME
// POST /api/recipes
// -------------------------------------------------------------
if ($method === 'POST' && empty($subPath)) {
    $productSizeId = (int)($inputData['product_size_id'] ?? 0);
    $ingredients = $inputData['ingredients'] ?? []; // [{"ingredient_id": 1, "quantity": 120.0}, ...]

    if ($productSizeId <= 0 || empty($ingredients) || !is_array($ingredients)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Eksik veya geçersiz veri! 'product_size_id' ve 'ingredients' dizisini göndermelisiniz."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Ürün boyutu geçerli mi kontrol et
    $stmtSize = $db->prepare("SELECT id FROM product_sizes WHERE id = :id");
    $stmtSize->execute([':id' => $productSizeId]);
    if (!$stmtSize->fetch()) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Geçersiz 'product_size_id'! Lütfen önce geçerli bir ürün boyutu oluşturun."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $db->beginTransaction();

        // 1. Mevcut reçete satırlarını sıfırlayalım (Kolay ve temiz güncelleme için)
        $stmtDelete = $db->prepare("DELETE FROM recipes WHERE product_size_id = :product_size_id");
        $stmtDelete->execute([':product_size_id' => $productSizeId]);

        // 2. Yeni reçete satırlarını ekleyelim
        $stmtInsert = $db->prepare("
            INSERT INTO recipes (product_size_id, ingredient_id, quantity) 
            VALUES (:product_size_id, :ingredient_id, :quantity)
        ");

        $addedItems = [];
        foreach ($ingredients as $item) {
            $ingredientId = (int)($item['ingredient_id'] ?? 0);
            $quantity = (float)($item['quantity'] ?? 0.0);

            if ($ingredientId <= 0 || $quantity <= 0) {
                // Hatalı satırları atlayalım veya hata fırlatalım
                continue;
            }

            // Malzeme veritabanında var mı kontrol et
            $stmtCheck = $db->prepare("SELECT name, unit FROM ingredients WHERE id = :id");
            $stmtCheck->execute([':id' => $ingredientId]);
            $ingInfo = $stmtCheck->fetch();

            if ($ingInfo) {
                $stmtInsert->execute([
                    ':product_size_id' => $productSizeId,
                    ':ingredient_id' => $ingredientId,
                    ':quantity' => $quantity
                ]);
                
                $addedItems[] = [
                    "ingredient_id" => $ingredientId,
                    "ingredient_name" => $ingInfo['name'],
                    "quantity" => $quantity,
                    "unit" => $ingInfo['unit']
                ];
            }
        }

        $db->commit();

        echo json_encode([
            "success" => true,
            "message" => "Reçete başarıyla tanımlandı ve güncellendi!",
            "product_size_id" => $productSizeId,
            "recipe" => $addedItems
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Reçete kaydedilirken veritabanı hatası oluştu: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// -------------------------------------------------------------
// BİR ÜRÜN BOYUTUNUN REÇETESİNİ TAMAMEN SİLME
// DELETE /api/recipes/<product_size_id>
// -------------------------------------------------------------
if ($method === 'DELETE' && preg_match('/^\/(\d+)$/', $subPath, $matches)) {
    $productSizeId = (int)$matches[1];

    // Reçete var mı kontrol et
    $stmt = $db->prepare("SELECT COUNT(*) FROM recipes WHERE product_size_id = :id");
    $stmt->execute([':id' => $productSizeId]);
    if ($stmt->fetchColumn() == 0) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Bu ürün boyutuna ait tanımlı bir reçete bulunamadı!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmt = $db->prepare("DELETE FROM recipes WHERE product_size_id = :id");
    $stmt->execute([':id' => $productSizeId]);

    echo json_encode([
        "success" => true,
        "message" => "Ürün boyutuna ait reçete başarıyla silindi (Ürün boyutu silinmedi, sadece içindeki malzeme eşleştirmeleri kaldırıldı)."
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
