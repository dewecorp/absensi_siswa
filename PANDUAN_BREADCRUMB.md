# Panduan Breadcrumb Dinamis

Sistem breadcrumb dinamis memudahkan Anda untuk membuat dan mengelola breadcrumb secara otomatis tanpa perlu mengedit satu per satu laman!

## Cara Menggunakan untuk Laman Baru

### Langkah 1: Salin Template
Salin file `TEMPLATE_LAMAN_BARU.php` dan ganti nama sesuai kebutuhan laman Anda.

### Langkah 2: Atur 3 Variabel
Atur variabel di bagian atas laman:
```php
$page_title = 'Judul Laman Anda';
$current_page = basename(__FILE__);
```

### Langkah 3: Tambahkan Breadcrumb
Di bagian `section-header`, cukup tambahkan:
```php
<?php echo render_breadcrumb(); ?>
```

### Langkah 4: Daftarkan Menu ke Sidebar
Pastikan laman Anda terdaftar di `templates/sidebar.php` sesuai level user (admin, kepala, tata_usaha, dll.)

## Contoh Struktur Menu
Di `templates/sidebar.php`, menu Anda harus terlihat seperti ini:

```php
[
    'title' => 'Nama Menu Induk',
    'icon' => 'fas fa-icon',
    'submenu' => [
        ['title' => 'Nama Laman Anda', 'url' => 'nama_laman.php', 'active' => $current_page === 'nama_laman.php'],
    ]
]
```

## Hasilnya Otomatis!
Setelah menu terdaftar di `sidebar.php`, breadcrumb akan otomatis menjadi:
`Dashboard > Nama Menu Induk > Nama Laman Anda`

## Keuntungan
- ✅ Konsisten di semua laman
- ✅ Tidak perlu edit satu per satu saat struktur menu berubah
- ✅ Otomatis menyesuaikan menu baru
- ✅ Mudah digunakan