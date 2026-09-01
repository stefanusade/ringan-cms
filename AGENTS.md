# AGENTS.md — Headless CMS (PHP Procedural)

Dokumen ini adalah panduan kerja untuk AI coding agent (Zed Agent) saat mengerjakan
project ini. Baca seluruh dokumen sebelum membuat/mengubah kode. Ikuti aturan di
sini secara ketat, terutama bagian **Keamanan** — ini adalah requirement wajib,
bukan saran opsional.

---

## 1. Ringkasan Project

Membangun **Headless CMS super ringan** dengan:

- **Admin web dashboard** (server-rendered, bukan SPA berat) untuk mengelola semua isi.
- **User management** (multi-user, role-based).
- **Custom CRUD builder** — mirip ACF di WordPress: user admin bisa mendefinisikan
  "Content Type" (setara Custom Post Type) beserta field-field custom-nya
  (text, number, richtext, image, relation, dll) **tanpa perlu ubah kode**,
  lalu content type tersebut otomatis tersedia sebagai REST API endpoint.
- Ditulis dalam **PHP Procedural** (bukan OOP, bukan framework besar seperti
  Laravel/Symfony) — tapi tetap terstruktur rapi, bukan spaghetti.
- **Keamanan jadi prioritas utama** karena ini akan dipakai untuk client-facing API.

Target pengguna: dipakai sebagai backend headless untuk frontend Astro/JS lain
(pola serupa dengan setup headless WordPress yang sudah biasa dikerjakan), tapi
jauh lebih ringan dan tanpa overhead WordPress core.

---

## 2. Prinsip Desain (WAJIB dipatuhi agent)

1. **Procedural, bukan OOP-heavy.** Boleh pakai `class` HANYA untuk hal yang
   benar-benar butuh state/instance (mis. koneksi PDO wrapper tipis), tapi
   business logic tetap dalam bentuk function, bukan service class/DI container.
2. **Tanpa framework besar.** Tidak boleh menambahkan Laravel, Symfony, Slim,
   CodeIgniter, dsb. Boleh pakai composer HANYA untuk library kecil & terpercaya
   (mis. `firebase/php-jwt`, `vlucas/phpdotenv`) — selalu tanya user dulu sebelum
   menambah dependency baru.
3. **Zero-config feel untuk user akhir**, tapi kode tetap eksplisit — hindari magic.
4. **Setiap fitur baru wajib dicek terhadap checklist keamanan di Bab 6** sebelum
   dianggap selesai.
5. **Satu file = satu tanggung jawab.** Jangan taruh query DB, HTML, dan business
   logic dalam satu file besar.

---

## 3. Struktur Folder

```
/config/
    config.php          # load .env, definisikan konstanta (jangan hardcode secret)
    db.php              # koneksi PDO (singleton function get_db())
/includes/
    auth.php            # login, session, password hashing, CSRF token
    validation.php      # fungsi-fungsi validasi & sanitasi input generik
    response.php        # json_response(), redirect(), flash message helpers
    upload.php          # validasi & handling file upload aman
    rate_limit.php       # rate limiting login & API
    content_types.php   # CRUD untuk definisi content type & field
    content_entries.php # CRUD untuk data/entry berdasarkan content type
    permissions.php     # cek role & ownership (ACL sederhana)
    audit_log.php       # pencatatan aktivitas sensitif
/admin/
    index.php           # dashboard
    login.php
    logout.php
    users/
        index.php  create.php  edit.php  delete.php
    content-types/
        index.php  create.php  edit.php  fields.php  delete.php
    entries/
        index.php  create.php  edit.php  delete.php
    api-keys/
        index.php  create.php  revoke.php
    assets/
        css/  js/            # vanilla, tanpa build step berat
/api/
    v1/
        index.php           # router utama API
        auth.php            # endpoint login API (opsional, untuk JWT)
        content-type.php    # handler generik GET/POST/PUT/DELETE per content type
        _bootstrap.php      # validasi API key/JWT, rate limit, header CORS
/storage/
    uploads/                # HARUS di luar webroot pada deployment produksi
    logs/
/database/
    schema.sql
    migrations/             # file .sql bernomor urut, idempotent jika mungkin
/public/                    # webroot: hanya index.php + assets, TIDAK ADA source PHP lain
    index.php               # front controller tipis, include dari luar public/
    .htaccess
.env.example
```

**Aturan webroot:** hanya folder `/public` yang boleh diakses langsung oleh
Apache/Nginx. Semua folder lain (`/config`, `/includes`, `/database`, `/storage`)
harus berada di luar document root, atau diblok via `.htaccess` / `deny all`
di server config. Agent harus mengingatkan user jika struktur deployment tidak
memenuhi ini.

---

## 4. Skema Database Inti

Pendekatan: **hybrid JSON column**, bukan full EAV (Entity-Attribute-Value) klasik.
Alasan: EAV penuh (satu baris per field per entry) lambat untuk dibaca ulang dan
rumit di PHP procedural murni. JSON column lebih ringan untuk dibaca, tetap fleksibel,
dan MySQL 8 / MariaDB modern sudah cukup baik indexing JSON via generated column.

```sql
-- users
users (
  id, username, email, password_hash, role ENUM('superadmin','editor','viewer'),
  is_active, created_at, updated_at
)

-- content_types  (setara "Post Type" di WP)
content_types (
  id, slug UNIQUE, label, description,
  is_api_enabled TINYINT DEFAULT 1,
  created_by, created_at, updated_at
)

-- content_type_fields (setara ACF field group per content type)
content_type_fields (
  id, content_type_id, field_key, label,
  field_type ENUM('text','textarea','richtext','number','boolean',
                   'date','image','file','select','relation'),
  options_json,        -- untuk select options, relation target, validasi (required, max_length, dll)
  sort_order,
  is_required TINYINT
)

-- content_entries (data aktual, generik untuk semua content type)
content_entries (
  id, content_type_id, status ENUM('draft','published','archived'),
  data JSON,           -- { "field_key": value, ... } sesuai definisi field
  created_by, created_at, updated_at
)

-- api_keys
api_keys (
  id, label, key_hash,      -- SIMPAN HASH, bukan plaintext
  scope ENUM('read','read_write'),
  content_type_restriction JSON NULL,  -- null = semua content type
  is_active, last_used_at, created_at
)

-- audit_log
audit_log (
  id, user_id, action, target_table, target_id,
  ip_address, user_agent, created_at
)

-- login_attempts (untuk rate limiting/brute-force protection)
login_attempts (
  id, identifier (username/email/IP), success, attempted_at
)
```

Field `data JSON` divalidasi di layer aplikasi (`content_entries.php`) terhadap
definisi field dari `content_type_fields` sebelum disimpan — **jangan pernah
menyimpan field yang tidak terdefinisi** (mencegah mass-assignment ke API).

---

## 5. Desain API

- Prefix: `/api/v1/{content_type_slug}`
- Method: `GET` (list & detail), `POST` (create), `PUT/PATCH` (update), `DELETE`.
- Auth: header `X-API-Key`, divalidasi terhadap `api_keys.key_hash` (hash, bukan
  plaintext, dibandingkan pakai `hash_equals()`/verifikasi hash — jangan `==`).
- Response selalu JSON konsisten:
  ```json
  { "success": true, "data": {...}, "meta": {...} }
  { "success": false, "error": { "code": "...", "message": "..." } }
  ```
- Whitelist field: endpoint hanya menerima key yang ada di `content_type_fields`
  untuk content type tersebut — field lain ditolak (400), bukan diabaikan diam-diam.
- Pagination wajib untuk list endpoint (`?page=&per_page=`, batasi `per_page` maks
  100 untuk mencegah resource exhaustion).
- CORS: whitelist origin eksplisit dari config, jangan `Access-Control-Allow-Origin: *`
  jika API punya scope `read_write`.

---

## 6. Checklist Keamanan (WAJIB untuk setiap fitur/PR)

Agent harus memverifikasi poin-poin ini setiap kali menulis kode yang menyentuh
input user, autentikasi, atau file:

- [ ] **SQL Injection** — HANYA gunakan PDO prepared statements dengan bound
      parameter. Tidak ada concatenation string SQL, sama sekali, di mana pun.
- [ ] **XSS** — semua output ke HTML di-escape dengan `htmlspecialchars($val, ENT_QUOTES, 'UTF-8')`.
      Untuk field `richtext`, gunakan HTML purifier/whitelist tag, jangan output mentah.
- [ ] **CSRF** — semua form di admin dashboard menyertakan token CSRF
      (generate per session, verifikasi via `hash_equals()` sebelum proses POST).
- [ ] **Password** — hash dengan `password_hash($pw, PASSWORD_ARGON2ID)`, verifikasi
      dengan `password_verify()`. Jangan pernah log atau simpan password plaintext.
- [ ] **Session security** — `session_regenerate_id(true)` setelah login berhasil,
      cookie flags `HttpOnly`, `Secure`, `SameSite=Strict`/`Lax`.
- [ ] **Brute-force / rate limiting** — batasi percobaan login (mis. 5x/15 menit
      per IP+username via tabel `login_attempts`), begitu juga rate limit di API
      per API key/IP.
- [ ] **IDOR** — setiap akses ke entry/resource by ID wajib cek kepemilikan/role,
      jangan asumsikan ID yang valid = boleh diakses.
- [ ] **Mass assignment** — field yang diterima dari request HARUS di-whitelist
      sesuai definisi content type, tidak langsung `$_POST` → query.
- [ ] **File upload** — validasi MIME asli via `finfo_file()` (bukan cuma ekstensi),
      whitelist ekstensi, rename ke nama random (bukan nama asli user), simpan di
      luar webroot atau folder tanpa izin eksekusi PHP (`.htaccess: php_flag engine off`
      atau setara di Nginx/FASTPANEL).
- [ ] **Security headers** — set `Content-Security-Policy`, `X-Content-Type-Options: nosniff`,
      `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`.
- [ ] **Error handling** — `display_errors = Off` di produksi, error dicatat ke
      `/storage/logs/`, response ke user tidak pernah menampilkan stack trace/query SQL.
- [ ] **API key handling** — simpan `key_hash` (hash SHA-256/bcrypt dari key),
      tampilkan key asli ke user HANYA sekali saat dibuat.
- [ ] **Least privilege DB user** — user MySQL untuk aplikasi tidak boleh punya
      privilege `DROP`/`GRANT`, hanya `SELECT, INSERT, UPDATE, DELETE`.
- [ ] **Audit log** — aksi sensitif (login, hapus user, ubah role, buat API key,
      hapus content type) dicatat ke `audit_log`.

---

## 7. Konvensi Coding

- **Penamaan:** `snake_case` untuk function & variable, `PascalCase` tidak dipakai
  kecuali untuk nama class (jarang), file mengikuti isi utamanya (`content_entries.php`).
- **Satu fungsi = satu tanggung jawab.** Fungsi query DB (`get_entry_by_id()`,
  `create_content_type()`, dll) dipisah dari fungsi rendering/HTML.
- **Tidak ada logic besar di scope global** — file entry point (`admin/*/index.php`)
  hanya memanggil function dari `/includes/`, tidak mendefinisikan logic panjang inline.
- **Koneksi DB** lewat satu fungsi `get_db()` (singleton, return objek PDO yang sama
  selama request), jangan buka koneksi baru di banyak tempat.
- **Konfigurasi & secret** (DB credential, JWT secret, dsb.) HANYA dari `.env` via
  `config/config.php`, tidak pernah di-hardcode atau di-commit ke git.
- **Response helper konsisten:** semua endpoint API memanggil `json_response($data, $code)`
  dari `includes/response.php`, bukan `echo json_encode()` manual berulang.
- **Validasi input** selalu lewat `includes/validation.php` (mis. `validate_field($value, $rules)`)
  sebelum data masuk ke query, konsisten untuk admin form maupun API.

---

## 8. Yang TIDAK boleh dilakukan agent

- Jangan menambahkan framework/ORM besar tanpa persetujuan eksplisit user.
- Jangan menyimpan file upload di dalam folder yang bisa dieksekusi PHP.
- Jangan membuat query dengan variabel yang langsung disisipkan ke string SQL.
- Jangan mengekspos pesan error mentah (stack trace, query, path server) ke response API/HTML.
- Jangan melewati checklist Bab 6 walau untuk fitur "sementara"/prototype — kalau
  memang sengaja disederhanakan untuk prototype, agent harus menyebutkannya secara
  eksplisit ke user, bukan diam-diam.

---

## 9. Alur Verifikasi Sebelum Fitur Dianggap Selesai

1. Jalankan (atau minta user menjalankan) `php -l` di semua file yang diubah untuk cek syntax error.
2. Pastikan skema DB baru sudah ditambahkan ke `/database/schema.sql` dan file migrasi bernomor.
3. Cocokkan fitur baru terhadap checklist Bab 6.
4. Jika fitur menyentuh API publik, uji dengan contoh request (curl) untuk memastikan
   field yang tidak terdaftar ditolak, dan API key invalid mengembalikan 401.
