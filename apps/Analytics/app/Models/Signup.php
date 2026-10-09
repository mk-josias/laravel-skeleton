<?php

declare(strict_types=1);

namespace Apps\Analytics\Models;

use Foundation\Iam\Shadows\UserShadow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property Carbon $created_at
 * @property-read UserShadow|null $user
 */
final class Signup extends Model
{
    protected $table = 'analytics_signups';

    protected $fillable = ['user_id'];

    /** @return BelongsTo<UserShadow, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserShadow::class, 'user_id');
    }
}
