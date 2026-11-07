<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingSignal extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['meeting_id', 'from_attendee_id', 'to_attendee_id', 'type', 'payload'];
}
