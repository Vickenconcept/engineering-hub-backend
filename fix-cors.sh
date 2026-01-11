#!/bin/bash

# Script to fix CORS issues on Laravel Forge
# Run this on your server via SSH: bash fix-cors.sh

echo "Clearing Laravel caches..."

# Navigate to your project directory (adjust path if needed)
cd /home/forge/engineering-hub-backend-iwwxrqbo.on-forge.com || cd /home/forge/$(ls -t /home/forge | head -1)

# Clear all caches
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear

# Optionally optimize (only if config is stable)
# php artisan config:cache

echo "Caches cleared!"
echo ""
echo "Verifying CORS config..."
php artisan tinker --execute="print_r(config('cors'));"

echo ""
echo "Done! If CORS still doesn't work, try:"
echo "1. Restart PHP-FPM: sudo service php8.3-fpm restart (or your PHP version)"
echo "2. Check your .env file has CORS_ALLOWED_ORIGINS set correctly"
echo "3. Make sure your Vercel domain matches the pattern: *.vercel.app"

