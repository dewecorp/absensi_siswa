@echo off
echo ==========================================
echo      DEPLOY AND BACKUP SCRIPT
echo ==========================================
echo.

:: 1. Add all changes
echo [1/4] Adding changes to Git...
git add .
echo.
echo Changes to be committed:
git status --short
echo.

:: 2. Commit changes
echo [2/4] Committing changes...
set commit_msg=
set /p commit_msg="Enter commit message (Press Enter for 'Update'): "
if "%commit_msg%"=="" set commit_msg="Update"
git commit -m "%commit_msg%"

:: 3. Push to GitHub
echo [3/4] Pushing to GitHub...
git push origin main

:: 4. Create ZIP Backup
echo [4/4] Creating ZIP Backup (absensi_siswa_backup.zip)...
echo This might take a while...
powershell -Command "Get-ChildItem -Path . -Exclude '.git','*.zip' | Compress-Archive -DestinationPath absensi_siswa_backup.zip -Force"

echo.
echo ==========================================
if %errorlevel% equ 0 (
    echo      SUCCESSFULLY DEPLOYED AND BACKED UP!
) else (
    echo      ERROR: SOMETHING WENT WRONG!
)
echo ==========================================
pause
