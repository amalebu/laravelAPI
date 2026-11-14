<?php

namespace App\Core\Models;

use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class ModulePersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'personal_access_tokens';

    /**
     * Selalu gunakan koneksi database aktif yang telah diatur oleh SetModuleContext.
     */
    public function getConnectionName(): ?string
    {
        return DB::getDefaultConnection();
    }
}
