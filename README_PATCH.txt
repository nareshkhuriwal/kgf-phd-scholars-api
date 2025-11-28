Laravel API Patch for PhD Literature Review
==========================================

1) Create a fresh Laravel 11 project and install Sanctum:
   composer create-project laravel/laravel phd-lit-review-api
   cd phd-lit-review-api
   composer require laravel/sanctum
   php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
   php artisan migrate

2) Copy files from this patch into your Laravel project root (merge/overwrite).

3) Update .env DB settings and (optional) APP_URL.

4) Run migration & seed:
   php artisan migrate
   php artisan db:seed

   Seeded admin:
   - Email: admin@example.com
   - Password: Admin@123

5) Serve API:
   php artisan serve

6) Test:
   POST /api/auth/login
   GET  /api/papers        (with Bearer token)
   POST /api/papers        (with Bearer token)
   GET  /api/reports/rol   (with Bearer token)

7) Frontend .env:
   VITE_API_BASE_URL=http://localhost:8000/api
   VITE_MOCK_MODE=false

Enjoy!