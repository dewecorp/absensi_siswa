@echo off
echo ==========================================
echo      GIT AUTO COMMIT AND PUSH SCRIPT
echo ==========================================
echo.

:: 1. Add all changes
echo [1/3] Adding changes...
git add .

:: 2. Commit changes
echo [2/3] Committing changes...
set /p commit_msg="Enter commit message (Press Enter for 'Update'): "
if "%commit_msg%"=="" set commit_msg="Update"
git commit -m "%commit_msg%"

:: 3. Push to GitHub
echo [3/3] Pushing to GitHub...
git push origin main

echo.
echo ==========================================
if %errorlevel% equ 0 (
    echo      SUCCESSFULLY UPDATED GITHUB!
) else (
    echo      ERROR: PUSH FAILED!
)
echo ==========================================
pause
