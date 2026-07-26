<?php

namespace App\Domains\Administration\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Desk extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'entity', 'region'];

    protected static function newFactory()
    {
        return \Database\Factories\DeskFactory::new();
    }
}
