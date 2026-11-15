=== Ringan Export ===
Contributors: ringancms
Tags: export, acf, migration, headless, zip
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ekspor post/CPT WordPress beserta field ACF dan media ke satu ZIP sesuai profil JSON Ringan CMS.

== Description ==

Plugin ini menjembatani WordPress dan Ringan CMS:

1. Ekspor post/CPT + seluruh field ACF + media (gambar/file) ke satu file ZIP.
2. ZIP memuat `manifest.json` (format `ringan-export`) yang berisi profil pemetaan
   dari Ringan CMS, daftar entry, dan indeks media.
3. Unggah ZIP tersebut di Ringan CMS melalui **Tools → Import ZIP**. CMS akan
   memvalidasi manifest, mengimpor media lewat pemeriksaan MIME, lalu membuat
   entri sesuai content type tujuan.

Fitur:

* Profil JSON dapat ditempel manual atau diambil dari endpoint
  `GET /api/v1/{slug}/profile` milik Ringan CMS (opsional dengan `X-API-Key`).
* Pemetaan field: `post.title`, `post.content`, `post.excerpt`, `post.slug`,
  `post.date`, `post.modified`, `post.status`, `post.author`,
  `post.thumbnail`, atau `acf.<key>`.
* Transformasi nilai: text, HTML/richtext, tanggal, angka, boolean, select
  (dengan `value_map`), media, galeri, relasi, dan raw.
* Media hanya diambil dari folder uploads WordPress (tidak ada pengambilan URL
  eksternal), dengan batas ukuran dan whitelist MIME yang sama seperti CMS.
* Ekspor bertahap (batch 20 post per request, maksimal 5000 post dan 19000 media
  per ekspor) agar aman untuk situs besar.

== Requirements ==

* WordPress 6.0+ dan PHP 8.0+.
* Ekstensi PHP `ZipArchive` (ext-zip).
* Advanced Custom Fields (opsional; tanpa ACF hanya sumber `post.*` yang tersedia).

== Installation ==

1. Salin folder `ringan-export` ke `wp-content/plugins/`.
2. Aktifkan plugin melalui menu Plugins.
3. Buka **Tools → Ringan Export**.
4. Tempel profil JSON dari Ringan CMS (**Content Types → Profil**) atau ambil
   lewat URL `/api/v1/{slug}/profile`.
5. Pilih post type dan status, klik **Mulai Ekspor**, lalu unduh ZIP.
6. Di Ringan CMS buka **Tools → Import ZIP** dan unggah ZIP tersebut.

== Notes ==

* Field repeater/flexible content ACF belum dipetakan otomatis karena Ringan CMS
  belum memiliki tipe field tersebut.
* Relasi ACF diekspor sebagai ID post sumber; CMS mencocokkannya lewat tabel
  `import_refs` (relasi yang belum diimpor akan dilewati dengan peringatan).
* Impor ulang aman: CMS memakai `source_id` (ID post WordPress) untuk mencegah
  duplikasi.

== Changelog ==

= 1.0.0 =
* Rilis awal: ekspor post/CPT + ACF + media ke ZIP sesuai profil Ringan CMS.