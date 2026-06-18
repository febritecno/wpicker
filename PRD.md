# Product Requirements Document: WPicker

**Versi:** 1.1.0
**Status:** Siap untuk Pengembangan
**Konsep:** Jembatan (CLI + Plugin) untuk memodernisasi alur kerja AI Agent pada WordPress.

> This is a verbatim copy of the source PRD, kept in the repo as the authoritative
> reference. Implementation notes / deviations are tracked in the plan section of
> `README.md` and inline in code comments.

## 1. Filosofi Produk

WPicker mengubah WordPress dari sistem yang "sulit dijangkau AI" menjadi lingkungan
pengembangan yang AI-Native. WPicker memisahkan operasional menjadi dua bagian:

- **The Eyes (Plugin):** Bertindak sebagai sensor yang mengekspos struktur site,
  basis data, dan menjaga Deployment Vault (sejarah & rollback).
- **The Hands (CLI):** Alat bantu di sisi lokal yang memungkinkan AI melakukan
  sinkronisasi file, inspeksi, dan perbaikan secara aman.

## 2. Fitur Utama

### A. Plugin: WPicker Context & Vault

- **Manajemen Perangkat:** Dashboard di WP-Admin untuk memantau perangkat lokal
  yang terhubung, melihat riwayat penggunaan, dan mencabut akses (revocation).
- **Global Context API:** Mengekspos metadata site (plugin, tema, versi WP) dan
  data konfigurasi (seperti `theme_mods`) ke dalam bentuk JSON yang mudah
  dipahami AI.
- **Deployment Vault (Sistem Rollback):**
  - Setiap perintah push akan memicu pembuatan snapshot (backup) otomatis dari
    direktori child theme sebelum ditimpa.
  - **Manifest Log:** Mencatat stempel waktu, perangkat yang melakukan perubahan,
    dan daftar file yang terdampak.
  - **Auto-Linting:** Sebelum file baru diterapkan, plugin menjalankan `php -l`
    untuk mendeteksi syntax error. Jika ditemukan error, push dibatalkan
    (Rollback preventif).

### B. CLI: WPicker Agent

Alat baris perintah yang menjadi pintu masuk AI Agent ke WordPress.

| Perintah | Fungsi Utama |
|---|---|
| `wpicker login` | Autentikasi aman via PIN 6-digit & Application Password. |
| `wpicker context` | Mengambil data konfigurasi site (meminimalisir hallucination). |
| `wpicker theme pull` | Mengunduh versi terbaru dari live site. |
| `wpicker theme push` | Mengirim perubahan lokal dengan validasi linting. |
| `wpicker history` | Melihat riwayat deploy untuk pelacakan masalah. |
| `wpicker rollback <time>` | Memulihkan file ke snapshot tertentu secara instan. |

## 3. Alur Kerja "Self-Healing" AI Agent

Dengan fitur Vault, AI Agent kini memiliki kemampuan pemulihan diri:

1. **Deteksi:** Jika push menyebabkan Fatal Error, plugin menolak perubahan dan
   mengirimkan log error ke CLI.
2. **Analisis:** AI menjalankan `wpicker history` untuk memahami perubahan apa
   yang memicu error.
3. **Healing (Penyembuhan):** AI menjalankan `wpicker rollback <timestamp>` untuk
   mengembalikan stabilitas situs dalam hitungan milidetik.
4. **Perbaikan:** AI membaca error log, memperbaiki kode di lingkungan lokal, dan
   melakukan push ulang.

## 4. Keamanan & Batasan (Guardrails)

- **Pemisahan Hak Akses:** Plugin hanya beroperasi dalam mode baca (Read-only)
  untuk data sensitif, dan hanya mengizinkan modifikasi file di direktori Child Theme.
- **Anti-Destruksi:** Tidak ada akses database tulis (Write) melalui CLI. Semua
  perubahan CSS/Style yang bersifat global harus dilakukan melalui file-file
  dalam tema, sesuai standar pengembangan WordPress.
- **Autentikasi Terenkripsi:** Menggunakan Application Passwords bawaan WordPress
  yang terisolasi per perangkat, sehingga tidak perlu membagikan kredensial login utama.

## 5. Roadmap Implementasi

- **Fase 1 (Foundational):** Membangun koneksi aman, PIN generator, dan
  sinkronisasi file dasar (Pull/Push).
- **Fase 2 (Stability):** Mengimplementasikan sistem backup atomik, manifest log,
  dan perintah rollback di CLI.
- **Fase 3 (Intelligence):** Integrasi AI-Oriented Context (API JSON) dan fitur
  `wpicker init` untuk menanamkan `.cursorrules` ke folder proyek.
- **Fase 4 (Observability):** Menyempurnakan UI Admin untuk manajemen riwayat
  rollback dan log aktivitas.

> **Catatan untuk AI Agent:** Gunakan PRD ini sebagai referensi utama saat membangun
> modul. Fokuslah pada modularitas antara PHP (Plugin) dan Go (CLI). Pastikan setiap
> komunikasi API dilakukan dengan enkripsi/keamanan standar WordPress.
