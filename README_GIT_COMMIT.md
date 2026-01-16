# Script Commit ke Git

Script untuk memudahkan proses commit perubahan ke Git repository.

## File Script

1. **git_commit.bat** - Script untuk Windows (Command Prompt)
2. **git_commit.sh** - Script untuk Linux/Mac (Bash)
3. **git_commit.ps1** - Script untuk Windows (PowerShell)

## Cara Penggunaan

### Windows (Command Prompt)

```batch
git_commit.bat "pesan commit"
```

Atau tanpa parameter (akan diminta input):
```batch
git_commit.bat
```

### Windows (PowerShell)

```powershell
.\git_commit.ps1 "pesan commit"
```

Atau tanpa parameter:
```powershell
.\git_commit.ps1
```

**Catatan:** Jika mendapat error execution policy, jalankan:
```powershell
Set-ExecutionPolicy -ExecutionPolicy RemoteSigned -Scope CurrentUser
```

### Linux/Mac (Bash)

Pertama, berikan permission execute:
```bash
chmod +x git_commit.sh
```

Kemudian jalankan:
```bash
./git_commit.sh "pesan commit"
```

Atau tanpa parameter:
```bash
./git_commit.sh
```

## Fitur

- ✅ Menampilkan status perubahan sebelum commit
- ✅ Konfirmasi sebelum melakukan commit
- ✅ Validasi pesan commit tidak boleh kosong
- ✅ Otomatis menambahkan semua perubahan (git add .)
- ✅ Opsi untuk push ke remote setelah commit
- ✅ Error handling yang baik
- ✅ Pesan yang informatif

## Contoh Penggunaan

```batch
# Commit dengan pesan langsung
git_commit.bat "Fix: Perbaiki pagination dan show entries di data guru"

# Commit dengan input interaktif
git_commit.bat
# Akan diminta: Masukkan pesan commit: Fix bug pagination
```

## Catatan

- Script akan menambahkan **semua perubahan** (git add .)
- Pastikan Anda sudah berada di direktori repository Git
- Script akan menanyakan konfirmasi sebelum commit dan push
- Jika push gagal, Anda bisa push manual dengan `git push`
