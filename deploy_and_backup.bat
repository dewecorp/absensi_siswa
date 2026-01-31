@echo off
setlocal enabledelayedexpansion

echo ==========================================
echo      DEPLOY AND BACKUP SCRIPT
echo      Target: https://github.com/dewecorp/absensi_siswa
echo ==========================================
echo.

:: 0. Cleanup Temporary Files
echo [Cleanup] Removing temporary debug/check/test/fix files...
del /q "debug*.php" 2>nul
del /q "check*.php" 2>nul
del /q "test*.php" 2>nul
del /q "fix*.php" 2>nul
del /q "kepala\debug*.php" 2>nul
del /q "kepala\check*.php" 2>nul
del /q "kepala\cek*.php" 2>nul
echo Done.
echo.

:: 0. Check Git Installation
where git >nul 2>nul
if %errorlevel% neq 0 (
    echo Error: Git is not installed or not in PATH.
    pause
    exit /b 1
)

:: 1. Initialize Git if needed
if not exist ".git" (
    echo [Init] Initializing Git repository...
    git init
)

:: 2. Configure Remote (As requested by user)
echo [Config] Configuring remote 'origin'...
git remote remove origin >nul 2>nul
git remote add origin https://github.com/dewecorp/absensi_siswa
if %errorlevel% neq 0 (
    echo Warning: Could not set remote. It might already exist correctly.
)

:: 3. Cleanup stale lock files
if exist ".git\index.lock" (
    echo [Cleanup] Found stale git lock file. Removing...
    del /f /q ".git\index.lock"
)

:: 4. Add all changes
echo [Git] Adding changes...
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
    echo No changes to commit. Proceeding to sync...
) else (
    :: 5. Commit changes
    :get_message
    echo.
    set "commit_msg="
    set /p "commit_msg=Enter commit message (Press Enter for 'Update'): "
    if "!commit_msg!"=="" set "commit_msg=Update"
    
    echo.
    echo    Commit Message: "!commit_msg!"
    echo.
    set /p "confirm=Are you sure you want to commit with this message? (Y/N): "
    if /i "!confirm!" neq "y" (
        echo.
        echo Cancelled. Please re-enter message.
        goto :get_message
    )

    echo [Git] Committing changes...
    git commit -m "!commit_msg!"
    if !errorlevel! neq 0 (
        echo Error: Commit failed.
        goto :error
    )
)

:: 6. Pull (Rebase) and Push
echo [Git] Pulling latest changes (rebase)...
git pull --rebase origin main
if %errorlevel% neq 0 (
    echo Warning: Pull failed. You might need to resolve conflicts manually.
    set /p "continue=Continue anyway? (Y/N): "
    if /i "!continue!" neq "y" goto :error
)

echo Pushing to GitHub (Branch: main)...
git push -u origin main
if %errorlevel% neq 0 (
    echo.
    echo ========================================================
    echo ERROR: PUSH FAILED
    echo ========================================================
    echo Possible reasons:
    echo 1. Internet connection issue.
    echo 2. Invalid credentials (password/token).
    echo 3. You don't have write access to the repository.
    echo 4. Remote contains work that you do not have locally.
    echo.
    echo Try running: git push -u origin main --force
    echo (Warning: --force will overwrite remote changes)
    echo.
    goto :error
)

echo.
echo [Git] Push successful.
echo.
pause

:: 7. Create ZIP Backup
echo [Backup] Preparing backup...

:: Generate Timestamp using PowerShell (More reliable than WMIC)
for /f %%a in ('powershell -Command "Get-Date -format 'yyyy-MM-dd_HH-mm-ss'"') do set TIMESTAMP=%%a

:: Define Backup Filename
set "BACKUP_DIR=backups"
if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"
set "BACKUP_FILE=%BACKUP_DIR%\source_backup_%TIMESTAMP%.zip"

echo [Backup] Creating ZIP: %BACKUP_FILE%
echo This might take a while...
pause

:: Create backup using tar
:: We use explicit file inclusion to be safer, but * is usually fine.
tar -a -c -f "%BACKUP_FILE%" --exclude ".git" --exclude "backups" --exclude "node_modules" *

if %errorlevel% neq 0 (
    echo.
    echo [Warning] Tar command returned error code %errorlevel%.
    echo Some files might be locked or inaccessible.
    echo Backup might be partial.
) else (
    echo.
    echo [Success] Backup saved to: %BACKUP_FILE%
)

echo.
echo ==========================================
echo      SUCCESS! DEPLOYED AND BACKED UP
echo ==========================================
pause
exit /b 0

:error
echo.
echo ==========================================
echo      OPERATION FAILED
echo ==========================================
pause
exit /b 1
