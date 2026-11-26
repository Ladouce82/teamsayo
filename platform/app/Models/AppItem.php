<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppItem extends Model
{
    use SoftDeletes;

    protected $fillable = ['workspace_id', 'collection', 'item_key', 'company_id', 'subject_key', 'data', 'version', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['data' => 'array', 'version' => 'integer'];
    }
}
