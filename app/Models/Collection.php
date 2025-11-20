<?php
// app/Models/Collection.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Collection extends Model
{
    protected $fillable = ['user_id','name','description','purpose','status'];

    public function user() { return $this->belongsTo(User::class); }

    public function items(): HasMany
    {
        // keep items ordered by position (then by id for ties)
        return $this->hasMany(CollectionItem::class)->orderByRaw('COALESCE(position,0) ASC')->orderBy('id');
    }

    public function papers()
    {
        return $this->belongsToMany(Paper::class, 'collection_items')->withTimestamps();
    }

    /** Scope: restrict to an owner */
    public function scopeOwnedBy($q, int $userId)
    {
        return $q->where('user_id', $userId);
    }
}
