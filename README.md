# Multi-WordPress Dashboard — Panduan Instalasi & Setup

## Persyaratan
- PHP 8.0+
- MySQL 5.7+ / MariaDB 10.3+
- Apache/Nginx dengan `mod_rewrite` aktif
- Ekstensi PHP: `pdo_mysql`, `openssl`, `curl`, `fileinfo`
- WordPress target: **Application Passwords** harus diaktifkan (WP 5.6+)

---

## Instalasi

### 1. Upload File
Upload seluruh folder `WP/` ke root web server Anda (misal: `htdocs/WP/` atau `/var/www/html/WP/`).

### 2. Buat Database
```sql
CREATE DATABASE wp_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 3. Import Schema
```bash
mysql -u root -p wp_dashboard < sql/schema.sql
```

### 4. Konfigurasi
Edit file `config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'wp_dashboard');
define('DB_USER', 'root');
define('DB_PASS', 'password_anda');
define('ENCRYPT_KEY', 'ganti_dengan_32_karakter_acak!!!');  // Wajib diganti!
define('APP_URL', 'http://localhost/WP');  // Sesuaikan URL
```

### 5. Buat Folder Upload
```bash
mkdir -p WP/uploads/images
chmod 755 WP/uploads/images
```

### 6. Setup Cron (untuk Scheduled Posts)
Tambahkan ke crontab server:
```bash
# Jalankan setiap menit
* * * * * php /path/to/WP/cron/scheduler.php >> /path/to/WP/cron/scheduler.log 2>&1
```

---

## Login Pertama
- **URL**: `http://localhost/WP/`
- **Username**: `admin`
- **Password**: `admin123`

> ⚠️ Segera ganti password admin setelah login pertama!

---

## Setup WordPress Sites

### Di WordPress (target situs):
1. Login ke WordPress Admin
2. Buka **Users → Profile**
3. Scroll ke bagian **Application Passwords**
4. Beri nama (misal: "WP Dashboard") → klik **Add New Application Password**
5. **Salin password yang tampil** (hanya tampil sekali!)

### Di Dashboard Ini:
1. Login sebagai admin
2. Buka **Kelola Situs WP**
3. Klik **Tambah Situs**
4. Isi:
   - Nama Situs: nama tampilan
   - URL Situs: `https://blog-anda.com`
   - API Base URL: `https://blog-anda.com/wp-json/wp/v2` (otomatis terisi)
   - WP Username: username WordPress Anda
   - Application Password: password yang disalin tadi
5. Klik **Test Koneksi** untuk verifikasi
6. Simpan

---

## Setup Mapping Author

Agar artikel tampil dengan nama penulis yang benar di WordPress:

1. Buka **Mapping Author**
2. Klik **Tambah Mapping**
3. Pilih user platform (penulis)
4. Pilih situs WordPress
5. Author WP akan dimuat otomatis → pilih author yang sesuai
6. Simpan

---

## Alur Penerbitan Artikel

```
Penulis login → Buat artikel → Pilih situs WP
     ↓
Pilih opsi terbitkan:
  ├─ Sekarang  → dikirim langsung ke WP API
  ├─ Draft     → disimpan lokal
  ├─ Sekali    → scheduler cron jalankan pada waktu ditentukan
  ├─ Harian    → scheduler jalankan tiap hari
  ├─ Mingguan  → scheduler jalankan tiap minggu
  ├─ Bulanan   → scheduler jalankan tiap bulan
  └─ Kustom    → scheduler jalankan tiap N jam
```

---

## Struktur File
```
WP/
├── index.php              ← Router utama
├── config.php             ← Konfigurasi DB & app
├── auth.php               ← Session & autentikasi
├── api/wordpress.php      ← WP REST API client
├── includes/layout.php    ← Template layout
├── pages/                 ← Halaman-halaman
│   ├── login.php
│   ├── dashboard.php
│   ├── posts.php
│   ├── new_post.php
│   ├── edit_post.php
│   ├── sites.php
│   ├── users.php
│   ├── author_map.php
│   ├── profile.php
│   ├── activity.php
│   └── settings.php
├── actions/               ← Handler form/aksi
│   ├── auth_action.php
│   ├── post_action.php
│   ├── site_action.php
│   └── user_action.php
├── cron/scheduler.php     ← Cron: proses jadwal posting
├── assets/css/style.css   ← Stylesheet utama
├── assets/js/app.js       ← JavaScript utama
├── uploads/images/        ← Gambar upload lokal
└── sql/schema.sql         ← Schema database
```
