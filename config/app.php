<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => env('APP_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'en'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supavut Insight
    |--------------------------------------------------------------------------
    |
    | URL หน้าเว็บของ Insight — ใช้ทำลิงก์ส่งผู้ใช้ไปแก้ไขข้อมูลส่วนตัว
    | (ข้อมูลพนักงานทั้งหมดแก้ที่ Insight ที่เดียว ดู App\Services\InsightMirror)
    |
    */

    'insight_url' => env('INSIGHT_URL', 'http://192.168.7.12:8080/Insight/public/index.php'),

    /*
    |--------------------------------------------------------------------------
    | Webhook รับสัญญาณจาก Insight
    |--------------------------------------------------------------------------
    |
    | Insight ยิงมาบอกว่ามีข้อมูลเปลี่ยน แล้ว PR Compare ดึงเฉพาะแถวนั้นมาอัปเดตทันที
    | ต้องตั้งค่าให้ตรงกันทั้งสองฝั่ง (ฝั่ง Insight คือ PR_COMPARE_SECRET)
    | เว้นว่าง = ปิดรับ webhook
    |
    */

    'insight_webhook_secret' => env('INSIGHT_WEBHOOK_SECRET', ''),

    /*
    | URL ของ public storage ใน Insight สำหรับรูปโปรไฟล์
    | ใช้ HTTP แทน UNC เพราะ Apache/PHP อาจไม่มีสิทธิ์อ่าน network share
    */
    'insight_asset_url' => env(
        'INSIGHT_ASSET_URL',
        rtrim((string) preg_replace(
            '#/index\.php$#i',
            '',
            env('INSIGHT_URL', 'http://192.168.7.12:8080/Insight/public/index.php')
        ), '/').'/storage'
    ),

];
