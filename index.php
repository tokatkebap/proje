<?php
/**
 * index.php - Ana API Yönlendirici (Router)
 * 
 * Bu dosya gelen tüm HTTP isteklerini karşılar, CORS başlıklarını ayarlar, 
 * gelen JSON verilerini temizler ve doğru API dosyasına yönlendirir.
 */

// Bazı sunucu veya PHP çalışma ortamlarında getallheaders() fonksiyonu tanımlı olmayabilir.
// Bu durumlarda uygulamanın çökmesini engellemek için alternatif bir fallback tanımlıyoruz:
if (!function_exists('getallheaders')) {
    function getallheaders() {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headerName = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerName] = $value;
            }
        }
        return $headers;
    }
}

// CORS (Cross-Origin Resource Sharing) başlıkları - API'mizin tarayıcılardan da çağrılabilmesi için
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Eğer istek bir OPTIONS (Preflight) isteğiyse hemen 200 dön (Tarayıcı güvenlik önlemi)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Veritabanını dahil et (Otomatik olarak veritabanı kurulacak ve $db değişkeni tanımlanacak)
require_once __DIR__ . '/db.php';

// JSON olarak gelen istek gövdesini (Request Body) oku ve diziye çevir
$inputData = [];
$rawInput = file_get_contents("php://input");
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $inputData = $decoded;
    }
}

// İstek URL yolunu (Path) çözümle
// Derin klasör yollarında veya doğrudan php -S ile çalışırken hatasız çalışacak dinamik çözümleyici
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$parsedUrl = parse_url($requestUri);
$path = $parsedUrl['path'] ?? '';

$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$baseFolder = dirname($scriptName); // Örn: /cigkofte-backend veya /

if ($baseFolder !== '/' && $baseFolder !== '\\') {
    $path = preg_replace('#^' . preg_quote($baseFolder, '#') . '#', '', $path);
}

// Eğer url içinde index.php varsa temizleyelim (örn: /index.php/api/products -> /api/products)
$path = preg_replace('#^/index\.php#', '', $path);
$path = rtrim($path, '/');

// HTTP metodunu al (GET, POST, PUT, DELETE)
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Yetkilendirme Yardımcı Fonksiyonu
// İstek başlıklarındaki (Headers) 'Authorization' değerini harf duyarsız (case-insensitive) olarak kontrol eder
function getAuthorizedUser() {
    global $db;
    $headers = getallheaders();
    
    // Authorization başlığını harf duyarsız arayalım
    $authHeader = '';
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            $authHeader = $value;
            break;
        }
    }
    
    if (empty($authHeader)) {
        return null;
    }
    
    // Bearer token_degeri formatını kontrol et
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
        
        // Bu örnekte, testi kolaylaştırmak için Token olarak doğrudan 'kullanici_adi:rol'
        // veya veritabanındaki user ID'yi kabul edelim.
        // Eğer token sayısal ise (ID ise), veritabanından çekelim.
        if (is_numeric($token)) {
            $stmt = $db->prepare("SELECT id, username, role, full_name FROM users WHERE id = :id");
            $stmt->execute([':id' => (int)$token]);
            return $stmt->fetch();
        }
        
        // Veya token "admin" ya da "kasiyer" ise hızlıca sahte yetki verelim (Geliştirme kolaylığı için)
        if ($token === 'admin-token') {
            return ['id' => 1, 'username' => 'admin', 'role' => 'admin', 'full_name' => 'Yönetici Ahmet'];
        } elseif ($token === 'kasiyer-token') {
            return ['id' => 2, 'username' => 'kasiyer', 'role' => 'cashier', 'full_name' => 'Kasiyer Canan'];
        }
    }
    return null;
}

// Sadece yöneticilerin erişebileceği sayfalar için yetki kontrol fonksiyonu
function requireAdmin() {
    $user = getAuthorizedUser();
    if (!$user || $user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode([
            "success" => false,
            "message" => "Bu işlem için 'admin' (Yönetici) yetkisi gereklidir!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    return $user;
}

// Giriş yapmış herhangi bir personelin (kasiyer veya admin) erişimi için
function requireLogin() {
    $user = getAuthorizedUser();
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Lütfen geçerli bir kullanıcı tokenı ile giriş yapın (Authorization: Bearer <kullanici_id>)"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }
    return $user;
}

// API YÖNLENDİRİCİSİ (ROUTER)
// Gelen URL'ye göre ilgili dosyayı yükle
switch (true) {
    // 1. Personel / Giriş API
    case ($path === '/api/staff' || strpos($path, '/api/staff/') === 0):
        require_once __DIR__ . '/api/staff.php';
        break;

    // 2. Kategori API
    case ($path === '/api/categories' || strpos($path, '/api/categories/') === 0):
        require_once __DIR__ . '/api/categories.php';
        break;

    // 3. Ürün ve Ürün Boyutu API
    case ($path === '/api/products' || strpos($path, '/api/products/') === 0):
        require_once __DIR__ . '/api/products.php';
        break;

    // 4. Malzeme / Envanter API
    case ($path === '/api/ingredients' || strpos($path, '/api/ingredients/') === 0):
        require_once __DIR__ . '/api/ingredients.php';
        break;

    // 5. Reçete API
    case ($path === '/api/recipes' || strpos($path, '/api/recipes/') === 0):
        require_once __DIR__ . '/api/recipes.php';
        break;

    // 6. Sipariş API
    case ($path === '/api/orders' || strpos($path, '/api/orders/') === 0):
        require_once __DIR__ . '/api/orders.php';
        break;

    // 7. Raporlama API
    case ($path === '/api/reports' || strpos($path, '/api/reports/') === 0):
        require_once __DIR__ . '/api/reports.php';
        break;

    // Ana sayfa veya Bilgi
    case ($path === '' || $path === '/api'):
        echo json_encode([
            "success" => true,
            "message" => "Çiğköfte Restoran API Sistemine Hoş Geldiniz!",
            "version" => "1.0.0",
            "docs" => "API endpoints: /api/staff, /api/categories, /api/products, /api/ingredients, /api/recipes, /api/orders, /api/reports"
        ], JSON_UNESCAPED_UNICODE);
        break;

    // Geçersiz Endpoint
    default:
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Aradığınız API uç noktası bulunamadı! Yol: " . $path
        ], JSON_UNESCAPED_UNICODE);
        break;
}
