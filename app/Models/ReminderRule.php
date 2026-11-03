<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * «X days after a customer had service Y, remind them»: the definition only. Ava turns it into one Reminder per customer and appointment (App\Reminders\ReminderRuleScheduler).
 * Written only by App\Reminders\ReminderRuleService. The Workspace is never taken from a request.
 */
#[Fillable(['name', 'delay_amount', 'delay_unit', 'send_time', 'channel', 'subject', 'body', 'template_name', 'template_language', 'template_params', 'created_by_user_id'])]
class ReminderRule extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'activated_at' => 'datetime', 'template_params' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'reminder_rule_services', 'reminder_rule_id', 'service_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReminderRuleEvent::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }
}
