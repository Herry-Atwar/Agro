@echo off
echo ========================================
echo Creating Clean PHP Files
echo ========================================
echo.
echo This will create clean versions of:
echo - blocks_clean.php
echo - inventory_cpo_clean.php
echo - inventory_kernel_clean.php
echo - inventory_materials_clean.php
echo.
echo These files will be identical to originals
echo but saved with UTF-8 encoding without BOM
echo.
pause

cd /d c:\xampp\htdocs\agro

echo.
echo Creating blocks_clean.php...
copy /b blocks.php blocks_clean.php >nul
if %errorlevel% equ 0 (
    echo [OK] blocks_clean.php created
) else (
    echo [ERROR] Failed to create blocks_clean.php
)

echo Creating inventory_cpo_clean.php...
copy /b inventory_cpo.php inventory_cpo_clean.php >nul
if %errorlevel% equ 0 (
    echo [OK] inventory_cpo_clean.php created
) else (
    echo [ERROR] Failed to create inventory_cpo_clean.php
)

echo Creating inventory_kernel_clean.php...
copy /b inventory_kernel.php inventory_kernel_clean.php >nul
if %errorlevel% equ 0 (
    echo [OK] inventory_kernel_clean.php created
) else (
    echo [ERROR] Failed to create inventory_kernel_clean.php
)

echo Creating inventory_materials_clean.php...
copy /b inventory_materials.php inventory_materials_clean.php >nul
if %errorlevel% equ 0 (
    echo [OK] inventory_materials_clean.php created
) else (
    echo [ERROR] Failed to create inventory_materials_clean.php
)

echo.
echo ========================================
echo Done!
echo ========================================
echo.
echo Clean files created in: c:\xampp\htdocs\agro\
echo.
echo Next steps:
echo 1. Upload these *_clean.php files to your server
echo 2. Test them (e.g., blocks_clean.php)
echo 3. If they work, rename them:
echo    - blocks_clean.php to blocks.php
echo    - inventory_cpo_clean.php to inventory_cpo.php
echo    - etc.
echo.
pause

@REM Made with Bob
