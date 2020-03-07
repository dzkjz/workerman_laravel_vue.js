<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    //
    protected $fillable = ['user_id', 'room_id', 'content'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
