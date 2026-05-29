<?php
/**
 * api/reports.php - Dashboard Raporlama ve Analiz API
 * 
 * Bu dosya yönetici (admin) paneli için günlük ciro analizleri, 
 * son 7 günlük satış trendleri ve en çok satan ürünlerin istatistiklerini üretir.
 */

$subPath = str_replace('/api/reports', '', $path);
$subPath = rtrim($subPath, '/');

// Raporlama hassas veri içerdiğinden sadece yöneticilere (admin) açıktır
requireAdmin();

// -------------------------------------------------------------
// DASHBOARD GENEL RAPORLARI
// GET /api/reports
// -------------------------------------------------------------
if ($method === 'GET' && empty($subPath)) {
    // İstenirse belirli bir tarihin raporunu çekebilmek için parametre alalım (Varsayılan: Bugün)
    $targetDate = $_GET['date'] ?? null;
    
    if ($targetDate) {
        // Tarih formatı doğrulaması (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Geçersiz tarih formatı! Lütfen YYYY-MM-DD formatında gönderin."], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $dateSqlCondition = "date(created_at) = :target_date";
        $dateParams = [':target_date' => $targetDate];
    } else {
        $dateSqlCondition = "date(created_at) = date('now', 'localtime')";
        $dateParams = [];
    }

    // --- 1. GÜNLÜK CİRO ANALİZİ (Bugün/Hedef Gün) ---
    // Toplam Ciro, Toplam Sipariş Sayısı ve Ortalama Sepet Tutarı
    $stmtDaily = $db->prepare("
        SELECT 
            COALESCE(SUM(total_amount), 0.0) as total_revenue,
            COUNT(id) as total_orders,
            COALESCE(AVG(total_amount), 0.0) as average_order_value
        FROM orders
        WHERE {$dateSqlCondition}
    ");
    $stmtDaily->execute($dateParams);
    $dailySummary = $stmtDaily->fetch();

    // --- 2. SON 7 GÜNLÜK SATIŞ TRENDİ (Grafikler İçin) ---
    $weeklyTrend = $db->query("
        SELECT 
            date(created_at) as sale_date,
            SUM(total_amount) as daily_revenue,
            COUNT(id) as order_count
        FROM orders
        WHERE date(created_at) >= date('now', '-6 days', 'localtime')
        GROUP BY sale_date
        ORDER BY sale_date ASC
    ")->fetchAll();

    // --- 3. EN ÇOK SATAN ÜRÜNLERİN İSTATİSTİKLERİ ---
    // En çok satan ürün boyutları, satış adetleri ve ürettikleri toplam ciro
    $topSelling = $db->query("
        SELECT 
            p.name as product_name,
            ps.size_name,
            SUM(oi.quantity) as total_quantity_sold,
            SUM(oi.quantity * oi.unit_price) as total_revenue_generated
        FROM order_items oi
        JOIN product_sizes ps ON oi.product_size_id = ps.id
        JOIN products p ON ps.product_id = p.id
        GROUP BY oi.product_size_id
        ORDER BY total_quantity_sold DESC
        LIMIT 10
    ")->fetchAll();

    // --- 4. ENVANTER KRİTİK STOK UYARISI ---
    // Stoğu 10 birimin (gram veya adet fark etmeksizin) altına düşen malzemeler
    $criticalStock = $db->query("
        SELECT id, name, stock_quantity, unit 
        FROM ingredients 
        WHERE (unit = 'adet' AND stock_quantity < 20) 
           OR (unit = 'gram' AND stock_quantity < 1000) -- Gram bazlılar için 1 KG sınır
           OR (unit = 'ml' AND stock_quantity < 500)   -- Sıvılar için yarım litre
        ORDER BY stock_quantity ASC
    ")->fetchAll();

    echo json_encode([
        "success" => true,
        "reporting_date" => $targetDate ?? date('Y-m-d'),
        "data" => [
            "daily_summary" => [
                "total_revenue" => (float)$dailySummary['total_revenue'],
                "total_orders" => (int)$dailySummary['total_orders'],
                "average_order_value" => round((float)$dailySummary['average_order_value'], 2)
            ],
            "weekly_trend" => array_map(function($row) {
                return [
                    "date" => $row['sale_date'],
                    "revenue" => (float)$row['daily_revenue'],
                    "orders" => (int)$row['order_count']
                ];
            }, $weeklyTrend),
            "top_selling_products" => array_map(function($row) {
                return [
                    "product_name" => $row['product_name'],
                    "size_name" => $row['size_name'],
                    "total_quantity_sold" => (int)$row['total_quantity_sold'],
                    "total_revenue" => (float)$row['total_revenue_generated']
                ];
            }, $topSelling),
            "critical_stock_warnings" => array_map(function($row) {
                return [
                    "ingredient_id" => $row['id'],
                    "name" => $row['name'],
                    "stock_quantity" => (float)$row['stock_quantity'],
                    "unit" => $row['unit']
                ];
            }, $criticalStock)
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
