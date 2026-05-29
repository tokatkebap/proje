<?php
/**
 * api/orders.php - Sipariş Oluşturma ve Otomatik Stok Düşme Motoru
 * 
 * Bu dosya yeni siparişleri kaydeder. Sipariş tamamlandığında satılan ürünlerin 
 * reçetelerine bakarak envanterden (lavaş, harç, poşet vb.) otomatik stok düşer.
 * Stok yetersizse siparişi bloke edip hangi malzemenin eksik olduğunu bildirir.
 */

$subPath = str_replace('/api/orders', '', $path);
$subPath = rtrim($subPath, '/');

// Sipariş işlemleri için herhangi bir personelin (kasiyer veya admin) oturum açmış olması gerekir
$currentUser = requireLogin();

// -------------------------------------------------------------
// SİPARİŞ GEÇMİŞİNİ LİSTELEME
// GET /api/orders
// -------------------------------------------------------------
if ($method === 'GET' && empty($subPath)) {
    // Son 50 siparişi alan kasiyer ve toplam tutarıyla listeleyelim
    $orders = $db->query("
        SELECT o.id, o.total_amount, o.created_at, u.full_name as cashier_name 
        FROM orders o
        JOIN users u ON o.user_id = u.id
        ORDER BY o.id DESC
        LIMIT 50
    ")->fetchAll();

    foreach ($orders as &$order) {
        $stmtItems = $db->prepare("
            SELECT oi.id, p.name as product_name, ps.size_name, oi.quantity, oi.unit_price 
            FROM order_items oi
            JOIN product_sizes ps ON oi.product_size_id = ps.id
            JOIN products p ON ps.product_id = p.id
            WHERE oi.order_id = :order_id
        ");
        $stmtItems->execute([':order_id' => $order['id']]);
        $order['items'] = $stmtItems->fetchAll();
    }

    echo json_encode([
        "success" => true,
        "data" => $orders
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// -------------------------------------------------------------
// YENİ SİPARİŞ OLUŞTURMA (Otomatik Stok Düşme Mekanizması)
// POST /api/orders
// -------------------------------------------------------------
if ($method === 'POST' && empty($subPath)) {
    $items = $inputData['items'] ?? []; // [{"product_size_id": 1, "quantity": 2}, ...]

    if (empty($items) || !is_array($items)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Sipariş verilemedi! Sepetiniz boş. Lütfen 'items' dizisini doldurun."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        // İşlemleri güvenli yapmak için transaction başlatalım
        $db->beginTransaction();

        $totalAmount = 0.0;
        $processedItems = []; // Eklenecek sipariş kalemleri detayları
        $ingredientDeductions = []; // Stoktan düşülecek toplam malzemeler (id => miktar)

        // 1. ÖNCE SİPARİŞTEKİ ÜRÜNLERİN GEÇERLİLİĞİNİ VE FİYATLARINI KONTROL EDELİM
        foreach ($items as $item) {
            $productSizeId = (int)($item['product_size_id'] ?? 0);
            $qty = (int)($item['quantity'] ?? 0);

            if ($productSizeId <= 0 || $qty <= 0) {
                throw new Exception("Hatalı ürün boyutu veya miktarı tanımlandı!");
            }

            // Ürün boyutu var mı ve fiyatı ne kadar?
            $stmtSize = $db->prepare("
                SELECT ps.id, ps.price, ps.size_name, p.name as product_name 
                FROM product_sizes ps
                JOIN products p ON ps.product_id = p.id
                WHERE ps.id = :id
            ");
            $stmtSize->execute([':id' => $productSizeId]);
            $sizeDetails = $stmtSize->fetch();

            if (!$sizeDetails) {
                throw new Exception("ID'si {$productSizeId} olan ürün boyutu veritabanında bulunamadı!");
            }

            $price = (float)$sizeDetails['price'];
            $itemTotal = $price * $qty;
            $totalAmount += $itemTotal;

            $processedItems[] = [
                'product_size_id' => $productSizeId,
                'quantity' => $qty,
                'unit_price' => $price,
                'name' => $sizeDetails['product_name'] . " (" . $sizeDetails['size_name'] . ")"
            ];

            // 2. REÇETEYE BAKIP GEREKLİ ENVENTER MALZEMELERİNİ HESAPLAYALIM
            $stmtRecipe = $db->prepare("
                SELECT r.ingredient_id, r.quantity, i.name as ingredient_name, i.stock_quantity, i.unit 
                FROM recipes r
                JOIN ingredients i ON r.ingredient_id = i.id
                WHERE r.product_size_id = :product_size_id
            ");
            $stmtRecipe->execute([':product_size_id' => $productSizeId]);
            $recipeRows = $stmtRecipe->fetchAll();

            foreach ($recipeRows as $recipe) {
                $ingId = (int)$recipe['ingredient_id'];
                $recipeQty = (float)$recipe['quantity'];
                
                // Bu ürünün bu sipariş miktarı için gereken toplam malzeme
                $neededTotal = $recipeQty * $qty;

                if (!isset($ingredientDeductions[$ingId])) {
                    $ingredientDeductions[$ingId] = [
                        'name' => $recipe['ingredient_name'],
                        'unit' => $recipe['unit'],
                        'current_stock' => (float)$recipe['stock_quantity'],
                        'needed_qty' => 0.0
                    ];
                }
                $ingredientDeductions[$ingId]['needed_qty'] += $neededTotal;
            }
        }

        // 3. STOK YETERLİLİK KONTROLÜ
        // Eğer herhangi bir malzemenin stoğu yetmiyorsa siparişi iptal et (Rollback yapacak)
        foreach ($ingredientDeductions as $ingId => $data) {
            if ($data['current_stock'] < $data['needed_qty']) {
                $missingAmount = $data['needed_qty'] - $data['current_stock'];
                throw new Exception("Yetersiz Stok! '{$data['name']}' malzemesinden stokta yeterli miktar yok. Gerekli: {$data['needed_qty']} {$data['unit']}, Eldeki: {$data['current_stock']} {$data['unit']} (Eksik: {$missingAmount} {$data['unit']})");
            }
        }

        // 4. VERİTABANINA SİPARİŞİ YAZ
        $stmtOrder = $db->prepare("INSERT INTO orders (user_id, total_amount) VALUES (:user_id, :total_amount)");
        $stmtOrder->execute([
            ':user_id' => $currentUser['id'],
            ':total_amount' => $totalAmount
        ]);
        $orderId = (int)$db->lastInsertId();

        // 5. SİPARİŞ KALEMLERİNİ YAZ VE STOKLARI DÜŞ
        $stmtItemInsert = $db->prepare("
            INSERT INTO order_items (order_id, product_size_id, quantity, unit_price) 
            VALUES (:order_id, :product_size_id, :quantity, :unit_price)
        ");

        foreach ($processedItems as $pItem) {
            $stmtItemInsert->execute([
                ':order_id' => $orderId,
                ':product_size_id' => $pItem['product_size_id'],
                ':quantity' => $pItem['quantity'],
                ':unit_price' => $pItem['unit_price']
            ]);
        }

        // Stok güncellemesini yapalım
        $stmtStockUpdate = $db->prepare("
            UPDATE ingredients 
            SET stock_quantity = stock_quantity - :deduct_qty 
            WHERE id = :id
        ");

        foreach ($ingredientDeductions as $ingId => $data) {
            $stmtStockUpdate->execute([
                ':deduct_qty' => $data['needed_qty'],
                ':id' => $ingId
            ]);
        }

        // Sipariş ve stok düşüşleri sorunsuz tamamlandı, kaydet
        $db->commit();

        echo json_encode([
            "success" => true,
            "message" => "Sipariş başarıyla alındı ve reçeteye göre stoktan düşüldü!",
            "order" => [
                "order_id" => $orderId,
                "cashier" => $currentUser['full_name'],
                "total_amount" => $totalAmount,
                "items" => $processedItems,
                "stock_deductions" => array_values(array_map(function($k, $v) {
                    return [
                        "ingredient_id" => $k,
                        "name" => $v['name'],
                        "deducted_quantity" => $v['needed_qty'],
                        "remaining_stock" => $v['current_stock'] - $v['needed_qty'],
                        "unit" => $v['unit']
                    ];
                }, array_keys($ingredientDeductions), $ingredientDeductions))
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        // Hata oluşursa her şeyi iptal et (Hiçbir sipariş yazılmaz, stoklar düşmez)
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Sipariş tamamlanamadı! Hata: " . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}
