<?php
/**
 * db.php - Veritabanı Bağlantı ve Kurulum Yardımcısı
 * 
 * Bu dosya SQLite veritabanı bağlantısını kurar.
 * Eğer veritabanı dosyası yoksa, tabloları otomatik oluşturur ve varsayılan verileri (Seed) ekler.
 */

// Hataları ekranda göstermek için (Geliştirme aşamasında faydalıdır)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// SQLite veritabanı dosyasının yolu
$dbPath = __DIR__ . '/veritabanı.sqlite';

try {
    // PDO ile SQLite veritabanı bağlantısı
    $db = new PDO("sqlite:" . $dbPath);
    // Hata modunu istisnalara (Exceptions) ayarla
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Verileri nesne (object) veya ilişkisel dizi (assoc) olarak almak için varsayılan ayar
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Yabancı anahtar (Foreign Key) kısıtlamalarını SQLite için aktif et
    $db->exec("PRAGMA foreign_keys = ON;");

    // Tabloları oluşturma SQL sorguları
    $db->exec("
        -- 1. Kategoriler Tablosu
        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE
        );

        -- 2. Ürünler Tablosu
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            category_id INTEGER NOT NULL,
            description TEXT,
            FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
        );

        -- 3. Ürün Boyutları ve Fiyatları Tablosu
        CREATE TABLE IF NOT EXISTS product_sizes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            size_name TEXT NOT NULL, -- örn: Normal Dürüm, Mega Dürüm, 1 KG
            price REAL NOT NULL,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            UNIQUE(product_id, size_name) -- Bir üründe aynı boyut adı iki kez olamaz
        );

        -- 4. Malzemeler (Envanter) Tablosu
        CREATE TABLE IF NOT EXISTS ingredients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            unit TEXT NOT NULL, -- örn: gram, adet, ml
            stock_quantity REAL NOT NULL DEFAULT 0.0
        );

        -- 5. Reçeteler Tablosu
        CREATE TABLE IF NOT EXISTS recipes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_size_id INTEGER NOT NULL,
            ingredient_id INTEGER NOT NULL,
            quantity REAL NOT NULL, -- Reçetede gereken miktar (örn: 100 gram veya 1 adet)
            FOREIGN KEY (product_size_id) REFERENCES product_sizes(id) ON DELETE CASCADE,
            FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
            UNIQUE(product_size_id, ingredient_id) -- Bir ürün boyutunda bir malzeme sadece bir kere tanımlanabilir
        );

        -- 6. Personel (Kullanıcılar) Tablosu
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            role TEXT NOT NULL CHECK(role IN ('admin', 'cashier')), -- admin (Yönetici) veya cashier (Kasiyer)
            full_name TEXT NOT NULL
        );

        -- 7. Siparişler Tablosu
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            total_amount REAL NOT NULL,
            created_at TEXT NOT NULL DEFAULT (datetime('now', 'localtime')),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
        );

        -- 8. Sipariş Kalemleri Tablosu
        CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            product_size_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            unit_price REAL NOT NULL,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_size_id) REFERENCES product_sizes(id) ON DELETE RESTRICT
        );
    ");

    // --- ÖRNEK VERİLERİ (SEED) EKLEME ---
    
    // 1. Personel Ekle (Eğer hiç kullanıcı yoksa)
    $userCount = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount == 0) {
        $stmt = $db->prepare("INSERT INTO users (username, password, role, full_name) VALUES (:username, :password, :role, :full_name)");
        
        // Yönetici şifresi: admin123
        $stmt->execute([
            ':username' => 'admin',
            ':password' => password_hash('admin123', PASSWORD_DEFAULT),
            ':role' => 'admin',
            ':full_name' => 'Yönetici Ahmet'
        ]);

        // Kasiyer şifresi: kasiyer123
        $stmt->execute([
            ':username' => 'kasiyer',
            ':password' => password_hash('kasiyer123', PASSWORD_DEFAULT),
            ':role' => 'cashier',
            ':full_name' => 'Kasiyer Canan'
        ]);
    }

    // 2. Kategorileri Ekle (Boşsa)
    $categoryCount = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($categoryCount == 0) {
        $db->exec("INSERT INTO categories (name) VALUES ('Dürümler'), ('Porsiyonlar'), ('Yemekler/Menüler'), ('İçecekler')");
    }

    // 3. Malzemeleri Ekle (Boşsa)
    $ingredientCount = $db->query("SELECT COUNT(*) FROM ingredients")->fetchColumn();
    if ($ingredientCount == 0) {
        $stmt = $db->prepare("INSERT INTO ingredients (name, unit, stock_quantity) VALUES (:name, :unit, :stock_quantity)");
        
        $stmt->execute([':name' => 'Çiğköfte Harcı', ':unit' => 'gram', ':stock_quantity' => 15000.0]); // 15 kg
        $stmt->execute([':name' => 'Lavaş', ':unit' => 'adet', ':stock_quantity' => 200.0]);
        $stmt->execute([':name' => 'Marul', ':unit' => 'gram', ':stock_quantity' => 5000.0]); // 5 kg
        $stmt->execute([':name' => 'Nar Ekşisi', ':unit' => 'ml', ':stock_quantity' => 2000.0]); // 2 Litre
        $stmt->execute([':name' => 'Dürüm Kağıdı', ':unit' => 'adet', ':stock_quantity' => 300.0]);
        $stmt->execute([':name' => 'Plastik Tabak', ':unit' => 'adet', ':stock_quantity' => 100.0]);
    }

} catch (PDOException $e) {
    die("Veritabanı bağlantısı veya şema kurulumu başarısız oldu: " . $e->getMessage());
}
