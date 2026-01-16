@echo off
echo Installing required PHP libraries for Excel import functionality...
echo.

REM Check if composer is installed
where composer >nul 2>&1
if %errorlevel% neq 0 (
    echo Error: Composer is not installed or not in PATH.
    echo Please install Composer first from https://getcomposer.org/
    pause
    exit /b 1
)

REM Check if composer.json exists
if not exist "composer.json" (
    echo Error: composer.json file not found in the current directory.
    pause
    exit /b 1
)

echo Running composer install...
composer install

echo.
echo Installation complete! The Excel import functionality should now work with XLS/XLSX files.
echo.
pause