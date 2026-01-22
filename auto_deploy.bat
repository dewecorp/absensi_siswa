@echo off
setlocal

:: KONFIGURASI
set "REPO_URL=https://github.com/dewecorp/absensi_siswa"
set "ZIP_NAME=absensi_siswa_backup.zip"

echo ===================================================
echo    SCRIPT OTOMATIS: GIT PUSH & ZIP BACKUP
echo ===================================================
echo.

:: -----------------------------------------------------
:: 1. PROSES GIT
:: -----------------------------------------------------
echo [1/3] Memproses Git...

:: Cek apakah folder .git ada
if not exist ".git" (
    echo [INFO] Inisialisasi Git repository baru...
    git init
)

:: Pastikan branch utama bernama 'main'
git branch -M main

:: Pastikan remote URL sesuai
git remote remove origin >nul 2>&1
git remote add origin %REPO_URL%

:: Tambahkan semua file
git add .

:: Cek status apakah ada yang perlu di-commit
git diff --cached --quiet
if %errorlevel% equ 0 (
    echo [INFO] Tidak ada perubahan untuk di-commit.
) else (
    set /p commit_msg="Masukkan pesan commit (Enter untuk default): "
    if "%commit_msg%"=="" set commit_msg="Auto-update: %date% %time%"
    
    git commit -m "%commit_msg%"
)

:: Push ke GitHub
echo [2/3] Mengirim ke GitHub (%REPO_URL%)...
git push -u origin main
if %errorlevel% neq 0 (
    echo [ERROR] Gagal melakukan push. Cek koneksi internet atau kredensial Git Anda.
    echo Lanjut ke proses backup zip...
)

:: -----------------------------------------------------
:: 2. PROSES ZIP
:: -----------------------------------------------------
echo.
echo [3/3] Membuat file ZIP (%ZIP_NAME%)...

:: Hapus file zip lama jika ada agar tidak menumpuk/error
if exist "%ZIP_NAME%" del "%ZIP_NAME%"

:: Gunakan PowerShell untuk zip
:: Logika: Ambil semua item di folder ini, kecuali file .zip, lalu kompres.
:: Note: Get-ChildItem secara default mengabaikan folder tersembunyi seperti .git
powershell -NoProfile -Command "Get-ChildItem -Path . -Exclude '*.zip' | Compress-Archive -DestinationPath '%ZIP_NAME%' -Force"

if exist "%ZIP_NAME%" (
    echo.
    echo [SUKSES] File zip berhasil dibuat: %CD%\%ZIP_NAME%
) else (
    echo.
    echo [GAGAL] Gagal membuat file zip.
)

echo.
echo ===================================================
echo Selesai. Tekan tombol apa saja untuk keluar.
pause >nul
