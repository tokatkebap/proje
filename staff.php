<?php
/**
 * api/staff.php - Personel CRUD ve Giriş API
 * 
 * Bu dosya yeni personel ekleme, listeleme, güncelleme, silme 
 * ve personel giriş (Login) işlemlerini kontrol eder.
 */

// Gelen istek yolunu analiz edelim (örn: /api/staff/login veya /api/staff/5)
$subPath = str_replace('/api/staff', '', $path);
$subPath = rtrim($subPath, '/');

// -------------------------------------------------------------
// GİRİŞ (LOGIN) İŞLEMİ (Şifresiz erişilebilir)
// POST /api/staff/login
// -------------------------------------------------------------
if ($method === 'POST' && $subPath === '/login') {
    $username = trim($inputData['username'] ?? '');
    $password = trim($inputData['password'] ?? '');

    if (empty($username) || empty($password)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Lütfen kullanıcı adı (username) ve şifre (password) bilgilerini gönderin."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Kullanıcıyı veritabanından bulalım
    $stmt = $db->prepare("SELECT * FROM users WHERE username = :username");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        // Giriş Başarılı
        // Testleri kolaylaştırmak için kullanıcının veritabanındaki ID'sini Bearer Token olarak döndürüyoruz!
        echo json_encode([
            "success" => true,
            "message" => "Giriş başarılı! Hoş geldiniz, " . $user['full_name'],
            "user" => [
                "id" => $user['id'],
                "username" => $user['username'],
                "role" => $user['role'],
                "full_name" => $user['full_name']
            ],
            "token" => (string)$user['id'] // Örn: Authorization: Bearer 1 veya Bearer 2 şeklinde istek atılabilir
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Giriş Başarısız
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Kullanıcı adı veya şifre hatalı!"
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// -------------------------------------------------------------
// BUNDAN SONRAKİ TÜM İŞLEMLER YÖNETİCİ (ADMIN) YETKİSİ GEREKTİRİR!
// -------------------------------------------------------------
$currentUser = requireAdmin();

// -------------------------------------------------------------
// PERSONEL LİSTELEME
// GET /api/staff
// -------------------------------------------------------------
if ($method === 'GET' && empty($subPath)) {
    // Şifre alanını dışarıda bırakarak kullanıcıları çekelim
    $users = $db->query("SELECT id, username, role, full_name FROM users")->fetchAll();
    
    echo json_encode([
        "success" => true,
        "data" => $users
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// -------------------------------------------------------------
// TEK BİR PERSONEL DETAYI ÇEKME
// GET /api/staff/<id>
// -------------------------------------------------------------
if ($method === 'GET' && preg_match('/^\/(\d+)$/', $subPath, $matches)) {
    $userId = (int)$matches[1];
    
    $stmt = $db->prepare("SELECT id, username, role, full_name FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    
    if ($user) {
        echo json_encode([
            "success" => true,
            "data" => $user
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Personel bulunamadı!"
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// -------------------------------------------------------------
// YENİ PERSONEL EKLEME
// POST /api/staff
// -------------------------------------------------------------
if ($method === 'POST' && empty($subPath)) {
    $username = trim($inputData['username'] ?? '');
    $password = trim($inputData['password'] ?? '');
    $role = trim($inputData['role'] ?? 'cashier'); // Varsayılan yetki: cashier (kasiyer)
    $fullName = trim($inputData['full_name'] ?? '');

    // Temel doğrulama
    if (empty($username) || empty($password) || empty($fullName)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Eksik bilgi! Lütfen username, password ve full_name alanlarını doldurun."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    if ($role !== 'admin' && $role !== 'cashier') {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Geçersiz yetki (role)! Sadece 'admin' veya 'cashier' seçilebilir."
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $stmt = $db->prepare("INSERT INTO users (username, password, role, full_name) VALUES (:username, :password, :role, :full_name)");
        $stmt->execute([
            ':username' => $username,
            ':password' => password_hash($password, PASSWORD_DEFAULT), // Şifreyi hashleyerek güvenli kaydediyoruz
            ':role' => $role,
            ':full_name' => $fullName
        ]);

        echo json_encode([
            "success" => true,
            "message" => "Yeni personel başarıyla kaydedildi!",
            "data" => [
                "id" => $db->lastInsertId(),
                "username" => $username,
                "role" => $role,
                "full_name" => $fullName
            ]
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        // Eğer kullanıcı adı zaten varsa (UNIQUE kısıtlaması nedeniyle hata verecektir)
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Bu kullanıcı adı zaten alınmış!"
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// -------------------------------------------------------------
// PERSONEL BİLGİSİNİ GÜNCELLEME
// PUT /api/staff/<id>
// -------------------------------------------------------------
if ($method === 'PUT' && preg_match('/^\/(\d+)$/', $subPath, $matches)) {
    $userId = (int)$matches[1];

    // Personel var mı kontrol et
    $stmt = $db->prepare("SELECT id FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Güncellenecek personel bulunamadı!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $username = trim($inputData['username'] ?? '');
    $password = trim($inputData['password'] ?? '');
    $role = trim($inputData['role'] ?? '');
    $fullName = trim($inputData['full_name'] ?? '');

    // Dinamik olarak güncelleme SQL'ini hazırlayalım
    $updateFields = [];
    $params = [':id' => $userId];

    if (!empty($username)) {
        $updateFields[] = "username = :username";
        $params[':username'] = $username;
    }
    if (!empty($password)) {
        $updateFields[] = "password = :password";
        $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
    }
    if (!empty($role)) {
        if ($role !== 'admin' && $role !== 'cashier') {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "Geçersiz yetki! Sadece 'admin' veya 'cashier' olabilir."], JSON_UNESCAPED_UNICODE);
            exit();
        }
        $updateFields[] = "role = :role";
        $params[':role'] = $role;
    }
    if (!empty($fullName)) {
        $updateFields[] = "full_name = :full_name";
        $params[':full_name'] = $fullName;
    }

    if (empty($updateFields)) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Güncellenecek herhangi bir alan gönderilmedi!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    try {
        $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        echo json_encode([
            "success" => true,
            "message" => "Personel bilgileri başarıyla güncellendi!"
        ], JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "Güncelleme başarısız! Kullanıcı adı zaten mevcut olabilir."
        ], JSON_UNESCAPED_UNICODE);
    }
    exit();
}

// -------------------------------------------------------------
// PERSONEL SİLME
// DELETE /api/staff/<id>
// -------------------------------------------------------------
if ($method === 'DELETE' && preg_match('/^\/(\d+)$/', $subPath, $matches)) {
    $userId = (int)$matches[1];

    // Kendini silmeye çalışmasını engelleyelim
    if ($userId === $currentUser['id']) {
        http_response_code(400);
        echo json_encode([
            "success" => false,
            "message" => "Kendi hesabınızı silemezsiniz!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    // Personel var mı kontrol et
    $stmt = $db->prepare("SELECT id FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Silinecek personel bulunamadı!"
        ], JSON_UNESCAPED_UNICODE);
        exit();
    }

    $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);

    echo json_encode([
        "success" => true,
        "message" => "Personel başarıyla sistemden silindi!"
    ], JSON_UNESCAPED_UNICODE);
    exit();
}
