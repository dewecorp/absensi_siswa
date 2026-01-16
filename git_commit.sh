#!/bin/bash

# Script untuk commit perubahan ke Git
# Penggunaan: ./git_commit.sh "pesan commit"

echo "========================================"
echo "Script Commit ke Git"
echo "========================================"
echo ""

# Cek apakah dalam repository git
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo "ERROR: Direktori ini bukan repository Git!"
    echo "Silakan jalankan 'git init' terlebih dahulu."
    exit 1
fi

# Cek apakah ada perubahan
if ! git status --porcelain > /dev/null 2>&1; then
    echo "ERROR: Gagal memeriksa status Git!"
    exit 1
fi

# Tampilkan status
echo "Status perubahan:"
echo "----------------------------------------"
git status --short
echo ""

# Tanyakan apakah ingin melanjutkan
read -p "Apakah Anda ingin commit perubahan ini? (y/n): " confirm
if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
    echo "Commit dibatalkan."
    exit 0
fi

# Ambil pesan commit
if [ -z "$1" ]; then
    read -p "Masukkan pesan commit: " commit_msg
else
    commit_msg="$1"
fi

# Validasi pesan commit
if [ -z "$commit_msg" ]; then
    echo "ERROR: Pesan commit tidak boleh kosong!"
    exit 1
fi

# Add semua perubahan
echo ""
echo "Menambahkan semua perubahan..."
git add .
if [ $? -ne 0 ]; then
    echo "ERROR: Gagal menambahkan file!"
    exit 1
fi

# Commit
echo ""
echo "Melakukan commit..."
git commit -m "$commit_msg"
if [ $? -ne 0 ]; then
    echo "ERROR: Gagal melakukan commit!"
    exit 1
fi

echo ""
echo "========================================"
echo "Commit berhasil!"
echo "========================================"
echo ""

# Tanyakan apakah ingin push
read -p "Apakah Anda ingin push ke remote? (y/n): " push_confirm
if [[ "$push_confirm" =~ ^[Yy]$ ]]; then
    echo ""
    echo "Melakukan push ke remote..."
    git push
    if [ $? -ne 0 ]; then
        echo "WARNING: Push gagal. Mungkin belum ada remote atau perlu konfigurasi."
        echo "Silakan push manual dengan: git push"
    else
        echo "Push berhasil!"
    fi
fi

echo ""
echo "Selesai!"
