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

:: Create temp directory to avoid file lock errors
set "TEMP_DIR=backup_staging_temp"
if exist "%TEMP_DIR%" rmdir /s /q "%TEMP_DIR%"
mkdir "%TEMP_DIR%"

echo Copying files to staging area (including vendor)...
:: Robocopy is robust and can read files that might be read-locked by web server
:: /E : Copy subdirectories, including Empty ones
:: /XD : Exclude Directories (.git and the temp dir itself)
:: /XF : Exclude Files (the target zip file)
:: /R:1 /W:1 : Retry once, wait 1 second on error
:: /NFL /NDL /NJH /NJS : Reduce log output
robocopy . "%TEMP_DIR%" /E /XD .git "%TEMP_DIR%" /XF absensi_siswa_backup.zip /R:1 /W:1 /NFL /NDL /NJH /NJS

echo Compressing files...
powershell -Command "Compress-Archive -Path '%TEMP_DIR%\*' -DestinationPath absensi_siswa_backup.zip -Force"

echo Cleaning up staging files...
rmdir /s /q "%TEMP_DIR%"

echo.
echo ==========================================
if %errorlevel% equ 0 (
    echo      SUCCESSFULLY DEPLOYED AND BACKED UP!
) else (
    echo      ERROR: SOMETHING WENT WRONG!
)
echo ==========================================
pause