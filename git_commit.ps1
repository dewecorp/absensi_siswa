# Script PowerShell untuk commit perubahan ke Git
# Penggunaan: .\git_commit.ps1 "pesan commit"

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Script Commit ke Git" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Cek apakah dalam repository git
try {
    $null = git rev-parse --git-dir 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: Direktori ini bukan repository Git!" -ForegroundColor Red
        Write-Host "Silakan jalankan 'git init' terlebih dahulu." -ForegroundColor Yellow
        exit 1
    }
} catch {
    Write-Host "ERROR: Gagal memeriksa repository Git!" -ForegroundColor Red
    exit 1
}

# Tampilkan status
Write-Host "Status perubahan:" -ForegroundColor Yellow
Write-Host "----------------------------------------" -ForegroundColor Gray
git status --short
Write-Host ""

# Tanyakan apakah ingin melanjutkan
$confirm = Read-Host "Apakah Anda ingin commit perubahan ini? (y/n)"
if ($confirm -ne "y" -and $confirm -ne "Y") {
    Write-Host "Commit dibatalkan." -ForegroundColor Yellow
    exit 0
}

# Ambil pesan commit
if ($args.Count -eq 0) {
    $commit_msg = Read-Host "Masukkan pesan commit"
} else {
    $commit_msg = $args[0]
}

# Validasi pesan commit
if ([string]::IsNullOrWhiteSpace($commit_msg)) {
    Write-Host "ERROR: Pesan commit tidak boleh kosong!" -ForegroundColor Red
    exit 1
}

# Add semua perubahan
Write-Host ""
Write-Host "Menambahkan semua perubahan..." -ForegroundColor Yellow
git add .
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Gagal menambahkan file!" -ForegroundColor Red
    exit 1
}

# Commit
Write-Host ""
Write-Host "Melakukan commit..." -ForegroundColor Yellow
git commit -m $commit_msg
if ($LASTEXITCODE -ne 0) {
    Write-Host "ERROR: Gagal melakukan commit!" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Green
Write-Host "Commit berhasil!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host ""

# Tanyakan apakah ingin push
$push_confirm = Read-Host "Apakah Anda ingin push ke remote? (y/n)"
if ($push_confirm -eq "y" -or $push_confirm -eq "Y") {
    Write-Host ""
    Write-Host "Melakukan push ke remote..." -ForegroundColor Yellow
    git push
    if ($LASTEXITCODE -ne 0) {
        Write-Host "WARNING: Push gagal. Mungkin belum ada remote atau perlu konfigurasi." -ForegroundColor Yellow
        Write-Host "Silakan push manual dengan: git push" -ForegroundColor Yellow
    } else {
        Write-Host "Push berhasil!" -ForegroundColor Green
    }
}

Write-Host ""
Write-Host "Selesai!" -ForegroundColor Green
