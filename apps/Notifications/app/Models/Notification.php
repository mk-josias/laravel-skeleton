<?php

declare(strict_types=1);

namespace Apps\Notifications\Models;

use Apps\Notifications\Enums\NotificationType;
use Apps\Notifications\Observers\NotificationObserver;
use Foundation\Iam\Shadows\UserShadow;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What one person was told. It is addressed to its recipient, so scoping on the recipient is the authorisation.
 *
 * @property int $id
 * @property int $recipient_user_id
 * @property NotificationType $type
 * @property array<string, mixed> $payload
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 * @property-read UserShadow|null $recipient
 */
#[ObservedBy(NotificationObserver::class)]
final class Notification extends Model
{
    protected $table = 'notifications_inbox';

    protected $fillable = ['recipient_user_id', 'type', 'payload'];

    /** @param Builder<Notification> $query */
    public function scopeAddressedTo(Builder $query, int $userId): void
    {
        $query->where('recipient_user_id', $userId);
    }

    /** @param Builder<Notification> $query */
    public function scopeUnread(Builder $query, mixed $unread = true): void
    {
        filter_var($unread, FILTER_VALIDATE_BOOL) ? $query->whereNull('read_at') : $query->whereNotNull('read_at');
    }

    /** @return BelongsTo<UserShadow, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(UserShadow::class, 'recipient_user_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recipient_user_id' => 'integer',
            'type' => NotificationType::class,
            'payload' => 'array',
            'read_at' => 'datetime',
        ];
    }
}
