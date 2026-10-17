# WPicker — Context

Ringkasan inti proyek. Sumber: `PRD.md`, `README.md`, `DEPLOYMENT.md`, lalu dicek ke kode (`cli/`, `plugin/`, `Makefile`, `scripts/`). Kalau dokumen dan kode beda, kode yang dipakai di sini.

## Masalah yang dipecahkan

Develop WordPress itu susah. Dua titik sakit:

1. **Kerja paling enak di lokal pakai AI.** Semua perubahan dibuat di lokal, dibantu agent.
2. **Setor perubahan lokal ↔ server ribet.** Cara lama: All-in-One backup → export → upload → restore, tiap kali ada perubahan, dua arah. Lambat, berat, banyak langkah manual.

Inti tool: **memudahkan develop WordPress** — edit di lokal dengan AI, sync ke server (dan sebaliknya) cukup satu perintah.

Ukuran sukses: `pull` → edit → `push` menggantikan siklus backup/upload manual.

## Apa ini

Jembatan antara AI agent lokal dan site WordPress live. Nama = plesetan *woodpecker*.
Alternatif ringan untuk MCP server: output JSON ringkas, hemat token, kurangi hallucination.

| Bagian | Julukan | Stack | Lokasi | Peran |
|---|---|---|---|---|
| Plugin | The Eyes | PHP 7.4+ | Site WordPress | REST API, context, lint gate, Deployment Vault, manajemen perangkat, scanner |
| CLI | The Hands | Go 1.21+, cobra | Lokal / CI / agent | Login, context, pull/push, history, rollback, page, scan |

Versi di kode: **1.0.0** (`plugin/wpicker.php`, `cli/cmd/root.go`). Tag git: `v1.0.0`.

## Struktur repo

```
cli/
  main.go
  cmd/                 perintah cobra: login, context, theme, history, rollback, page, scan, device, init, update
  internal/auth/       alur login PIN
  internal/client/     HTTP client + method per endpoint (api.go)
  internal/config/     ~/.wpicker/sites.json (multi-site, flock) + config.json lama (hanya dibaca/diimpor)
  internal/sync/       walk, sha256, diff, tulis atomik, manifest lokal
  internal/migrate/    restore backup: ekstrak tar, baca wp-config, impor SQL, ganti URL (sadar PHP-serialized)
  internal/tui/        output terminal / JSON
plugin/
  wpicker.php          bootstrap
  includes/Core.php    wiring + registrasi route
  includes/REST/       Context, Device, Theme, Vault, Page, Vuln
  includes/Auth/       Pin, Capability
  includes/Sync/       PathGuard (kunci ke child theme), Deployer (transaksi push)
  includes/Lint/       PhpLint (php -l)
  includes/Health/     Probe (health check setelah push)
  includes/Inspect/    Logs, Files, Database (penjaga query), Redactor, Audit — diagnosa read-only
  includes/Backup/     Job (backup bertahap + unggahan), Tar, Dumper, Restore (restore ke situs ini), Rewriter (ganti URL aman-serialized)
  includes/Vault/      Manager (snapshot/restore/prune), Manifest
  includes/Context/    Provider
  includes/Builder/    Detector (Divi/Elementor/Gutenberg)
  includes/Security/   Scanner (wpvulnerability.net)
  includes/System/     Updater (GitHub releases)
  admin/               views: header, devices, vault (Snapshots), vuln, settings
  tests/php/           PHPUnit
local-dev/wpicker-child/   child theme contoh untuk wp-env
scripts/release.sh     rilis ke GitHub
install.sh             install/remove CLI
```

## Perintah CLI (sesuai kode)

Flag global: `--site <url>` (override site), `--json` (output mesin, untuk agent), `-v`.

| Perintah | Fungsi |
|---|---|
| `wpicker login` | Pairing via PIN 6 digit → Application Password per perangkat |
| `wpicker sites` (+ `login/stop/start/delete/use/link/unlink`), `whoami` | Daftar site + status + folder; site mana yang dipakai folder ini dan kenapa. `logout` kini alias tersembunyi `sites delete --local-only` |
| `wpicker context [--save]` | Konteks site. `--save` → `./.wpicker/context.json` |
| `wpicker connect [--agent …]` | Tulis instruksi untuk agent AI (skill `/wpicker` Claude Code, `AGENTS.md`, Cursor, Gemini, Copilot, Windsurf, Cline); `--refresh` menyegarkan yang sudah ada. Dipanggil otomatis oleh `install.sh`, `login`, dan `update`. `init` sudah dihapus. |
| `wpicker guide` | Cetak panduan lengkap untuk agent |
| `wpicker theme files` | Daftar file child theme di server |
| `wpicker theme status` | Seperti `git status`: yang perlu di-push, yang bisa di-pull, konflik |
| `wpicker theme diff [path...]` | Diff baris per baris lokal vs server (pakai `diff` sistem) |
| `wpicker theme pull` | Tampilkan daftar perubahan di server, pilih mana yang di-apply (nomor, rentang, `a` semua yang aman, `d <n>` lihat diff) |
| `wpicker theme pull <path...>` | Apply hanya file itu; file yang berubah di dua sisi digabung per baris |
| `wpicker theme pull --all` | Apply semua, menggabung bila perlu. Ini juga perilaku tanpa terminal / `--json` |
| `wpicker theme pull --theirs <path>` | Konflik: ambil versi server, tanpa merge |
| `wpicker theme pull --force` | Lokal dijadikan sama dengan server, edit lokal dibuang (file yang hanya ada di lokal tetap) |
| `wpicker theme pull --keep-local <path>` | Selesaikan konflik dengan versi lokal; push berikutnya menimpa server |
| `wpicker theme pull --dry-run` | Daftar saja |
| `wpicker theme push [--out dir] [--dry-run] [--all]` | Kirim file berubah; lint + snapshot di server |
| `wpicker snapshots` (alias `history`) `[--limit 30]` | Daftar snapshot beserta status |
| `wpicker rollback <manifest-id-atau-prefix>` | Pulihkan child theme dari snapshot |
| `wpicker page create --title --builder --file` | Buat page (divi / elementor / gutenberg) |
| `wpicker page update <post-id> --builder --file [--title]` | Update page |
| `wpicker scan` (alias `vuln`) | Cek plugin terpasang ke WPVulnerability. Exit 1 kalau ada temuan |
| `wpicker backup list` / `create` / `delete <id>` / `download <id>` | Backup situs penuh di server |
| `wpicker backup restore [id] --path <dir>` | Pilih backup, bangun ulang di WordPress lokal (file + DB + ganti URL) |
| `wpicker clone --path <dir>` | Backup baru + restore sekaligus |
| `wpicker device list` / `revoke <id>` | Kelola perangkat terpasang |
| `wpicker update` | Update CLI dari GitHub release |

## REST API plugin

Namespace: `/wp-json/wpicker/v1`. Auth: Basic dengan Application Password (kecuali pairing). Route tanpa `permission_callback` otomatis ditolak.

| Method | Path | Capability |
|---|---|---|
| GET | `/context` | `edit_theme_options` |
| GET | `/device/challenge` | `edit_themes` (sesi WP-Admin) |
| POST | `/device/challenge` | username + password di body |
| POST | `/device/register` | username + password di body, + PIN |
| GET | `/device/pairing` | `edit_themes` (sesi) — status pairing + PIN yang sedang aktif |
| DELETE | `/device/pairing` | `edit_themes` (sesi) — hentikan pairing, PIN dibuang |
| GET | `/device` | `manage_options` |
| DELETE | `/device/{id}` | `manage_options` |
| GET | `/theme/files` | `edit_themes` |
| GET | `/theme/file?path=` | `edit_themes` |
| POST | `/theme/push` | `edit_themes` |
| GET | `/history`, `/history/{id}` | `edit_themes` |
| POST | `/rollback` | `edit_themes` |
| GET | `/snapshot/{id}/diff?path=&mode=change\|restore` | `edit_themes` |
| POST | `/snapshot/{id}/apply`, `/snapshot/{id}/reject` | `manage_options`, hanya sesi WP-Admin |
| POST | `/snapshot/{id}/revert` | `manage_options`, hanya sesi WP-Admin |
| POST | `/snapshot/delete` | `manage_options`, hanya sesi WP-Admin |
| GET / POST | `/backup` | `manage_options` + setting `allow_backup` — daftar / mulai |
| POST | `/backup/{id}/step` | sama — lanjutkan beberapa detik |
| GET | `/backup/{id}/download` | sama — arsip mentah, dukung `Range` |
| DELETE | `/backup/{id}` | sama |
| POST | `/backup/upload`, `/backup/{id}/chunk?offset=`, `/backup/{id}/finish` | sama — terima file backup dari luar |
| POST / DELETE | `/restore` | sama, hanya sesi WP-Admin — mulai / lupakan restore ke situs ini |
| GET | `/restore` | sama — status |
| POST | `/restore/step` | token restore (tanpa login) |
| POST | `/page`, `/page/{id}` | `manage_options` |
| GET | `/vuln` | `manage_options` |

## Cara kerja sync

**Tiga sisi:** tiap file dibandingkan antara lokal, server, dan *base* = hash saat pull terakhir (`./.wpicker/last-pull.json`). Logika di `cli/internal/sync/status.go`.

| Lokal vs base | Server vs base | Status | Ditangani oleh |
|---|---|---|---|
| berubah | sama | To push | `push` |
| sama | berubah | To pull | `pull` |
| berubah | berubah, isi beda | Conflict | `pull` **menggabung per baris** (lihat bawah); atau pilih sisi: `pull --theirs <path>` / `pull --keep-local <path>` |
| berubah | berubah, isi sama | — | base diperbarui diam-diam |

**Pull:** `GET /theme/files` → hitung status → tampilkan daftar bernomor (`A`/`M`/`D`/`C`) → apply hanya yang dipilih, lewat `GET /theme/file?encoding=base64` per file, tulis atomik. Base diperbarui **per file**: yang tidak di-apply tetap muncul di pull berikutnya. Edit lokal tidak pernah ditimpa kecuali file itu dipilih atau `--force`.

**Merge tiga arah** (`cli/internal/sync/merge.go`, `base.go`). Untuk file yang berubah di dua sisi, `pull` menggabung per baris terhadap *isi* versi bersama terakhir, yang disimpan di `.wpicker/base/<path>` (diperbarui tiap pull, push, dan saat dua sisi diketahui sama):

- Perubahan di baris berbeda → digabung otomatis ("merged cleanly").
- Baris yang sama diubah berbeda → dua versi ditulis di antara penanda git standar `<<<<<<< yours` / `=======` / `>>>>>>> site (device, waktu)`. Editor (VS Code, Cursor) menampilkan kontrol merge-nya; agent bisa menyelesaikannya.
- Setelah merge, versi server jadi base; file hasil = perubahan lokal biasa yang menunggu `push`.
- `push` menolak file yang masih berisi penanda. `status` menandainya.
- Salinan lokal sebelum merge: `.wpicker/merge-backup/<path>`.
- Tidak bisa digabung (tetap konflik, pilih sisi): file biner, satu sisi menghapus, atau base tidak tercatat.
- `status` menyebut siapa yang terakhir mem-push tiap file di server (dari riwayat snapshot).

Dibandingkan dengan `git merge-file` pada 600 kasus acak: 460 hasil bersih identik, 133 sama-sama konflik, 2 kita lebih hati-hati, 4 git konflik tapi kita menggabung (semua karena baris kembar yang bisa dipasangkan dua cara).

Path yang diabaikan (`sync.IsIgnored`: dotfile, `.git`, `vendor`, `node_modules`, `dist`, `build`, `.sass-cache`) tidak di-pull, tidak di-push, tidak dijadwalkan hapus.

**Push:**
1. CLI hitung status tiga sisi; yang dikirim hanya "To push".
2. Ada konflik → push ditolak, suruh pull dan pilih sisi. `push --all` juga ditolak kalau server punya perubahan yang belum di-pull.
3. CLI kirim `files` (isi base64, aman untuk biner) + `delete` (file yang dihapus lokal).
4. Server: validasi semua path lewat `PathGuard` (di luar child theme / `wp-config.php` → tolak 400).
5. Server: `php -l` tiap file `.php` → gagal = 422 `wpicker_lint_syntax` + `file` + `line`. Belum ada yang ditulis.
6. Server: health check awal (site sehat sebelum push?), lalu snapshot seluruh child theme ke Vault.
7. Server: tulis file (temp + rename), hapus file, buang folder kosong, `opcache_invalidate`. Gagal di tengah → theme dikembalikan ke snapshot.
8. Server: **health check** — request ke halaman depan dan ke `/wp-json/wpicker/v1` dari site sendiri. Ada 5xx → theme dikembalikan persis ke snapshot, manifest `aborted`, balas 422 `wpicker_health_failed` + pesan fatal dari log PHP + `file` + `line` + `rolled_back: true`.
9. Sukses → respons berisi `applied`, `deleted`, `health` (`ok` / `unreachable` / `already_failing` / `skipped`).
10. Gagal → CLI tulis `./.wpicker/last-error.json` untuk dibaca agent.

Health check: `includes/Health/Probe.php`. Matikan lewat `wpicker_settings['health_check'] = 0`. Kalau site sudah 5xx sebelum push (`already_failing`), push tidak di-revert — bisa jadi itu perbaikannya.

**Vault:** di dalam direktori uploads, dilindungi `.htaccess`. Simpan 30 snapshot terakhir (`vault_keep`). Rollback bikin snapshot pengaman dulu (`rollback_safety`), lalu theme dijadikan salinan persis snapshot (file yang tidak ada di snapshot dihapus).

**Login:** CLI kirim username + password admin di body `POST /device/challenge` → server verifikasi (`wp_authenticate`) → PIN 6 digit dibuat, **tidak** dikirim balik ke CLI; user baca di WP-Admin → WPicker → `POST /device/register` + PIN → Application Password per perangkat. Password hanya diterima lewat HTTPS atau `WP_ENVIRONMENT_TYPE=local`. 5× gagal (password atau PIN) per username + IP → kunci 15 menit (429). PIN berlaku `pin_ttl` detik (default 120), sekali pakai.

Kartu **Pair a new device** (WP-Admin → Devices) mengikuti status server lewat polling 3 detik: menampilkan PIN yang dibuat di halaman maupun yang diminta `wpicker login`, hitung mundur, **Regenerate** (PIN lama mati), **Stop pairing** (PIN dibuang), dan reload sendiri saat device berhasil masuk. PIN tidak lagi diperbarui otomatis saat habis.

## Mode push dan review

Setting **WP-Admin → WPicker → Settings → When a device pushes** (`wpicker_settings['push_mode']`):

| Mode | Perilaku |
|---|---|
| `direct` (default) | Lint → snapshot → apply → health check. Balas 201 |
| `review` | Lint → file ditahan di `uploads/wpicker-vault/<id>.staged/`, status `review`. Balas 202. Site belum berubah sampai admin klik **Apply** di halaman Snapshots |

Aturan mode review:

- Apply / Reject hanya dari sesi WP-Admin. Application Password ditolak (403) — device tidak bisa menyetujui push-nya sendiri.
- Syntax error ditolak sebelum masuk antrean.
- Push baru dari device yang sama menggantikan yang lama (`superseded`).
- Apply ditolak (`wpicker_stale`, 409) kalau file target di server berubah sejak push diajukan.
- Apply menjalankan transaksi yang sama dengan mode direct: fatal → revert, entri jadi `aborted`.
- CLI mencatat yang diajukan di `last-pull.json` → `pending`; begitu server memuat isi itu (disetujui), base maju sendiri. Tanpa ini, edit berikutnya dianggap konflik.

Status snapshot: `review`, `applied`, `aborted` (Failed), `reverted`, `restored`, `rejected`, `superseded`, `pending` (Saved — snapshot diambil, mis. safety backup).

Dua cara mundur, beda arti:

| | Restore (`POST /rollback`, `wpicker rollback <id>`) | Revert (`POST /snapshot/{id}/revert`, tombol di modal) |
|---|---|---|
| Efek | Seluruh tema kembali ke keadaan sebelum entri itu | Hanya perubahan push itu dibatalkan |
| Push sesudahnya | Ikut hilang | Tetap |
| Konflik | Tidak ada (menimpa) | Ditolak (`wpicker_revert_conflict`) kalau file yang sama berubah lagi setelahnya |

Revert berjalan lewat transaksi yang sama dengan push (lint, snapshot, health check) dan membuat entri baru jenis `revert`; entri asal jadi `reverted`. Entri `revert` bisa di-revert lagi. Hanya sesi WP-Admin; belum ada di CLI.

**Resolusi konflik.** Dua keadaan di mana perubahan bertubrukan tidak lagi ditolak mentah:

| Aksi | Konflik | Kode 409 |
|---|---|---|
| Revert | File push itu sudah diubah lagi oleh push berikutnya | `wpicker_revert_conflict` |
| Apply (push yang ditahan) | File target berubah di server sejak push diajukan | `wpicker_stale` |

Server membalas daftar file (`files`, `resolvable: true`); modal menampilkan tiap file dengan dua pilihan dan tombol Compare (diff "yang ada di site sekarang" vs "yang akan ditulis"). Permintaan diulang dengan `resolve: { "<path>": "apply" | "skip" }`: `apply` = aksi ini menang untuk file itu (perubahan yang lebih baru hilang), `skip` = file dibiarkan, sisanya tetap dijalankan. Semua file konflik harus diputuskan. File yang dilewati dicatat di Log.

Halaman **Snapshots**:

- Kartu ringkasan, search (ID, device, nama file), filter status / jenis / device.
- Pagination di browser: 5 baris per halaman (default), pilihan 10 / 50 / 100 / All, diingat di `localStorage`.
- Klik baris → modal hampir selebar layar: daftar file di kiri (nama file, folder, tanda A/M/D, jumlah baris +/−; kepala daftar menampilkan folder tema dan total), diff di kanan (path lengkap, aksi, Copy path), tab Log, tombol Revert / Restore / Apply / Reject / Delete. Rincian per file dihitung server di `GET /history/{id}`.
- Diff: `GET /snapshot/{id}/diff` mengembalikan baris terstruktur (`ctx` / `chg` / `gap`, 3 baris konteks, dari `Text_Diff` bawaan WordPress); `admin.js` merender Split atau Unified dengan nomor baris dan penanda bagian baris yang berubah. Untuk push yang sudah applied, "sesudah" diambil dari salinan di `<id>.after/`.
- Hapus: satu (dari modal), terpilih (checkbox + bar aksi), atau semua. `POST /snapshot/delete` dengan `{ids}` atau `{all:true}`; hanya sesi WP-Admin. Yang dihapus: record, folder snapshot, `.after`, `.staged`. Theme tidak disentuh.

Logika transaksi ada di `includes/Sync/Deployer.php` (dipakai `REST/Theme.php` untuk push dan `REST/Vault.php` untuk Apply).

## Backup dan clone situs penuh

Satu backup = satu file `.tar` berisi seluruh situs. Disimpan di server, terdaftar di **WP-Admin → WPicker → Backups** dan di `wpicker backup list`.

Isi arsip:

| Entri | Isi |
|---|---|
| `wpicker-backup.json` | Asal backup: URL, ABSPATH, prefix tabel, versi, tema/plugin aktif, bagian yang disertakan |
| `database.sql` | Semua tabel ber-prefix situs. **Satu statement per baris** (newline di data sudah di-escape), jadi importer cukup jalankan baris demi baris |
| `wp-content/…` | `plugins`, `mu-plugins`, `themes`, `uploads` |

**Membuat (server, `includes/Backup/`):** job bertahap — klien memanggil `POST /backup/{id}/step` berulang, tiap langkah kerja ±6 detik (fase `db` → `list` → `files` → `done`). Tar dipilih karena bisa ditambah terus tanpa menulis ulang (zip tidak). Folder job bernama `<id>-<token acak 32 hex>` di `uploads/wpicker-vault/backups/`; token tidak pernah dikirim ke klien, karena `.htaccess` tidak berlaku di nginx. Tidak ikut: `wpicker-vault`, `node_modules`, `.git`, backup plugin lain, dan symlink. Retensi: `backup_keep` (default 3).

**Restore ke lokal (CLI, `cli/internal/migrate/`):** `wpicker backup restore [id] --path <folder WordPress lokal>`

1. Pilih backup (id, `--latest`, atau pilih dari daftar), unduh ke `~/.wpicker/backups/` (bisa dilanjutkan kalau putus).
2. Baca koneksi DB dari `wp-config.php` lokal; site milik app Local dideteksi lewat `sites.json` dan socket MySQL-nya dipakai. Bisa ditimpa dengan `--db-host/--db-port/--db-socket/--db-name/--db-user/--db-pass`.
3. Tampilkan apa yang akan diganti, minta konfirmasi (`--yes` untuk skrip).
4. Simpan keadaan lokal ke `<path>/wpicker-before-restore-<waktu>/` (dump DB + folder wp-content lama).
5. Ekstrak file (hanya `wp-content/…`, tiap path dicek tidak keluar folder).
6. Muat SQL, lalu ganti URL dan path server → lokal di semua kolom teks. Data PHP-serialized diurai dan panjang string-nya dihitung ulang (`phpserial.go`), supaya tidak rusak.

`wpicker clone --path …` = buat backup baru + restore + hapus backup itu dari server.

**Restore ke situs ini (server, `includes/Backup/Restore.php`)** — WP-Admin → WPicker → **Backup & Restore** → tombol Restore, atau unggah file `.tar` dulu ("Restore from a file", potongan 2 MB lewat `POST /backup/upload` → `/chunk` → `/finish`).

Fase: `safety` (backup situs saat ini, opsional) → `scan` → `db` → `rewrite` → `files` → `swap` → `done`.

- **Database dimuat ke tabel bayangan `wpkr_*`**, bukan ke tabel hidup: WordPress harus tetap bisa boot untuk tiap request langkah. Di akhir, satu `RENAME TABLE` menukar semuanya sekaligus.
- Hanya statement buatan `Dumper` yang dijalankan (`DROP TABLE IF EXISTS` / `CREATE TABLE` / `INSERT INTO` pada tabel ber-prefix backup); sisanya dilewati. Arsip unggahan = input tak tepercaya.
- Ganti URL/path oleh `Backup/Rewriter.php` (padanan `phpserial.go`; mengurai teks, tidak pernah `unserialize()`).
- Sebelum tukar, ke tabel bayangan ditulis: `siteurl`/`home` situs ini, plugin WPicker tetap aktif, dan option yang milik situs ini (`wpicker_settings`, `wpicker_backups`, `wpicker_vault_manifests`, `wpicker_restore`). Prefix tabel berbeda ikut disesuaikan (`user_roles`, kunci usermeta).
- File ditimpa di tempat; file yang tidak ada di backup dibiarkan; folder plugin WPicker tidak pernah disentuh.
- **Otorisasi:** memulai restore hanya dari sesi WP-Admin. Langkah berikutnya memakai token sekali pakai (`X-WPicker-Restore-Token`), karena setelah `swap` user yang memulai sudah tidak ada. Browser mengirim langkah tanpa cookie dan tanpa nonce.
- Setelah selesai: login ulang dengan akun dari backup.
- Retensi (`backup_keep`) dijalankan saat backup **selesai**; backup yang sedang di-restore dan salinan pengamannya dilindungi.

**Penggantian alamat** (`Backup/Rewriter.php` dan `cli/internal/migrate/phpserial.go`, harus selalu sepadan). Satu lintasan, kecocokan terpanjang dulu:

- `http://` dan `https://` → alamat baru; varian dengan dan tanpa `www.`; `//host`; bentuk tanpa skema di atribut (`="host/…`); slash ter-escape JSON; `urlencode` dan `rawurlencode`.
- Path instalasi, dan path `wp-content` kalau letaknya di luar instalasi.
- **Cek batas:** kecocokan harus berakhir tepat di akhir alamat lama. `example.com` tidak menyentuh `example.com.au`, `example.community`, atau folder `site2` / `site.bak`.
- Tabel tanpa primary key tunggal: tiap nilai berbeda diganti di semua baris yang memuatnya.
- Alamat email di domain lama **tidak** diganti (sengaja).

**Setelah database dimuat** (keduanya): transient dan `rewrite_rules` dibuang (dibangun ulang WordPress), plugin pemaksa HTTPS dimatikan kalau alamat tujuan bukan HTTPS. Waktu ubah file dipulihkan dari arsip (banyak tema memakainya sebagai `?ver=`). Restore WP-Admin punya opsi "exact copy" (default aktif): file di folder yang dipulihkan tapi tidak ada di backup dihapus.

**Uji mirror** (`scripts/mirror/`): `seed.php` mengisi sumber dengan data sulit (semua ejaan alamat, serialized bersarang/objek/multibyte, baris 700 KB, tabel tanpa key, domain mirip); `run.sh` backup → restore ke tujuan → membuktikan: database identik setelah alamat tiap situs dinormalkan (`dump.php`, tidak memakai Rewriter; membaca nilai dengan `unserialize` PHP), tidak ada alamat sumber tersisa, file identik isi + waktu, halaman dirender sama. Hasil 2026-10-13, kedua jalur: 14 tabel, 209 baris, 406 file, 0 beda.

CLI belum bisa memicu restore di server atau mengunggah file backup; `wpicker backup restore` tetap berarti restore ke WordPress lokal.

## Diagnosa (read-only)

Untuk mencari penyebab masalah di server, oleh orang atau agent. **Mati secara default**: `wpicker_settings['allow_inspect']`, dinyalakan di Settings → Diagnostics. Semua akses dicatat (`Inspect\Audit`, option `wpicker_audit`, 200 terakhir) dan tampil di Settings.

| CLI | REST | Isi |
|---|---|---|
| `wpicker logs` | `GET /inspect/logs` | Log PHP (`error_log`), `wp-content/debug.log`, dan log privat WPicker; diurai, dikelompokkan per pesan+file+baris dengan jumlah dan waktu pertama/terakhir |
| `wpicker debug on\|off\|status` | `POST /inspect/debug`, `GET /inspect/status` | Setting `debug_log` |
| `wpicker files ls\|cat\|grep` | `GET /inspect/files`, `/file`, `/grep` | Baca `wp-content` |
| `wpicker db tables\|query` | `GET /inspect/db/tables`, `POST /inspect/db/query` | Query read-only |

**Mode debug** (`Inspect\Logs::debug_boot()`, dipanggil paling awal di `wpicker.php`). `WP_DEBUG` adalah konstanta di `wp-config.php` dan tidak bisa diubah saat jalan, jadi dibuat padanannya: `error_reporting(E_ALL)`, `display_errors` mati, log ke file privat `uploads/wpicker-vault/logs/debug-<token>.log` (kecuali `WP_DEBUG_LOG` sudah aktif). Peringatan *deprecated* dan *doing it wrong* WordPress terkunci pada `WP_DEBUG` di core, jadi ditangkap lewat action `*_run` dan ditulis sendiri ke log. Error sebelum plugin dimuat (di `wp-config.php` / mu-plugin awal) tidak tertangkap. Log dipangkas ke 1 MB terakhir saat melewati 5 MB.

**File** (`Inspect\Files`): path relatif `wp-content`, diselesaikan dengan `realpath()` lalu dicek prefix — menutup `..` dan symlink keluar. Ditahan: `wpicker-vault`, folder backup plugin lain, `.git`, dan nama file kredensial/dump (`.env*`, `*.pem`, `*.key`, `*.sql`, `*.log`, `wp-config*.php`, …).

**Database** (`Inspect\Database`), berlapis:

1. Bentuk statement: satu statement, tanpa komentar, diawali `SELECT`/`WITH`/`SHOW`/`DESCRIBE`/`EXPLAIN`; tanpa `INTO`, `LOAD_FILE`, `SLEEP`, dll.; skema `mysql`/`performance_schema`/`sys` ditolak; `SHOW` dibatasi (tanpa `GRANTS`, `PROCESSLIST`).
2. **Kredensial tidak pernah masuk query.** Setiap rujukan ke `users`, `usermeta`, `options` diganti sub-select: `users` tanpa `user_pass`/`user_activation_key`; `usermeta` dan `options` tanpa baris yang namanya cocok pola kredensial (`Redactor::NAME`), tanpa session, application password, salt, `wpicker_*`, transient. Karena dibuang di sumber, tidak bisa dijangkau lewat ekspresi, alias, sub-query, atau `UNION`.
3. Tabel yang namanya gudang kredensial (`*api_keys`, `*sessions`, `*tokens`, …) ditolak.
4. Dijalankan dalam `START TRANSACTION READ ONLY`.
5. Hasil: kolom bernama kredensial dan kredensial di dalam nilai (serialized/JSON) disamarkan; batas baris (100, maks 1000) dan panjang sel.

Diuji terhadap database nyata: 15 query sah, 14 upaya bocor (tidak ada yang bocor), 17 ditolak. **Tidak tertutup:** rahasia yang disimpan plugin di tabelnya sendiri dengan nama tak mencurigakan; dan data pribadi (email, alamat, pesanan) memang terbaca.

## State lokal

| File | Isi |
|---|---|
| `~/.wpicker/sites.json` (0600; banyak site, id tetap, `default`) — `config.json` lama hanya dibaca dan diimpor sebagai site 1, tidak pernah ditulis, supaya binary lama di PATH tidak merusak store. Folder terhubung lewat `<folder>/.wpicker/project.json` (tanpa rahasia; dipilih berdasarkan URL). Urutan pemilihan: `--site` → `$WPICKER_SITE` → link folder → default; perintah yang mengubah site menolak jika hanya mengandalkan default saat ada >1 site. Lama: `~/.wpicker/config.json` (0600) | site, username, application password (plaintext), device id/nama. **Satu site saja.** |
| `./.wpicker/last-pull.json` | base: hash tiap file saat terakhir di-pull/di-push |
| `./.wpicker/base/` | isi tiap file pada saat itu (leluhur untuk merge) |
| `./.wpicker/merge-backup/` | versi lokal sebelum sebuah merge |
| `./.wpicker/last-error.json` | error push terakhir |
| `./.wpicker/context.json` | hasil `context --save` / `init` |

## Alur kerja agent

1. `wpicker login` → PIN dari menu **WPicker** di WP-Admin.
2. `wpicker context` → pahami tema, plugin, builder.
3. `wpicker theme status` lalu `wpicker theme pull` → sebelum edit.
4. Edit lokal.
5. `wpicker theme push`.

Self-healing: push ditolak (syntax error atau fatal runtime; server sudah memulihkan file) → baca `last-error.json` → fix → push ulang. Site hidup tapi logika salah → `wpicker history` → `wpicker rollback <id>`.

## Guardrails

- Tulis file **hanya** di child theme aktif. Parent theme, core, `wp-config.php` ditolak.
- Auth Application Password per perangkat, bisa dicabut dari WP-Admin atau CLI.
- Pakai di staging / dev / lokal. Uninstall plugin kalau tidak dipakai.

## Dev, test, rilis

- `make dev` → wp-env di `http://localhost:8888` (admin / password), WP 6.6, PHP 8.2. Butuh Docker + Node 18+.
- `make build-cli` → `bin/wpicker`. `make test` → Go test + `php -l`. `make test-php` → PHPUnit di wp-env.
- `make mirror` → uji backup + restore antar situs (`scripts/mirror/`), jalur WP-Admin lalu jalur CLI. **Menimpa situs tujuan.**
- `make sim` → simulasi e2e ke dua site Local (lihat bawah). Butuh mode push `direct`; mode `review` diuji manual lewat sesi admin (belum ada di skrip).
- Status (2026-10-12): Go test lulus; `make sim` 52 lulus, 0 gagal, 0 celah dikenal. PHPUnit tidak dijalankan.
- Rilis: `scripts/release.sh vX.Y.Z` → bump versi, commit, build 5 target (darwin/linux amd64+arm64, windows amd64), tag, push tag, bikin **draft** GitHub release. Butuh `gh`.
- Install CLI: `curl -sL https://raw.githubusercontent.com/febritecno/wpicker/main/install.sh | bash -s -- install`.
- Syarat server: PHP 7.4+, `php` di `PATH`, child theme aktif.

## Lingkungan simulasi (Local by Flywheel)

| Peran | Site | URL | Isi |
|---|---|---|---|
| Server | `wpicker` | `http://wpicker.local` | Plugin = symlink ke `plugin/` repo (edit kode langsung berlaku). Child theme `wpicker-child` aktif |
| Lokal | `wpicker-local` | `http://wpicker-local.local` | Folder `wp-content/themes/wpicker-child` = folder kerja CLI (pull/push dari sini) |

- Path: `~/Local Sites/<site>/app/public`. Admin dua-duanya: `root` / `root`.
- CLI sudah di-pair ke server sebagai device `flow-test` (`~/.wpicker/config.json`). Pakai `bin/wpicker` hasil build repo, bukan `/usr/local/bin/wpicker`.
- `scripts/sim.sh` / `make sim`: pull → push → lint gate → drift → rollback → hapus file → biner → fatal runtime → pull selektif → merge + konflik → pilih sisi → dua orang beda baris. `PASS`/`FAIL` untuk yang harus jalan; `check_gap` tersedia untuk menandai celah baru sebagai `KNOWN` tanpa menggagalkan run. Membersihkan sendiri setelah selesai.
- WP-CLI ke site Local harus pakai PHP + `php.ini` milik Local (socket MySQL ada di sana):
  ```bash
  L="$HOME/Library/Application Support/Local"
  "$L/lightning-services/php-8.2.29+0/bin/darwin-arm64/bin/php" -c "$L/run/<site-id>/conf/php/php.ini" \
    /opt/homebrew/bin/wp --path="$HOME/Local Sites/<site>/app/public" <perintah>
  ```
  Site id: `wpicker` = `zBqR06TtO`, `wpicker-local` = `YJrDSmmBP`.
- Log error PHP server: `~/Local Sites/wpicker/logs/php/error.log`.

## Bug yang ditemukan saat simulasi

Plugin di commit `002fb65` **fatal di semua request REST** — tidak ada perintah CLI yang bisa jalan. Sudah diperbaiki di working tree:

| Bug | File |
|---|---|
| `Device::register()` bentrok tanda tangan dengan `Base::register()` → fatal saat class dimuat | `REST/Device.php` |
| `Vuln` tidak implement `routes()`, pakai `$this->namespace` dan `check_permission` yang tidak ada | `REST/Vuln.php` |
| `Page` panggil `check_permission()` dan `error()` yang tidak ada | `REST/Page.php` |
| `Vuln` dan `Page` tidak di-import → `Class "WPicker\Vuln" not found` | `Core.php` |
| `touch_device` minta 4 argumen, `rest_pre_dispatch` kirim 3 | `Core.php` |
| Urutan hasil `create_new_application_password` terbalik → register 500 | `REST/Device.php` |
| Login dengan password admin asli selalu 401 (Basic auth core hanya terima Application Password) | `REST/Device.php`, `cli/internal/client` |
| Fatal runtime mematikan REST → rollback tidak bisa dipanggil | `REST/Theme.php`, `Health/Probe.php` |
| Pesan error CLI tercetak dua kali | `cli/cmd/root.go` |

Jebakan yang perlu diingat:

- Core memanggil `permission_callback` beberapa kali per request (dispatch + sekali per handler untuk header `Allow`). Efek samping di dalamnya (hitung gagal login) harus di-cache per request — lihat `Device::authorize()`.
- Opcache menunda efek file baru ~2 detik (`revalidate_freq`). Tanpa `opcache_invalidate`, health check langsung setelah tulis melihat kode lama dan lolos palsu.

Belum diperbaiki:

- **Updater didaftarkan di dalam `rest_api_init`** → hook update plugin tidak pernah terpasang di halaman admin biasa.
- Password tidak disembunyikan saat diketik di `wpicker login`.
- Halaman Devices: tombol "Generate PIN" menimpa PIN yang sudah diminta CLI (tetap berfungsi, user baca PIN baru).

## Kekurangan terhadap masalah inti

Sudah ditutup: clone situs penuh server → lokal (diuji manual ke site `wpicker-local`). Dibuktikan `make sim`: pull selektif ala git (daftar, pilih, konflik tidak ditimpa), hapus file dua arah, file biner, rollback persis, push gagal di tengah di-revert, fatal runtime di-revert otomatis, login pakai password admin.

Masih terbuka, urut dari yang paling menghalangi tujuan "ganti siklus backup-upload":

1. **Lokal → server untuk isi situs: bisa, tapi lewat WP-Admin.** Buat backup di situs sumber, unggah di Backup & Restore situs tujuan, klik Restore. Belum ada satu perintah CLI untuk itu, dan sifatnya menimpa seluruh database (bukan per halaman).
2. **Merge per baris hanya di CLI.** Di WP-Admin (revert / apply push basi) konflik masih diputuskan per file. File biner dan hapus-vs-ubah tidak bisa digabung di mana pun.
3. **Health check hanya lihat halaman depan + REST.** Fatal yang cuma muncul di halaman lain (template tertentu, admin) lolos. Butuh loopback; kalau host memblokirnya hasilnya `unreachable` dan push tidak diverifikasi.
4. **Pull lambat untuk theme besar.** Satu request per file. Push mengirim semua file dalam satu body JSON (batas `post_max_size`).
5. **Satu site per mesin.** Config tunggal; tidak ada profil staging/production.
6. **Tidak ada watch mode.** Tiap perubahan harus `push` manual.
7. **`init` hanya menulis `.cursorrules`.** Tidak ada `CLAUDE.md` / `AGENTS.md` untuk agent lain.
8. **Scanner hanya plugin.** Core dan theme tidak dicek.
9. **`page create` langsung `publish`.** Tidak ada opsi draft, tidak ada snapshot/rollback untuk perubahan page.

Belum diuji: restore yang gagal di tengah fase `db` atau `files` (jalur kode ada, belum dipicu); restore saat plugin di backup fatal di server tujuan (situs bisa mati dan langkah berikutnya ikut gagal — pemulihan lewat salinan pengaman butuh akses file); backup situs besar (yang diuji 15 MB, 12 tabel); restore ke MySQL lewat TCP dan di Windows; multisite (ditolak); penolakan password lewat HTTP di site non-`local`; jalur `already_failing`; PHPUnit (butuh wp-env/Docker).

## Bug build

- `make package` menghapus zip plugin: `package-plugin` bikin `dist/wpicker.zip`, lalu `package-cli` jalankan `rm -rf dist`.
- `make package-cli` tidak set `GOOS`/`GOARCH`: `wpicker-darwin-arm64` dan `wpicker-linux-amd64` dua-duanya biner untuk mesin host. `scripts/release.sh` benar; Makefile tidak.

## Dokumen yang tidak cocok dengan kode

| Dokumen bilang | Kode |
|---|---|
| README: `page create --title --content --slug` | `--title --builder --file`; tidak ada `--content`, `--slug` |
| README: `wpicker scan run`, `scan status` | Hanya `wpicker scan` |
| README: scan core, theme, plugin | Plugin saja |
| PRD + DEPLOYMENT + `package.json`: versi 1.1.0 | 1.0.0 |
| DEPLOYMENT: `dist/wpicker-1.1.0.zip` | `dist/wpicker.zip` |
| DEPLOYMENT: repo berisi `.cursorrules` | `.cursorrules` ada di `.gitignore` |
| PRD: rollback `<time>` | `<manifest-id>` atau prefix |
| PRD: tidak ada tulis DB lewat CLI | `page create/update` tulis post + post meta |
| README License: "All Rights Reserved" | `LICENSE` = GPL v2, badge GPL v2+ |
| README: staging/dev saja | DEPLOYMENT ditulis untuk production |

## Usulan arah

1. Perluas sync ke plugin kustom dan `uploads/` dengan pola yang sama (hash diff, snapshot).
2. Sync DB dua arah dengan snapshot DB sebelum tulis dan search-replace URL. Bentrok dengan guardrail "tanpa tulis DB" di PRD — perlu keputusan.
3. Merge tiga arah per baris untuk konflik.
4. Profil multi-site + `wpicker watch`.

