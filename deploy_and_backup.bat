@echo off
setlocal enabledelayedexpansion

echo ==========================================
echo      DEPLOY AND BACKUP SCRIPT
echo ==========================================
echo.

:: 0. Cleanup stale lock files
if exist ".git\index.lock" (
    echo [0/5] Found stale git lock file. Removing...
    del /f /q ".git\index.lock"
)

:: 1. Add all changes
echo [1/5] Adding changes to Git...
git add .
if %errorlevel% neq 0 (
    echo Error: Failed to add changes.
    goto :error
)
echo.

:: Check status
echo Changes to be committed:
git status --short
echo.

:: Check if there are changes to commit
git diff --cached --quiet
if %errorlevel% equ 0 (
    echo No changes to commit. Skipping commit step.
    goto :pull_push
)

:: 2. Commit changes
echo [2/5] Committing changes...
set "commit_msg="
set /p "commit_msg=Enter commit message (Press Enter for 'Update'): "
if "!commit_msg!"=="" set "commit_msg=Update"

git commit -m "!commit_msg!"
if %errorlevel% neq 0 (
    echo Error: Commit failed.
    goto :error
)

:pull_push
:: 3. Pull and Push to GitHub
echo.
echo [3/5] Syncing with GitHub...

echo Pulling latest changes from remote...
git pull origin main
if %errorlevel% neq 0 (
    echo Error: Pull failed. Please resolve conflicts manually.
    goto :error
)

echo Pushing to GitHub...
git push origin main
if %errorlevel% neq 0 (
    echo Error: Push failed. check your internet connection or credentials.
    goto :error
)

:: 4. Create ZIP Backup
echo.
echo [4/5] Creating ZIP Backup (absensi_siswa_backup.zip)...
echo This might take a while...

:: Create temp directory to avoid file lock errors
set "TEMP_DIR=backup_staging_temp"
if exist "%TEMP_DIR%" rmdir /s /q "%TEMP_DIR%"
mkdir "%TEMP_DIR%"

echo Copying files to staging area (including vendor)...
:: Robocopy exit codes: 0-7 are success (0=no change, 1=copied, etc)
robocopy . "%TEMP_DIR%" /E /XD .git "%TEMP_DIR%" /XF absensi_siswa_backup.zip /R:1 /W:1 /NFL /NDL /NJH /NJS
set "ROBO_EXIT=%errorlevel%"

if %ROBO_EXIT% geq 8 (
    echo ROBOCP ERROR: %ROBO_EXIT%
    goto :error
)

echo Compressing files using TAR...
:: Use tar (built-in Windows 10/11) which is faster and more reliable
tar -a -c -f absensi_siswa_backup.zip -C "%TEMP_DIR%" .
if %errorlevel% neq 0 (
    echo TAR ERROR: %errorlevel%
    goto :error
)

echo Cleaning up staging files...
rmdir /s /q "%TEMP_DIR%"

echo.
echo ==========================================
echo      SUCCESSFULLY DEPLOYED AND BACKED UP!
echo ==========================================
pause
goto :eof

:error
echo.
echo ==========================================
echo      ERROR: OPERATION FAILED!
echo ==========================================
if exist "%TEMP_DIR%" rmdir /s /q "%TEMP_DIR%"
pause
exit /b 1
