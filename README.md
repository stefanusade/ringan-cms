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

## Golden Rule — tetap di bawah 1 MB

**Arsip rilis project (`.zip` atau `.tar.gz`) WAJIB di bawah 1.000.000 byte
(1 MB desimal).** Ini bagian dari tagline **"Ringan"** dan bukan target opsional.

- Ukur arsip dari file yang di-track Git, bukan dari folder kerja yang berisi
  `storage/`, `.env`, atau file sementara.
- Jangan commit `vendor/`, `node_modules/`, build output, binary, font, gambar
  besar, atau arsip lain. Aset upload user tetap di `storage/uploads` dan tidak
  ikut arsip rilis.
- Jika fitur baru membuat arsip melewati batas, optimalkan atau hapus aset
  sebelum rilis. Jangan menaikkan batas ini.
- Target aman: **< 900 KB** agar ada ruang untuk perbaikan kecil.

Cek sebelum rilis:

```sh
git archive --format=zip --output=/tmp/ringan-cms.zip HEAD
stat -c%s /tmp/ringan-cms.zip          # harus < 1000000

git archive --format=tar.gz --output=/tmp/ringan-cms.tar.gz HEAD
stat -c%s /tmp/ringan-cms.tar.gz       # harus < 1000000
```

## Fitur

- **Content Type builder** — buat "post type" (slug + label) lalu definisikan
  field-nya (text, textarea, richtext, number, boolean, date, image, file,
  select, relation) tanpa mengubah kode. Setiap content type otomatis tersedia
  sebagai REST API endpoint `/api/v1/{slug}`.
- **User management** dengan 3 role: `superadmin` (semua), `editor` (kelola
  entri, taksonomi, dan media), `viewer` (baca saja).
- **Media library** — kelola semua file yang diunggah: grid pratinjau, pencarian,
  hapus, copy URL, serta unggah langsung dari menu **Media**. File lama di
  `storage/uploads` yang belum tercatat bisa didaftarkan lewat "Sinkron file lama".
- **Import dari WordPress (WXR)** — impor post + meta ACF ke content type
  pilihan: pemetaan field otomatis/manual, verifikasi nilai sebelum eksekusi,
  unduh gambar/file ke media library, pemetaan kategori/tag, dan anti-duplikat
  (import ulang bisa memperbarui entri yang sama).
- **API key management** — key di-hash (SHA-256) saat disimpan, key asli hanya
  tampil sekali saat dibuat. Scope `read` / `read_write`, plus restriksi per
  content type (menu **Settings → API Keys**). Key bisa **diedit**
  (label/scope/restriksi/status) dan **dirotasi** untuk membuat secret baru;
  secret lama langsung tidak berlaku dan secret baru ditampilkan sekali.
- **Webhook SSG** — **Settings → Webhooks** mengirim POST JSON ke build hook
  Astro/Next/Netlify/Cloudflare saat entri dipublikasikan, diperbarui,
  di-unpublish, dihapus, atau setelah import. Request ditandatangani HMAC
  SHA-256 (`X-Ringan-Signature`), dikirim dari antrean setelah response selesai
  (editor tidak menunggu build), dengan retry otomatis dan log delivery.
  Kontrak lengkap + contoh endpoint frontend: lihat
  [Webhook & Rebuild SSG](#webhook--rebuild-ssg).
  Upgrade: jalankan migrasi `database/migrations/010_webhooks.sql`.
- **Settings** — atur judul situs, tagline, deskripsi, favicon, **zona waktu**,
  dan **path REST API** (menu Settings di admin; default `/api/v1`, bisa diubah
  menjadi prefix rahasia seperti `/content-api`). Info situs juga tersedia via
  endpoint publik `GET /api/v1/site`.
- **Manajemen entri lanjutan** — tab **Semua / Published / Draft / Trash**,
  aksi massal (buang ke Trash, pulihkan, set Draft/Published, hapus permanen),
  dan tombol **Buang** per baris. Trash adalah soft delete: entri tidak tampil
  di tab Semua, dashboard, maupun API, tetapi datanya tetap tersimpan sampai
  dihapus permanen. Upgrade: jalankan migrasi
  `database/migrations/009_entry_trash.sql` (otomatis lewat menu Updates).
- **Taksonomi lintas content type** — kategori/tag/kustom yang bisa dipakai
  oleh banyak content type sekaligus (relasi many-to-many), hierarkis opsional;
  term dikelola di admin, entri bisa multi-term. API: filter
  `?tax=<slug>&term=<slug>`, daftar `GET /api/v1/{slug}/taxonomies`, dan
  term ikut di payload setiap entri.
- **Update** — cek & update Ringan CMS langsung dari dashboard (manifest
  JSON yang di-host sendiri, superadmin only, backup otomatis).
- **Media** — pustaka media (lihat/unggah/hapus) + kompresi gambar otomatis saat
  upload + offload media ke R2/S3-compatible (Settings → Media).
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

# Unggah media (gambar/file) — untuk form frontend
curl -X POST -H "X-API-Key: rcm_xxx" \
  -F "file=@/path/foto.jpg" \
  -F "kind=image" \
  "https://cms.example.com/api/v1/media"
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
- **Upload via API:**
  - Endpoint **`POST /api/v1/media`** (`multipart/form-data`) untuk mengunggah
    file dari frontend. Field `file` (wajib), `kind` = `image` (khusus gambar)
    atau `file` (default). Mengembalikan `{ path, url, mime, name, size }`
    (`url` siap dipakai). Butuh API key scope `read_write` atau JWT
    editor/superadmin — bukan endpoint publik.
  - Field `image`/`file` juga menerima path relatif hasil upload (mis.
    `2026/09/abc.png`) atau data URI `data:image/png;base64,....`.
  - File disimpan dengan nama acak & MIME diverifikasi via `finfo_file()`.
- **Entri dari API key:** `created_by` diisi `NULL` (bukan user) — dapat
  dikosongkan karena kolomnya nullable, aman untuk pengiriman form publik.
- **Rate limit:** per API key / per IP (default 120 req/60 detik, ubah lewat
  `.env`). Login dibatasi 5 percobaan / 15 menit per IP+username.
- **Required field:** hanya dieksekusi saat `status` bukan `draft`. Simpan
  sebagai `draft` memperbolehkan field wajib kosong; publish/archive wajib
  lengkap.
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

| Aksi                        | superadmin | editor | viewer |
|-----------------------------|:----------:|:------:|:------:|
| Kelola user & API keys      | ✓          | —      | —      |
| Kelola content types/fields | ✓          | —      | —      |
| Kelola taksonomi & term     | ✓          | ✓      | —      |
| Buat/edit/hapus entri       | ✓          | ✓      | —      |
| Import dari WordPress       | ✓          | ✓      | —      |
| Unggah/hapus media          | ✓          | ✓      | —      |
| Lihat entri/media/dashboard | ✓          | ✓      | ✓      |

## Media

- **Menu Media:** pustaka semua file yang diunggah (grid pratinjau, pencarian,
  hapus, copy URL, unggah langsung). File lama di `storage/uploads` yang belum
  tercatat dapat didaftarkan via tombol "Sinkron file lama".
- **Editor rich text:** gambar bisa disisipkan dengan copy-paste (screenshot/file)
  atau drag & drop; gambar otomatis diunggah ke CMS lalu disimpan lokal (atau
  di-offload bila R2/S3 aktif).

- **Settings → Media → Kompresi Gambar:** resize otomatis bila lebih lebar
  dari `media_max_width` (JPEG/WebP juga di-re-encode sesuai `media_quality`).
  Butuh ekstensi PHP **GD**.
- **Settings → Media → Offload (R2/S3-compatible):** setiap upload disalin ke
  bucket via SigV4 (tanpa SDK). Konfigurasi bisa dari halaman Settings atau
  env `MEDIA_ENDPOINT`, `MEDIA_REGION`, `MEDIA_BUCKET`, `MEDIA_ACCESS_KEY`,
  `MEDIA_SECRET_KEY`, `MEDIA_KEEP_LOCAL`, `MEDIA_PUBLIC_BASE` (env lebih aman
  untuk secret). Tombol "Tes Koneksi" tersedia di halaman Media.
- Jika `media_keep_local` dimatikan, file lokal dihapus setelah offload dan
  route `/files/…` mengalihkan (302) ke `media_public_base`.

## Import dari WordPress

- Menu **Tools → WordPress Import** (superadmin/editor) menerima file **WXR** dari
  Tools → Export. Halaman **Tools** menjadi daftar utilitas admin; import tidak
  perlu akses database WordPress.
- Alur: upload → pilih post type → petakan field → **verifikasi** → import
  bertahap (20 item per batch, tersedia opsi "proses otomatis").
- Field CMS dapat diisi dari judul/konten/excerpt/slug/tanggal/author atau meta
  key. Gambar unggulan WordPress (`_thumbnail_id`) tersedia sebagai sumber
  `post:thumbnail` dan otomatis dipilih untuk field image bernama `gambar`,
  `foto`, `thumbnail`, `featured_image`, dan sejenisnya. Tanggal ACF `Ymd`
  (date picker) dinormalisasi otomatis; nilai select di luar opsi dikosongkan
  dengan peringatan; field wajib yang belum terpetakan memblokir import (opsi
  "simpan sebagai draft" tetap tersedia).
- Gambar/file ACF diunduh ke `storage/uploads` (batas `MAX_UPLOAD_SIZE`, hanya
  host publik — proteksi SSRF, tanpa redirect). Sertakan media pada export agar
  attachment dapat di-resolve.
- Anti-duplikat memakai tabel `import_refs` (post ID WordPress → entry), jadi
  import ulang dapat memilih "lewati" atau "perbarui" tanpa entri ganda.
- Repeater ACF belum dipetakan otomatis (CMS belum punya tipe repeater); meta
  `repeater_0_*` dilaporkan pada halaman verifikasi.
- File kerja di `storage/tmp/import` dibersihkan otomatis setelah 24 jam. Batas
  ukuran file dapat diubah via env `WP_IMPORT_MAX_BYTES` (default 32 MB); untuk
  file sangat besar, pecah WXR atau naikkan `memory_limit` karena hasil parsing
  dimuat per batch.
- Parser WXR ditulis tanpa ekstensi XML: DOCTYPE/ENTITY ditolak, sehingga aman
  dari XXE dan tidak butuh `ext-simplexml`.

## Webhook & Rebuild SSG

Webhook keluar menghubungkan CMS ke frontend statis (Astro/Next/Hugo/Eleventy).
Setiap perubahan konten yang berdampak publik mengirim POST JSON ke endpoint
frontend atau langsung ke build hook platform, sehingga rebuild berjalan tanpa
membuat editor menunggu dan tanpa downtime.

Konfigurasi: **Settings → Webhooks** (superadmin). Fitur ini **nonaktif secara
default**.

### Alur

1. User/admin/API mempublikasikan, memperbarui, meng-unpublish, atau menghapus entry.
2. CMS menulis event ke tabel `webhook_deliveries` (antrean).
3. Response ke user dikirim lebih dulu (`fastcgi_finish_request` bila tersedia).
4. Worker mengirim POST ke URL webhook, maksimal 3 percobaan (jeda 30s dan 60s).
5. Endpoint frontend memverifikasi HMAC lalu memicu build/deploy (langsung atau queue).
6. Status dan error dicatat di **Settings → Webhooks** (20 delivery terakhir).

Pengiriman bersifat **at-least-once** dan **tidak berurutan**. Frontend harus
idempoten dan boleh menerima event yang sama lebih dari sekali.

### Konfigurasi

| Field | Keterangan |
|---|---|
| URL webhook | Endpoint `http/https` penerima POST. Tidak mengikuti redirect. |
| Secret | Opsional. Jika diisi, request ditandatangani HMAC SHA-256. Kosongkan input untuk mempertahankan secret lama. |
| Izinkan host privat | Default mati. Aktifkan bila SSG ada di `localhost`/jaringan Docker/internal. |
| Event | Pilih event yang memicu pengiriman. |
| Kirim Tes | Mengirim `webhook.test` secara sinkron memakai pengaturan tersimpan. |
| Proses Antrean Sekarang | Mencoba ulang delivery yang masih `pending`. |

### Event

| Event | Kapan dikirim | Field tambahan |
|---|---|---|
| `entry.published` | Create published, draft/archived → published, atau create/update via API | `status`, `via` (`admin`/`api`) |
| `entry.updated` | Entry yang **sudah published** disimpan/diubah lagi | `status`, `via` |
| `entry.unpublished` | Published → draft/archived | `status`, `via` |
| `entry.deleted` | Published entry dihapus permanen (API atau aksi hapus tunggal) | `status`, `via` |
| `entries.bulk` | Bulk publish/draft/trash/delete dari tabel entries (bukan restore) | `action`, `count`, `ids` (maks 100) |
| `entries.imported` | Import WordPress selesai dan ada entry dibuat/diperbarui | `imported`, `updated` |
| `webhook.test` | Tombol **Kirim Tes** | `message` |

Catatan:

- Edit pada entry **draft** tidak mengirim webhook.
- Restore dari Trash tidak mengirim webhook (hasilnya draft, belum publik).
- Hapus permanen dari baris tabel memakai `entries.bulk` (`action: "delete"`),
  bukan `entry.deleted`.
- `entries.imported` dikirim sekali per sesi import, bukan per entry.

### Payload

Semua request: `POST`, `Content-Type: application/json`, body JSON.

```json
{
  "event": "entry.published",
  "occurred_at": "2026-10-24T12:34:56+00:00",
  "site": {
    "title": "Nama Situs",
    "url": "https://cms.example.com"
  },
  "content_type": {
    "id": 3,
    "slug": "artikel",
    "label": "Artikel"
  },
  "entry": { "id": 42 },
  "actor": { "id": 1, "username": "admin" },
  "status": "published",
  "via": "admin"
}
```

`entry` dan `actor` dapat bernilai `null` (event bulk/import: `entry` null; aksi
via API key: `actor` null). `content_type` hanya null pada `webhook.test`.

Contoh `entries.bulk`:

```json
{
  "event": "entries.bulk",
  "occurred_at": "2026-10-24T12:40:00+00:00",
  "site": { "title": "Nama Situs", "url": "https://cms.example.com" },
  "content_type": { "id": 3, "slug": "artikel", "label": "Artikel" },
  "entry": null,
  "actor": { "id": 1, "username": "admin" },
  "action": "trash",
  "count": 3,
  "ids": [12, 13, 14]
}
```

Contoh `entries.imported`:

```json
{
  "event": "entries.imported",
  "occurred_at": "2026-10-24T12:45:00+00:00",
  "site": { "title": "Nama Situs", "url": "https://cms.example.com" },
  "content_type": { "id": 3, "slug": "artikel", "label": "Artikel" },
  "entry": null,
  "actor": { "id": 1, "username": "admin" },
  "imported": 120,
  "updated": 15
}
```

Payload **tidak berisi isi entry**. Bila frontend butuh slug/URL/judul untuk
rebuild incremental, panggil REST API CMS memakai `entry.id` dan
`content_type.slug` (butuh API key scope `read`).

### Header & signature

```
POST /api/rebuild HTTP/1.1
Content-Type: application/json
User-Agent: RinganCMS/<versi> Webhook
X-Ringan-Event: entry.published
X-Ringan-Signature: sha256=<hex-hmac>
```

Signature dihitung dari **raw body** (byte persis sebelum JSON di-parse):

```
signature = "sha256=" + hex(HMAC_SHA256(raw_body, secret))
```

- Jika secret kosong, header signature tidak dikirim.
- Verifikasi harus **constant-time** dan dilakukan sebelum `JSON.parse`.
- Jangan mengubah body (mis. normalisasi newline) sebelum verifikasi.

### Kontrak endpoint frontend

Endpoint/function yang dibuat frontend WAJIB:

1. Menerima `POST` saja; method lain → 405.
2. Membaca raw body, memverifikasi `X-Ringan-Signature` dengan secret dari env.
   Signature salah/kosong → 401 dan **jangan** memicu build.
3. Mengabaikan `webhook.test` untuk build (cukup balas 2xx). Ini mencegah tes
   koneksi memicu deploy sungguhan.
4. Membalas **2xx dalam < 5 detik**. Jangan menjalankan build di dalam request.
5. Memicu build lewat build hook platform, job queue, atau `repository_dispatch`.
   Build berjalan asinkron setelah response 2xx.
6. Idempoten: event bisa berulang dan tidak berurutan. Gunakan debounce 10-30
   detik, atau kunci unik `event + occurred_at + entry.id`.
7. Mengembalikan 4xx/5xx hanya bila build hook gagal dipicu; CMS akan retry.

Pilihan implementasi:

- **A. Langsung ke platform** — isi URL webhook dengan Netlify Build Hook,
  Cloudflare Pages Deploy Hook, Vercel Deploy Hook, atau GitHub
  `repository_dispatch`. Tidak perlu kode frontend, tetapi platform umumnya
  tidak memverifikasi HMAC. Lindungi dengan URL rahasia + HTTPS.
- **B. Endpoint verifier** — CMS → function/serverless frontend → verifikasi HMAC
  → panggil build hook/queue. Direkomendasikan bila ingin signature diverifikasi.

#### Contoh Astro (SSR / serverless function)

`src/pages/api/rebuild.ts` hanya berjalan runtime bila Astro memakai adapter SSR.
Untuk Astro static, letakkan kode yang sama di Netlify Function, Cloudflare
Pages Function, atau Vercel Function.

```ts
import crypto from 'node:crypto';
import type { APIRoute } from 'astro';

export const POST: APIRoute = async ({ request }) => {
  const raw = await request.text(); // raw body, jangan JSON.parse dulu
  const received = request.headers.get('x-ringan-signature') ?? '';
  const secret = import.meta.env.WEBHOOK_SECRET;
  const expected = 'sha256=' + crypto
    .createHmac('sha256', secret)
    .update(raw)
    .digest('hex');

  if (
    received.length !== expected.length ||
    !crypto.timingSafeEqual(Buffer.from(received), Buffer.from(expected))
  ) {
    return new Response('invalid signature', { status: 401 });
  }

  const payload = JSON.parse(raw);
  if (payload.event === 'webhook.test') {
    return Response.json({ ok: true, test: true }, { status: 202 });
  }

  // Jangan build di sini. Panggil build hook / queue.
  const hook = import.meta.env.BUILD_HOOK_URL;
  const res = await fetch(hook, { method: 'POST' });
  if (!res.ok) return new Response('build hook failed', { status: 502 });

  return Response.json({ ok: true, event: payload.event }, { status: 202 });
};
```

#### Contoh Next.js (App Router)

`app/api/rebuild/route.ts`:

```ts
import crypto from 'node:crypto';

export const runtime = 'nodejs';

export async function POST(req: Request) {
  const raw = await req.text();
  const received = req.headers.get('x-ringan-signature') ?? '';
  const secret = process.env.WEBHOOK_SECRET ?? '';
  const expected = 'sha256=' + crypto
    .createHmac('sha256', secret)
    .update(raw)
    .digest('hex');

  if (
    received.length !== expected.length ||
    !crypto.timingSafeEqual(Buffer.from(received), Buffer.from(expected))
  ) {
    return Response.json({ error: 'invalid signature' }, { status: 401 });
  }

  const payload = JSON.parse(raw);
  if (payload.event === 'webhook.test') {
    return Response.json({ ok: true, test: true }, { status: 202 });
  }

  const res = await fetch(process.env.BUILD_HOOK_URL!, { method: 'POST' });
  if (!res.ok) return Response.json({ error: 'build hook failed' }, { status: 502 });

  return Response.json({ ok: true, event: payload.event }, { status: 202 });
}
```

#### Rebuild parsial (opsional)

Untuk situs besar, jangan selalu full rebuild:

- `entry.published` / `entry.updated`: rebuild path entry tersebut (ambil slug via
  REST API) atau full rebuild.
- `entry.unpublished` / `entry.deleted`: rebuild daftar + hapus halaman lama.
- `entries.bulk`: full rebuild, atau gunakan `ids` + `action`.
- `entries.imported`: full rebuild.
- `webhook.test`: tidak membangun apa pun.

### Delivery & retry

- Sukses = HTTP **2xx**. Status lain dianggap gagal. Body response tidak dipakai.
- Timeout: connect 3s, total 5s. Redirect tidak diikuti.
- Maksimal 3 percobaan (jeda 30s, lalu 60s); setelah itu status `failed`.
- Antrean diproses setelah response selesai. Bila PHP-FPM tidak tersedia,
  pengiriman tetap berjalan setelah output di-flush (koneksi bisa sedikit lebih lama).
- Riwayat dan error tersedia di **Settings → Webhooks**; tombol **Proses Antrean
  Sekarang** mencoba ulang delivery `pending`.

### Keamanan

- Gunakan HTTPS dan secret acak yang kuat; simpan secret hanya di server frontend.
- Jangan menaruh secret di bundle client (`NEXT_PUBLIC_*`, `PUBLIC_*`).
- Verifikasi signature **sebelum** melakukan efek samping apa pun.
- Host privat diblokir kecuali **Izinkan host privat/localhost** diaktifkan.
- Secret disimpan di tabel `settings` CMS (bukan di repo). Rotasi dengan mengganti
  secret di Settings; delivery lama yang masih pending akan ditandatangani dengan
  secret baru.
- Batasi efek: endpoint hanya memicu build, bukan operasi destruktif.

### Troubleshooting

| Gejala | Penyebab / solusi |
|---|---|
| Tidak ada delivery | Webhook nonaktif, URL kosong, atau event tidak dicentang. |
| `401 invalid signature` | Secret beda, body diubah proxy, atau verifikasi dilakukan setelah parse/transform. |
| `403`/host ditolak | URL privat; aktifkan **Izinkan host privat/localhost**. |
| Delivery `failed` | Endpoint tidak 2xx, timeout > 5s, atau redirect. Balas 2xx cepat dan picu build secara async. |
| Tabel belum ada | Jalankan migrasi `database/migrations/010_webhooks.sql`. |
| Build dobel | Event at-least-once. Tambahkan debounce/idempotency di frontend. |

### Checklist agent frontend

1. Buat endpoint `POST /api/rebuild` (atau function setara).
2. Verifikasi HMAC raw body; tolak tanpa signature bila secret diset.
3. Abaikan `webhook.test` untuk build; balas 2xx.
4. Balas 2xx < 5 detik; picu build lewat hook/queue, bukan build inline.
5. Tambahkan debounce/idempotency untuk burst event.
6. Simpan `WEBHOOK_SECRET` dan `BUILD_HOOK_URL` sebagai env server.
7. Isi URL + secret di **Settings → Webhooks**, centang event, simpan.
8. Klik **Kirim Tes**, pastikan HTTP 200 dan log `delivered`.
9. Publish satu entry, pastikan build berjalan dan halaman baru muncul tanpa downtime.

## Pembaruan

- Menu **Updates** di dashboard menampilkan versi terbaru (cache 6 jam) dan
  tombol update interaktif.
- **Default** Update URL diarahkan ke GitHub Releases project ini
  (`https://api.github.com/repos/stefanusade/ringan-cms/releases/latest`).
  Syarat: repo harus **publik**, dan setiap release wajib memiliki **file
  paket `.zip`** sebagai asset (checksum SHA-256 dari GitHub otomatis
  diverifikasi). Bisa diganti dengan manifest JSON kustom:
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
