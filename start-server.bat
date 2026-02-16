@echo off
echo Starting Laravel server on all interfaces (0.0.0.0:8000)...
echo This allows access from your phone and other devices on the network.
echo.
echo Server will be accessible at:
echo   - http://localhost:8000
echo   - http://127.0.0.1:8000
echo   - http://192.168.3.25:8000 (your network IP)
echo.
php artisan serve --host=0.0.0.0 --port=8000
