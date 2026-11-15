# Sistem Digitalisasi Pembayaran SPP Santri

**Stack:** PHP 8+, MySQL/MariaDB, PDO, HTML/CSS/JavaScript.  
**Tema:** modern, putih dan hijau.

## Fitur

- Login dengan peran admin dan santri.
- Dashboard ringkasan.
- Admin: kelola data santri, nominal SPP bulanan, kategori jenjang dan nominalnya.
- Admin: melihat pembayaran, menyetujui/menolak pembayaran tunai atau transfer yang melampirkan bukti.
- Admin: indikator tunggakan aktual dan hasil prediksi Decision Tree terintegrasi pada Data Santri dan detail santri.
- Santri: melihat bulan tertunggak, memilih bulan SPP yang ingin dibayar, memilih kategori jenjang.
- Riwayat pembayaran dan status pembayaran.

## Persyaratan

- PHP 8.0+ dengan ekstensi `pdo_mysql`, `fileinfo`.
- MySQL 5.7+/MariaDB.
- XAMPP/WAMP/LAMP.

## Instalasi lokal (XAMPP)

1. Ekstrak folder `santri-go` ke `C:\xampp\htdocs\`.
2. Buat database bernama `pesantren_spp`.
3. Import `database.sql` melalui phpMyAdmin.
4. Buka `config.php`, sesuaikan `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
5. Salin `uploads/.htaccess.example` menjadi `uploads/.htaccess` (Apache). Folder `uploads` harus dapat ditulis oleh PHP.
6. Buka `http://localhost/santri-go/`.
7. Akun awal:
   - Admin: `admin` / `password`
   - Santri demo: `santri001` / `password`
8. **Segera ganti password demo** sebelum digunakan. Akun demo hanya untuk pengujian.




## Alur pembayaran

- **Tunai di akademik:** santri memilih bulan, mengunggah bukti/nota yang diminta, status `Menunggu verifikasi`; admin memeriksa pembayaran dan menyetujui.
- **Transfer bank:** santri memilih bulan, mengunggah bukti transfer; status `Menunggu verifikasi`; admin memeriksa dan menyetujui.
- Bulan hanya dihitung lunas setelah pembayaran berstatus `paid`. Pembayaran manual yang menunggu verifikasi tidak menghapus tunggakan.

## Catatan keamanan sebelum produksi

- Ganti seluruh password demo, gunakan HTTPS, backup database, dan batasi akses admin.
- Sesuaikan kebijakan pembayaran sebagian, refund, biaya gateway, dan verifikasi bukti dengan kebijakan pesantren.
- Batasi ukuran/jenis berkas upload dan lindungi direktori upload.
- Lakukan audit keamanan, uji beban, dan pengujian penerimaan pengguna sebelum digunakan untuk data asli.

## Setting Tunnels HTTPS

- cd %USERPROFILE%\Downloads
- cloudflared-windows-amd64.exe tunnel --url http://localhost:80

## Pembaruan fitur admin
- Menu `Nominal Jenjang` mengelola nama dan nominal jenjang tanpa mengubah nominal SPP.
- Menu `Nominal SPP` mengelola tarif SPP bulanan secara terpisah untuk setiap jenjang.
- Menu `Kelola User` memungkinkan admin mencari semua akun, memperbarui nama/username/password, dan menambah/menghapus akun admin. Akun santri tetap dikelola melalui menu Data Santri agar relasi data dan pembayaran terjaga.
- Menu `Cari Santri & Tagihan` mencari santri dengan nama, username, nomor induk, atau jenjang, lalu menampilkan transaksi/tagihan yang sudah tercatat beserta nominal dan statusnya.

## Pembayaran SPP dan biaya jenjang secara terpisah

Pada database lama, jalankan `upgrade_pemisahan_tagihan.sql` satu kali melalui phpMyAdmin pada database `pesantren_spp`. Migrasi menambahkan kolom `payment_type` agar status SPP dan biaya jenjang untuk bulan yang sama dicatat terpisah. Untuk instalasi baru, impor `database.sql` terbaru sehingga kolom tersebut langsung tersedia.

## Fitur Machine Learning: prediksi tunggakan SPP

- Engine `ml_engine.php` tetap menggunakan Decision Tree sederhana berbasis CART/Gini yang dijalankan di PHP; hasilnya ditampilkan pada Data Santri dan detail santri, bukan sebagai menu terpisah.
- Fitur model berasal dari status pembayaran tepat waktu/tidak tepat waktu selama tiga bulan sebelumnya.
- Label historis ditetapkan sebagai tunggakan jika pembayaran SPP belum berstatus lunas dengan tanggal pencatatan sampai akhir bulan tagihan. Jika kebijakan jatuh tempo pesantren berbeda, aturan label di `ml_engine.php` perlu disesuaikan.
- Periode bulan dibagi secara kronologis (80% periode awal untuk pelatihan dan 20% periode terbaru untuk pengujian). Hasil evaluasi menampilkan accuracy, precision, recall, F1-score, dan komponen confusion matrix.
- Model membutuhkan sekurang-kurangnya 20 catatan santri-bulan dan kedua kelas target pada data pelatihan. Jika belum mencukupi, sistem tidak menampilkan prediksi agar hasil tidak menyesatkan.
- Prediksi hanya indikasi untuk membantu bendahara menentukan prioritas pengingat, bukan kepastian tunggakan atau penilaian kemampuan ekonomi santri.
- Tidak ada tabel database tambahan. Sistem menggunakan tabel `students` dan `payments` yang sudah ada.


## Pagination dan monitoring santri

- Komponen pagination reusable berada di `includes/pagination.php` dengan pilihan 10, 25, 50, dan 100 data per halaman.
- Data Santri, Kelola User, Pembayaran & Laporan, Riwayat Pembayaran, pencarian santri, serta daftar nominal menggunakan pola pagination yang konsisten.
- Detail santri menghitung tagihan per bulan dan jenis pembayaran sejak tanggal santri terdaftar. Pembayaran berstatus `paid` dianggap lunas; transaksi menunggu verifikasi dan bulan tanpa pembayaran tetap ditampilkan sebagai belum lunas.
- Prediksi dihitung saat halaman dibuka menggunakan riwayat pembayaran aktual dan ID santri. Persentase yang ditampilkan adalah proporsi kelas positif pada leaf Decision Tree, bukan probabilitas terkalibrasi.
