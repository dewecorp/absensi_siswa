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

:: 7. Create ZIP Backup
echo.
echo [Backup] Creating ZIP Backup (absensi_siswa_backup.zip)...
echo This might take a while...

:: Create temp directory to avoid file lock errors
set "TEMP_DIR=backup_staging_temp"
if exist "%TEMP_DIR%" rmdir /s /q "%TEMP_DIR%"
mkdir "%TEMP_DIR%"

echo Copying files to staging area...
:: Robocopy exit codes: 0-7 are success
robocopy . "%TEMP_DIR%" /E /XD .git "%TEMP_DIR%" /XF absensi_siswa_backup.zip /R:1 /W:1 /NFL /NDL /NJH /NJS
set "ROBO_EXIT=%errorlevel%"

if %ROBO_EXIT% geq 8 (
    echo ROBOCP ERROR: %ROBO_EXIT%
    goto :error
)

echo Compressing files...
tar -a -c -f absensi_siswa_backup.zip -C "%TEMP_DIR%" .
if %errorlevel% neq 0 (
    echo TAR ERROR: %errorlevel%
    goto :error
)

echo Cleaning up staging files...
rmdir /s /q "%TEMP_DIR%"

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
