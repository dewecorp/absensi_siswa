-- Create table for Data Inventaris Sarpras
CREATE TABLE IF NOT EXISTS tb_inventaris (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_kategori INT NOT NULL,
    nama_inventaris VARCHAR(200) NOT NULL,
    jumlah INT NOT NULL DEFAULT 1,
    luas DECIMAL(10,2) DEFAULT NULL,
    harga_satuan DECIMAL(15,2) NOT NULL DEFAULT 0,
    total DECIMAL(15,2) NOT NULL DEFAULT 0,
    status ENUM('Sertifikat', 'Milik Sendiri') NOT NULL DEFAULT 'Milik Sendiri',
    kondisi ENUM('Baik', 'Rusak') NOT NULL DEFAULT 'Baik',
    keterangan TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (id_kategori) REFERENCES tb_kategori_inventaris(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert sample data (optional)
INSERT INTO tb_inventaris (id_kategori, nama_inventaris, jumlah, luas, harga_satuan, total, status, kondisi, keterangan) VALUES 
(1, 'Komputer Desktop', 10, NULL, 7500000, 75000000, 'Milik Sendiri', 'Baik', 'Untuk lab komputer'),
(1, 'Laptop', 5, NULL, 12000000, 60000000, 'Milik Sendiri', 'Baik', 'Untuk guru dan admin'),
(1, 'Proyektor', 3, NULL, 5000000, 15000000, 'Milik Sendiri', 'Baik', 'Untuk ruang kelas'),
(2, 'Meja Siswa', 100, NULL, 350000, 35000000, 'Milik Sendiri', 'Baik', 'Meja kayu untuk siswa'),
(2, 'Kursi Siswa', 100, NULL, 250000, 25000000, 'Milik Sendiri', 'Baik', 'Kursi kayu untuk siswa'),
(2, 'Meja Guru', 6, NULL, 750000, 4500000, 'Milik Sendiri', 'Baik', 'Meja guru di setiap kelas'),
(2, 'Lemari Arsip', 4, NULL, 1500000, 6000000, 'Milik Sendiri', 'Baik', 'Untuk ruang admin'),
(3, 'Printer', 2, NULL, 2500000, 5000000, 'Milik Sendiri', 'Rusak', 'Perlu perbaikan'),
(3, 'ATK (Paket)', 50, NULL, 100000, 5000000, 'Milik Sendiri', 'Baik', 'Alat tulis kantor'),
(4, 'Sapu', 20, NULL, 35000, 700000, 'Milik Sendiri', 'Baik', 'Untuk kebersihan sekolah'),
(4, 'Pel', 15, NULL, 45000, 675000, 'Milik Sendiri', 'Baik', 'Untuk kebersihan sekolah'),
(5, 'Bola Basket', 5, NULL, 250000, 1250000, 'Milik Sendiri', 'Baik', 'Untuk ekstrakurikuler'),
(5, 'Bola Voli', 5, NULL, 200000, 1000000, 'Milik Sendiri', 'Baik', 'Untuk ekstrakurikuler')
ON DUPLICATE KEY UPDATE nama_inventaris = nama_inventaris;
