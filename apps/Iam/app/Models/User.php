<?php

declare(strict_types=1);

namespace Apps\Iam\Models;

use Foundation\Common\Auth\IsPrincipal;
use Foundation\Common\Auth\Principal;
use Foundation\Common\Database\Searchable;
use Illuminate\Database\Eloquent\Model;
use Microservices\Contracts\Shadows\Shadowed;
use Microservices\Traits\ShadowSource;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string|null $api_token
 */
final class User extends Model implements Principal, Shadowed
{
    use HasRoles;
    use IsPrincipal;
    use Searchable;
    use ShadowSource;

    protected $table = 'iam_users';

    protected string $guard_name = 'web';

    protected $fillable = ['name', 'email', 'password', 'api_token'];

    protected $hidden = ['password', 'api_token'];

    /** @var list<string> the fields the other modules' copies carry */
    protected array $shadowed = ['name', 'email'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    /** @return list<string> */
    protected function searchable(): array
    {
        return ['name', 'email'];
    }
}
