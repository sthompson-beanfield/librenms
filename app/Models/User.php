<?php

namespace App\Models;

use App\Events\UserCreated;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use LibreNMS\Authentication\LegacyAuth;
use NotificationChannels\WebPush\HasPushSubscriptions;
use Permissions;
use Spatie\Permission\Traits\HasRoles;

/**
 * @method static \Database\Factories\UserFactory factory(...$parameters)
 */
class User extends Authenticatable
{
    use HasFactory;
    use HasPushSubscriptions;
    use HasRoles;
    use Notifiable;

    protected $primaryKey = 'user_id';
    protected $fillable = ['realname', 'username', 'email', 'descr', 'can_modify_passwd', 'auth_type', 'auth_id', 'enabled'];
    protected $hidden = ['password', 'remember_token', 'pivot'];
    protected $attributes = [ // default values
        'descr' => '',
        'realname' => '',
        'email' => '',
    ];
    protected $dispatchesEvents = [
        'created' => UserCreated::class,
    ];

    /**
     * @return array{realname: 'string', descr: 'string', email: 'string', can_modify_passwd: 'integer'}
     */
    protected function casts(): array
    {
        return [
            'realname' => 'string',
            'descr' => 'string',
            'email' => 'string',
            'can_modify_passwd' => 'integer',
        ];
    }

    public function toFlare(): array
    {
        return $this->only(['auth_type', 'enabled']);
    }

    // ---- Helper Functions ----

    /**
     * Test if this user has global read access
     */
    public function hasGlobalRead(): bool
    {
        return $this->can('global-read');
    }

    /**
     * Test if this user has global admin access
     */
    public function hasGlobalAdmin(): bool
    {
        return $this->can('global-admin');
    }

    /**
     * Test if the User is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->can('admin');
    }

    /**
     * Test if this user is the demo user
     */
    public function isDemo(): bool
    {
        return $this->hasRole('demo');
    }

    /**
     * Check if this user has access to a device
     *
     * @param  Device|int  $device  can be a device Model or device id
     * @return bool
     */
    public function canAccessDevice($device): bool
    {
        return $this->hasGlobalRead() || Permissions::canAccessDevice($device, $this->user_id);
    }

    /**
     * Helper function to hash passwords before setting
     *
     * @param  string  $password
     */
    public function setPassword($password)
    {
        $this->attributes['password'] = $password ? Hash::make($password) : null;
    }

    /**
     * Check if the given user can set the password for this user
     *
     * @param  User  $user
     * @return bool
     */
    public function canSetPassword($user)
    {
        if ($user && LegacyAuth::get()->canUpdatePasswords()) {
            if ($user->isAdmin()) {
                return true;
            }

            return $user->is($this) && $this->can_modify_passwd;
        }

        return false;
    }

    public function getNotifications(?string $type = null): int|Collection
    {
        return match ($type) {
            'total' => $this->notifications()->count(),
            'read' => $this->notifications()->wherePivot('key', $type)->wherePivot('value', 1)->get(),
            'unread' => Notification::whereNotIn('notifications_id', fn ($q) => $q->select('notifications_id')->from('notifications_attribs')->where('user_id', $this->user_id)->where('key', 'read')->where('value', 1))->get(),
            'sticky' => Notification::whereIn('notifications_id', fn ($q) => $q->select('notifications_id')->from('notifications_attribs')->where('key', 'sticky')->where('value', 1))->get(),
            'sticky_count' => Notification::whereIn('notifications_id', fn ($q) => $q->select('notifications_id')->from('notifications_attribs')->where('key', 'sticky')->where('value', 1)->select('notifications_id'))->count(),
            default => $this->notifications,
        };
    }

    /**
     * Checks if this user has a browser push notification transport configured.
     *
     * @return bool
     */
    public function hasBrowserPushTransport(): bool
    {
        $user_id = \Auth::id();

        return AlertTransport::query()
            ->where('transport_type', 'browserpush')
            ->where('transport_config', 'regexp', "\"user\":\"(0|$user_id)\"")
            ->exists();
    }

    // ---- Query scopes ----

    /**
     * This restricts the query to only users that match the current auth method
     * It is not needed when using user_id, but should be used for username and auth_id
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeThisAuth($query)
    {
        // find user including ones where we might not know the auth type
        $type = LegacyAuth::getType();

        return $query->where(function ($query) use ($type) {
            $query->where('auth_type', $type)
                ->orWhereNull('auth_type')
                ->orWhere('auth_type', '');
        });
    }

    public function scopeAdminOnly($query)
    {
        $query->role('admin');
    }

    // ---- Accessors/Mutators ----

    public function setRealnameAttribute($realname)
    {
        $this->attributes['realname'] = (string) $realname;
    }

    public function setDescrAttribute($descr)
    {
        $this->attributes['descr'] = (string) $descr;
    }

    public function setEmailAttribute($email)
    {
        $this->attributes['email'] = (string) $email;
    }

    public function setCanModifyPasswdAttribute($modify)
    {
        $this->attributes['can_modify_passwd'] = $modify ? 1 : 0;
    }

    public function setEnabledAttribute($enable)
    {
        $this->attributes['enabled'] = $enable ? 1 : 0;
    }

    public function getDevicesAttribute()
    {
        // pseudo relation
        if (! array_key_exists('devices', $this->relations)) {
            $this->setRelation('devices', $this->devices()->get());
        }

        return $this->getRelation('devices');
    }

    // ---- Define Relationships ----
    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\ApiToken, $this>
     */
    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class, 'user_id', 'user_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Bill, $this>
     */
    public function bills(): BelongsToMany
    {
        return $this->belongsToMany(Bill::class, 'bill_perms', 'user_id', 'bill_id');
    }

    public function devices()
    {
        // pseudo relation
        return Device::query()->when(! $this->hasGlobalRead(), function ($query) {
            return $query->whereIntegerInRaw('device_id', Permissions::devicesForUser($this));
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Device, $this>
     */
    public function devicesOwned(): BelongsToMany
    {
        return $this->belongsToMany(Device::class, 'devices_perms', 'user_id', 'device_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\DeviceGroup, $this>
     */
    public function deviceGroups(): BelongsToMany
    {
        return $this->belongsToMany(DeviceGroup::class, 'devices_group_perms', 'user_id', 'device_group_id');
    }

    public function ports()
    {
        if ($this->hasGlobalRead()) {
            return Port::query();
        } else {
            //FIXME we should return all ports for a device if the user has been given access to the whole device.
            return $this->portsOwned();
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Port, $this>
     */
    public function portsOwned(): BelongsToMany
    {
        return $this->belongsToMany(Port::class, 'ports_perms', 'user_id', 'port_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Dashboard, $this>
     */
    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class, 'user_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\Notification, $this>
     */
    public function notifications(): BelongsToMany
    {
        return $this->belongsToMany(Notification::class, 'notifications_attribs', 'user_id', 'notifications_id', 'user_id', 'notifications_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\NotificationAttrib, $this>
     */
    public function notificationAttribs(): HasMany
    {
        return $this->hasMany(NotificationAttrib::class, 'user_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\UserPref, $this>
     */
    public function preferences(): HasMany
    {
        return $this->hasMany(UserPref::class, 'user_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\UserWidget, $this>
     */
    public function widgets(): HasMany
    {
        return $this->hasMany(UserWidget::class, 'user_id');
    }
}
