# Ringan CMS

Headless CMS super ringan berbasis **PHP procedural** (tanpa framework besar),
dengan admin dashboard server-rendered, user management multi-role, custom CRUD
builder (content type + field ala ACF), dan REST API otomatis per content type —
cocok dipakai sebagai backend headless untuk frontend Astro/JS.

## Disclaimer

Keamanan situs production adalah **tanggung jawab sepenuhnya dari penginstal /
pengelola Ringan CMS** — bukan tanggung jawab developer.

Ringan CMS menyediakan fondasi yang aman secara bawaan (prepared statements,
CSRF, hashing password, sanitasi output, upload yang divalidasi, dsb.), namun
hal-hal berikut tetap menjadi tanggung jawab Anda saat men-deploy ke production:

- Konfigurasi server & document root yang benar (webroot = folder `public/`);
- Kredensial database, `JWT_SECRET`, dan secret lain di `.env` — jangan
  di-commit ke git, jangan sampai bocor, dan gunakan nilai acak yang kuat;
- HTTPS/SSL, security headers, firewall, backup rutin, dan pemantauan;
- Memperbarui PHP serta komponen lain ke versi yang masih didukung keamanan;
- Menghapus folder `install/` dan file yang tidak diperlukan setelah instalasi;
- Segala modifikasi kode yang dilakukan penginstal di luar cakupan project ini.

Perangkat lunak ini disediakan apa adanya ("as-is"); penggunaan sepenuhnya
menjadi risiko Anda sendiri.

## Fitur

- **Content Type builder** — buat "post type" (slug + label) lalu definisikan
  field-nya (text, textarea, richtext, number, boolean, date, image, file,
  select, relation) tanpa mengubah kode. Setiap content type otomatis tersedia
  sebagai REST API endpoint `/api/v1/{slug}`.
- **User management** dengan 3 role: `superadmin` (semua), `editor` (kelola
  entri), `viewer` (baca saja).
- **API key management** — key di-hash (SHA-256) saat disimpan, key asli hanya
  tampil sekali saat dibuat. Scope `read` / `read_write`, plus restriksi per
  content type.
- **Settings** — atur judul situs, tagline, deskripsi, favicon, **zona waktu**,
  dan **path REST API** (menu Settings di admin; default `/api/v1`, bisa diubah
  menjadi prefix rahasia seperti `/content-api`). Info situs juga tersedia via
  endpoint publik `GET /api/v1/site`.
- **Taksonomi** — kategori/tag/kustom per content type (hierarkis opsional);
  term dikelola di admin, entri bisa multi-term. API: filter
  `?tax=<slug>&term=<slug>`, daftar `GET /api/v1/{slug}/taxonomies`, dan
  term ikut di payload setiap entri.
- **Update** — cek & update Ringan CMS langsung dari dashboard (manifest
  JSON yang di-host sendiri, superadmin only, backup otomatis).
- **Login API (JWT)** opsional via `POST /api/v1/auth`.
- **Pagination wajib** pada list endpoint (maks `per_page=100`).

## Persyaratan

- PHP **8.1+** dengan ekstensi: `pdo_mysql`, `mbstring`, `fileinfo`, `json`.
- MySQL 8 / MariaDB 10.4+.
- Apache (dengan `mod_rewrite`) atau Nginx.
- Tanpa composer, tanpa dependency eksternal.

## Instalasi

### Opsi A — Wizard web (disarankan)

1. Pastikan MySQL/MariaDB berjalan dan Anda punya kredensial user dengan hak
   `CREATE` pada database (dibutuhkan saat migrasi tabel).
2. Buka `http://localhost/install`.
3. Ikuti langkah wizard: prasyarat → kredensial database → migrasi → akun
   superadmin → selesai. Wizard otomatis membuat `.env` (JWT secret acak,
   permission 0600), database (bila user berhak `CREATE DATABASE`), semua tabel,
   dan akun admin.
4. Setelah selesai, **hapus folder `install/`** untuk keamanan. Rute `/install`
   juga otomatis diblok setelah instalasi terdeteksi selesai.

### Opsi B — CLI

```bash
cd /var/www/html/ringan-cms
cp .env.example .env
# edit .env: DB_HOST, DB_NAME, DB_USER, DB_PASS, JWT_SECRET (wajib di produksi)
php database/install.php        # buat tabel + superadmin pertama
```

Setelah instalasi, buka `http://localhost/admin/login`.

### Mode deployment

**Mode A — Document root = `/public` (disarankan untuk produksi):** arahkan
document root Apache/Nginx ke folder `public/`. Semua folder lain
(`config/`, `includes/`, `database/`, `admin/`, `api/`, `storage/`, `install/`)
berada di luar webroot. `.env` tidak akan pernah tersedia via HTTP.

**Mode B — Sub-folder (mis. `localhost/ringan-cms/`):** project ditaruh di
bawah document root, mis. `/var/www/html/ringan-cms`. `.htaccess` di root
project otomatis mengarahkan semua request ke front controller
`public/index.php`, dan `BASE_URL` terdeteksi otomatis dari lokasi script
(tidak perlu diisi di `.env`). Pada mode ini folder-folder tersebut secara
fisik ada di dalam area document root, jadi:

- pastikan `mod_rewrite` aktif dan `AllowOverride All` untuk direktori project;
- JANGAN menghapus file `.htaccess` deny-all di `config/`, `includes/`,
  `database/`, `api/`, `storage/` — itu lapisan keamanan yang mencegah folder
  tersebut diakses langsung via URL;
- `admin/` dan `install/` **sengaja tidak** memakai `Require all denied`
  (karena Apache mengecek `Require` sebelum mod_rewrite per-directory,
  deny-all di sana akan membuat routing `/admin/*` dan `/install` gagal 403).
  File PHP di kedua folder itu tetap tidak bisa dieksekusi langsung — rewrite
  root mengalihkan semua request non-`/public/` ke front controller, dan path
  seperti `/admin/login.php` bukan route → 404;
- proteksi `FilesMatch "\.(env|log|sql)$"` di `.htaccess` root juga jangan
  dihapus.

> **Mode B adalah pengecualian praktis dari aturan "webroot hanya /public"** —
> untuk produksi yang ketat, tetap gunakan Mode A.

Contoh konfigurasi Nginx:

```nginx
server {
    listen 80;
    server_name cms.example.com;
    root /var/www/html/ringan-cms/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

## Struktur

```
config/       env loader + konstanta + koneksi PDO (get_db())
includes/     auth, validation, response, rate_limit, audit_log,
              permissions, upload, content_types, content_entries, render
admin/        halaman dashboard (hanya diakses via front controller)
api/v1/       REST API (bootstrap, router, handler content type, auth JWT)
public/       webroot: index.php (front controller) + .htaccess
database/     schema.sql + migrations/ + install.php
storage/      uploads/ (di luar webroot) + logs/
```

## API

Default prefix: `/api/v1` — bisa diubah di admin (Settings → Path REST API),
misal menjadi `/content-api`. Catatan: mengubah path adalah lapisan obscurity
saja; otentikasi sesungguhnya tetap API key / JWT.

Auth: header `X-API-Key: <key>` (atau `Authorization: Bearer <jwt>`).

```bash
# List (default status=published)
curl -H "X-API-Key: rcm_xxx" "https://cms.example.com/api/v1/posts?page=1&per_page=20"

# Detail
curl -H "X-API-Key: rcm_xxx" "https://cms.example.com/api/v1/posts/12"

# Create (scope read_write)
curl -X POST -H "X-API-Key: rcm_xxx" -H "Content-Type: application/json" \
  -d '{"title":"Hello","body":"Isi","status":"published"}' \
  "https://cms.example.com/api/v1/posts"

# Update (PATCH = sebagian, PUT = ganti total)
curl -X PATCH -H "X-API-Key: rcm_xxx" -H "Content-Type: application/json" \
  -d '{"title":"Judul Baru"}' \
  "https://cms.example.com/api/v1/posts/12"

# Delete
curl -X DELETE -H "X-API-Key: rcm_xxx" "https://cms.example.com/api/v1/posts/12"

# Login JWT
curl -X POST -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"rahasia"}' \
  "https://cms.example.com/api/v1/auth"

# Info situs (publik, tanpa API key)
curl "https://cms.example.com/api/v1/site"

# Daftar taksonomi + term dari sebuah content type
curl -H "X-API-Key: rcm_xxx" "https://cms.example.com/api/v1/posts/taxonomies"

# List entri yang difilter per term
curl -H "X-API-Key: rcm_xxx" "https://cms.example.com/api/v1/posts?tax=category&term=berita"
```

### Format respons

```json
{ "success": true, "data": { "id": 1, "status": "published",
  "created_at": "...", "updated_at": "...", "fields": { ... } },
  "meta": { "total": 10, "page": 1, "per_page": 20, "total_pages": 1 } }

{ "success": false, "error": { "code": "validation_error",
  "message": "Validasi gagal.", "details": { "judul": ["wajib diisi"] } } }
```

### Catatan API

- **Whitelist field:** hanya key yang terdaftar di content type yang diterima;
  field lain ditolak `400`, bukan diabaikan diam-diam.
- **Upload via API:** field `image`/`file` menerima path relatif hasil upload
  admin (mis. `2026/09/abc.png`) atau data URI `data:image/png;base64,....`.
  File disimpan dengan nama acak & MIME diverifikasi via `finfo_file()`.
- **Rate limit:** per API key / per IP (default 120 req/60 detik, ubah lewat
  `.env`). Login dibatasi 5 percobaan / 15 menit per IP+username.
- **CORS:** isi `CORS_ORIGINS` di `.env` (dipisah koma) untuk whitelist origin.
  Tidak ada `Access-Control-Allow-Origin: *`.
- **List params:** `page`, `per_page` (≤100), `status`, `sort`
  (`id|created_at|updated_at`), `order` (`asc|desc`).

## Keamanan (ringkasan)

- Seluruh query memakai PDO prepared statements (tidak ada concatenation input
  user ke SQL).
- Password: `password_hash()` Argon2id; API key: SHA-256; verifikasi
  `hash_equals()` / `password_verify()`.
- CSRF token pada semua form admin, session dengan cookie `HttpOnly` +
  `SameSite=Lax` + `Secure` (HTTPS) + `session_regenerate_id()` setelah login.
- Output di-escape `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`; richtext
  disanitasi whitelist tag + buang event handler/protokol berbahaya.
- Upload: MIME asli via `finfo_file()`, whitelist ekstensi, nama random, disimpan
  di luar webroot, tidak dieksekusi PHP.
- Security headers: `X-Content-Type-Options`, `X-Frame-Options: DENY`,
  `Referrer-Policy`, dan CSP (non-debug).
- Audit log untuk aksi sensitif (login, user, content type, field, entry, API key).
- Error di log ke `storage/logs/`, tidak pernah ditampilkan ke user.

## Role

| Aksi                    | superadmin | editor | viewer |
|-------------------------|:----------:|:------:|:------:|
| Kelola user & API keys  | ✓          | —      | —      |
| Kelola content types    | ✓          | —      | —      |
| Buat/edit/hapus entri   | ✓          | ✓      | —      |
| Lihat entri/dashboard   | ✓          | ✓      | ✓      |

## Pembaruan

- Menu **Updates** di dashboard menampilkan versi terbaru (cache 6 jam) dan
  tombol update interaktif.
- Aktifkan dengan mengisi **Update URL** di Settings — URL manifest JSON:
  `{ "version": "1.2.0", "url": "https://…/ringan-cms-1.2.0.zip", "checksum": "sha256-hex", "changelog": "…" }`.
- Proses update: backup otomatis (storage/backups) → unduh paket → verifikasi
  checksum SHA-256 → ekstrak aman (anti zip-slip) → salin file (`.env`,
  `storage/`, `.git` tidak tersentuh) → jalankan semua migrasi DB → bersihkan
  cache.
- Butuh ekstensi PHP **ZipArchive** (ext-zip) dan hak tulis web server pada
  direktori project.

## Pengembangan

```bash
php -l file.php              # cek syntax
php database/install.php     # setup ulang skema (idempotent)
```

Skema perubahan DB: tambahkan file baru `database/migrations/NNN_nama.sql`
(idempotent) dan perbarui `database/schema.sql`.
