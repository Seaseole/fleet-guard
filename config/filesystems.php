<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        /*
         * Every document lives here and nowhere else.
         *
         * `serve => false` is the whole point: the `local` driver serves `local` and `public`
         * through the framework's own route, and a documents disk that is reachable by that route
         * is a private disk in name only. A file on this disk has exactly one way out — the signed
         * download controller — which is the one place the per-download audit can be true.
         *
         * On Cloud this becomes the private S3-compatible bucket with the same key shape. The bucket
         * is selected by `FLEET_DOCUMENTS_BUCKET` rather than by editing this file, so the switch is
         * an environment change and the disk keeps its `serve => false` on both branches. No path in
         * the domain code may assume a local root (`DocumentStore` is the only caller).
         */
        'documents_private' => env('FLEET_DOCUMENTS_BUCKET') === null
            ? [
                'driver' => 'local',
                'root' => storage_path('app/documents'),
                'serve' => false,
                'throw' => true,
                'report' => false,
            ]
            : [
                'driver' => 's3',
                'key' => env('AWS_ACCESS_KEY_ID'),
                'secret' => env('AWS_SECRET_ACCESS_KEY'),
                'region' => env('AWS_DEFAULT_REGION'),
                'bucket' => env('FLEET_DOCUMENTS_BUCKET'),
                'endpoint' => env('AWS_ENDPOINT'),
                'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
                'visibility' => 'private',
                'serve' => false,
                'throw' => true,
                'report' => false,
            ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * The off-site copy of the ledger-critical export.
         *
         * Development holds it beside the app; production points `FLEET_BACKUP_DISK` at an
         * S3-compatible disk whose bucket, provider, region and credentials are all different from
         * the documents bucket. That separation is the control: a copy in the same account as the
         * database is a second copy of a mistake, not a backup. `serve => false` because nothing
         * may download an export through the app — the verify command reads it through this disk.
         */
        'offsite' => [
            'driver' => 'local',
            'root' => storage_path('app/offsite'),
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

        /*
         * The production half of the same control. Deliberately its own env names rather than the
         * `AWS_*` set above: a backup that reuses the documents bucket's credentials is one leaked
         * key away from losing both copies, which is the failure the "different provider" rule
         * exists to prevent. Switching is `FLEET_BACKUP_DISK=offsite_s3` — no code change, which is
         * the point of keeping the disk name in config rather than in the exporter.
         */
        'offsite_s3' => [
            'driver' => 's3',
            'key' => env('FLEET_BACKUP_S3_KEY'),
            'secret' => env('FLEET_BACKUP_S3_SECRET'),
            'region' => env('FLEET_BACKUP_S3_REGION'),
            'bucket' => env('FLEET_BACKUP_S3_BUCKET'),
            'endpoint' => env('FLEET_BACKUP_S3_ENDPOINT'),
            'use_path_style_endpoint' => env('FLEET_BACKUP_S3_PATH_STYLE', false),
            'visibility' => 'private',
            'serve' => false,
            'throw' => true,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
