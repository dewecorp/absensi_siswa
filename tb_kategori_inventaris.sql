-- Create table for Kategori Inventaris
CREATE TABLE IF NOT EXISTS tb_kategori_inventaris (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_kategori VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert some sample categories (optional)
INSERT INTO tb_kategori_inventaris (nama_kategori) VALUES 
('Elektronik'),
('Furniture'),
('Alat Tulis Kantor'),
('Peralatan Kebersihan'),
('Peralatan Olahraga')
ON DUPLICATE KEY UPDATE nama_kategori = nama_kategori;
