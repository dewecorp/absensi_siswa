@echo off
echo Generating Excel templates for data import...
echo.

REM Check if composer is installed
where composer >nul 2>&1
if %errorlevel% neq 0 (
    echo Error: Composer is not installed or not in PATH.
    pause
    exit /b 1
)

REM Run the PHP script to generate Excel templates
php generate_templates.php

echo.
echo Excel templates have been updated!
echo.
pause