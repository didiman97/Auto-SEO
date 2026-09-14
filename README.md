# Synka Auto SEO Content Generator (Google Sheets & AI)

Plugin WordPress cerdas untuk otomatisasi pembuatan konten artikel SEO berkualitas tinggi (standar E-E-A-T Google) langsung dari database **Google Spreadsheet** menggunakan tenaga AI (**Google Gemini** / **OpenAI**).

---

## ✨ Fitur Unggulan

1. **Sinkronisasi Dua Arah Google Spreadsheet**:
   - Membaca baris artikel yang berstatus `Ready` atau `Pending`.
   - Otomatis mengubah status baris menjadi `Published` dan menyisipkan tautan URL artikel yang telah tayang.
2. **AI Content Generator E-E-A-T**:
   - Struktur heading hierarkis (`<h2>`, `<h3>`), *bullet points*, tabel ringkasan/perbandingan, dan *call-out tips*.
   - Otomatis menyertakan bagian **FAQ** beserta **Schema JSON-LD (`FAQPage`)** valid.
3. **Integrasi Plugin SEO (RankMath & Yoast)**:
   - Otomatis mengisi Meta Title, Meta Description (dengan CTA), dan Focus Keyword.
4. **Featured Image Otomatis**:
   - Otomatis mengunduh foto bebas royalti beresolusi tinggi (rasio 1200x675 ideal untuk Google Discover & Social Share) dan mengaitkannya ke Media Library WordPress.
5. **Penjadwalan WP-Cron & Trigger Manual**:
   - Jadwalkan auto-posting setiap 15 menit, 30 menit, per jam, atau harian.
   - Dilengkapi tombol *“Generate Sekarang (1 Post)”* untuk uji coba langsung.

---

## 📋 Struktur Kolom Google Spreadsheet

Buat Google Sheet dengan susunan kolom pada **Baris 1 (Header)**:

| Kolom | Nama Header | Penjelasan | Contoh Isi |
|---|---|---|---|
| **A** | `Keyword Utama` | Kata kunci utama yang ingin ditarget | `Strategi SEO On Page 2026` |
| **B** | `Kategori` | Kategori artikel di WordPress | `SEO` |
| **C** | `Jadwal` | Tanggal & jam publish (Opsional) | `2026-09-10 09:00` *(atau kosongkan)* |
| **D** | `Outline / Catatan` | Poin-poin wajib yang harus dibahas AI | `Bahas title tag, meta desc, heading, FAQ` |
| **E** | `Tone` | Gaya bahasa penulisan | `Profesional, Informatif & Praktis` |
| **F** | `Status` | Status eksekusi | **`Ready`** *(akan diubah ke `Published`)* |
| **G** | `URL Hasil Post` | Tautan postingan | *(Diisi otomatis oleh WordPress)* |

---

## 🚀 Cara Instalasi & Setup

### 1. Pemasangan Plugin di WordPress
1. Salin folder `synka-auto-seo` ke direktori `wp-content/plugins/` di server WordPress Anda.
2. Masuk ke **WP-Admin > Plugins**, lalu aktifkan **Synka Auto SEO Content Generator**.
3. Buka menu baru **Auto SEO Content** di sidebar admin.

### 2. Setup Google Apps Script (Two-Way Sync)
1. Buka Google Spreadsheet rencana konten Anda.
2. Masuk ke menu **Extensions > Apps Script**.
3. Hapus seluruh isi default, lalu salin kode dari file `google-apps-script-template.js`.
4. Klik tombol **Deploy > New deployment**:
   - **Select type**: `Web app`
   - **Execute as**: `Me`
   - **Who has access**: `Anyone` *(Penting)*
5. Klik **Deploy**, lalu salin URL Web App yang berakhiran `/exec`.
6. Tempelkan URL tersebut ke kolom **URL Webhook** di halaman pengaturan plugin.

### 3. Masukkan API Key AI
1. Pilih **Google Gemini** (Rekomendasi gratis/cepat dari [Google AI Studio](https://aistudio.google.com/app/apikey)) atau **OpenAI** (dari platform.openai.com).
2. Simpan pengaturan.
3. Klik tombol **Test Koneksi** untuk memverifikasi kesiapan sistem.
4. Klik **Generate Sekarang** untuk memproses artikel pertama Anda!
