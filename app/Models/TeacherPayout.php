<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherPayout extends Model
{
    protected $fillable = ['teacher_id', 'amount', 'comment'];
}