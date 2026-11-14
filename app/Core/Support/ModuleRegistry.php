<?php

namespace App\Core\Support;

use App\Modules\PersonalApp\Models\User as PersonalAppUser;

class ModuleRegistry
{
    /**
     * Daftar modul aktif beserta konfigurasi koneksi database & model autentikasinya.
     *
     * @return array<string, array{name: string, slug: string, connection: string, user_model: class-string}>
     */
    public static function modules(): array
    {
        return [
            'personal-app' => [
                'name' => 'PersonalApp',
                'slug' => 'personal-app',
                'connection' => 'personal_app',
                'user_model' => PersonalAppUser::class,
            ],
        ];
    }

    /**
     * Cari konfigurasi modul berdasarkan slug ('personal-app') atau connection ('personal_app').
     *
     * @return array{name: string, slug: string, connection: string, user_model: class-string}|null
     */
    public static function resolve(string $key): ?array
    {
        $modules = self::modules();

        if (isset($modules[$key])) {
            return $modules[$key];
        }

        foreach ($modules as $module) {
            if ($module['connection'] === $key) {
                return $module;
            }
        }

        return null;
    }
}
