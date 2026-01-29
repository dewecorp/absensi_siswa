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
:: Robocopy exit codes: 0-7 are success (0=no change, 1=copied, etc)
robocopy . "%TEMP_DIR%" /E /XD .git "%TEMP_DIR%" /XF absensi_siswa_backup.zip /R:1 /W:1 /NFL /NDL /NJH /NJS
set "ROBO_EXIT=%ERRORLEVEL%"

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
echo      ERROR: BACKUP FAILED!
echo ==========================================
if exist "%TEMP_DIR%" rmdir /s /q "%TEMP_DIR%"
pause
