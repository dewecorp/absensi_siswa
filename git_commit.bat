@echo off
REM Script untuk commit perubahan ke Git
REM Penggunaan: git_commit.bat "pesan commit"

echo ========================================
echo Script Commit ke Git
echo ========================================
echo.

REM Cek apakah dalam repository git
git rev-parse --git-dir >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Direktori ini bukan repository Git!
    echo Silakan jalankan 'git init' terlebih dahulu.
    pause
    exit /b 1
)

REM Cek apakah ada perubahan
git status --porcelain >nul 2>&1
if %errorlevel% neq 0 (
    echo ERROR: Gagal memeriksa status Git!
    pause
    exit /b 1
)

REM Tampilkan status
echo Status perubahan:
echo ----------------------------------------
git status --short
echo.

REM Tanyakan apakah ingin melanjutkan
set /p confirm="Apakah Anda ingin commit perubahan ini? (y/n): "
if /i not "%confirm%"=="y" (
    echo Commit dibatalkan.
    pause
    exit /b 0
)

REM Ambil pesan commit
if "%~1"=="" (
    set /p commit_msg="Masukkan pesan commit: "
) else (
    set commit_msg=%~1
)

REM Validasi pesan commit
if "%commit_msg%"=="" (
    echo ERROR: Pesan commit tidak boleh kosong!
    pause
    exit /b 1
)

REM Add semua perubahan
echo.
echo Menambahkan semua perubahan...
git add .
if %errorlevel% neq 0 (
    echo ERROR: Gagal menambahkan file!
    pause
    exit /b 1
)

REM Commit
echo.
echo Melakukan commit...
git commit -m "%commit_msg%"
if %errorlevel% neq 0 (
    echo ERROR: Gagal melakukan commit!
    pause
    exit /b 1
)

echo.
echo ========================================
echo Commit berhasil!
echo ========================================
echo.

REM Tanyakan apakah ingin push
set /p push_confirm="Apakah Anda ingin push ke remote? (y/n): "
if /i "%push_confirm%"=="y" (
    echo.
    echo Melakukan push ke remote...
    git push
    if %errorlevel% neq 0 (
        echo WARNING: Push gagal. Mungkin belum ada remote atau perlu konfigurasi.
        echo Silakan push manual dengan: git push
    ) else (
        echo Push berhasil!
    )
)

echo.
echo Selesai!
pause
